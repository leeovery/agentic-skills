# Goroutines and Workers

**Every goroutine has an owner: the code that starts it is the code that stops it and waits for it.**

Contents: the four questions · a worker and its owner · fan-out · tasks that fail together · a type that starts its own goroutine · shutdown order · checklist.

## The four questions

Answer each before writing `go` or `wg.Go`. If one has no answer, the goroutine isn't ready to exist.

| Question | A good answer |
|---|---|
| How does it exit? | Its loop returns on `ctx.Done()`, on a closed input channel, or when its one job is done. |
| What tells it to stop? | A context its owner cancels. Not a `bool` it polls, and not "the process ends". |
| Who waits for it? | A `WaitGroup` or errgroup's `Wait`, a `Close` that blocks, or a channel its owner reads. |
| Who closes its channels? | The one sender. With several senders, the owner, once they've all returned. |

And first: does it need to be a goroutine at all? A function that does its work and returns is easier to test, and its caller can still run it concurrently.

A channel needs closing only when a receiver ranges over it or waits for the end. A worker told to stop by its context can leave its input channel open.

## A worker and its owner

The worker blocks in `Run(ctx)` until its context ends, as the [SKILL.md](../SKILL.md) pattern's `Poller` does. It starts no goroutine of its own, so nothing it does outlives the call, and a test can run it inside a `synctest` bubble.

The owner decides that it runs alongside something else, and pairs the start with its stop and its wait in the same function, as the pattern's `runAlongside` does. Defers run last first, so `cancel` is deferred after `wg.Wait`. The other order waits forever for a worker nobody has told to stop.

A `Run` that can fail on its own, rather than only stop, returns an `error`. A `Run` that starts goroutines inside it waits for them before it returns, so its caller's wait covers them.

## Fan-out: independent tasks

Each goroutine writes only its own index, so the results slice needs no lock, and the order matches the input.

```go
// StandingOf checks every customer at once and returns their standings in the
// order given.
func (c *Checker) StandingOf(ctx context.Context, ids []CustomerID) []Standing {
	standings := make([]Standing, len(ids))
	var wg sync.WaitGroup
	for i, id := range ids {
		wg.Go(func() { standings[i] = c.check(ctx, id) })
	}
	wg.Wait()
	return standings
}
```

`wg.Go` ([Go 1.25](https://go.dev/doc/go1.25)) adds to the group before the goroutine starts, so there's no `Add` to misplace and no `Done` to forget. The function it runs must not panic. Each task here reports its own failure inside its `Standing`; none stops the others.

## Tasks that fail together

When one failure makes the rest pointless, use `golang.org/x/sync/errgroup`. `WithContext` cancels the shared context on the first error, and `Wait` returns that error. `SetLimit` caps how many run at once, and `Go` blocks while the group is full.

```go
// SendAll sends every invoice, four at a time, and stops at the first failure.
func (s *Sender) SendAll(ctx context.Context, invoices []Invoice) error {
	g, ctx := errgroup.WithContext(ctx)
	g.SetLimit(4)
	for _, inv := range invoices {
		g.Go(func() error { return s.send(ctx, inv) })
	}
	return g.Wait()
}
```

- `send` must honour `ctx`, or the tasks queued behind the failure still run in full.
- The context `WithContext` returns is cancelled once `Wait` returns: don't use it afterwards.
- errgroup is a dependency outside the standard library. Bring it in for this case, and say so in the change.
- Without errgroup, a buffered channel used as a semaphore caps a fan-out: the cap is the stated reason for its size.

## A type that starts its own goroutine

Prefer `Run(ctx)`. When a type must start its goroutine itself, because it's handed to code that can't run it, it keeps the cancel func, never the context, and `Close` stops the goroutine and waits for it.

```go
// Receipts sends receipts from a goroutine of its own. Close stops it and
// waits for the receipt in flight.
type Receipts struct {
	queue   chan Receipt
	stopped <-chan struct{}
	stop    context.CancelFunc
	wg      sync.WaitGroup
}

// StartReceipts sends each receipt queued with send, until ctx ends or Close
// is called.
func StartReceipts(ctx context.Context, send func(context.Context, Receipt) error, log *slog.Logger) *Receipts {
	ctx, stop := context.WithCancel(ctx)
	r := &Receipts{queue: make(chan Receipt), stopped: ctx.Done(), stop: stop}
	r.wg.Go(func() {
		for {
			select {
			case <-ctx.Done():
				return
			case rc := <-r.queue:
				if err := send(ctx, rc); err != nil {
					log.WarnContext(ctx, "send receipt", "order", rc.Order, "err", err)
				}
			}
		}
	})
	return r
}

// Queue hands rc to the sender, waiting until it's taken.
func (r *Receipts) Queue(ctx context.Context, rc Receipt) error {
	select {
	case r.queue <- rc:
		return nil
	case <-r.stopped:
		return ErrClosed
	case <-ctx.Done():
		return ctx.Err()
	}
}

// Close stops the sender and waits for the receipt in flight.
func (r *Receipts) Close() {
	r.stop()
	r.wg.Wait()
}
```

The queue is unbuffered, so `Queue` returns only once the sender has the receipt, and a slow sender slows its callers rather than piling up work that `Close` would drop. Nobody closes `queue`: the context says when to stop, and `stopped` keeps `Queue` from blocking on a sender that has gone.

## Shutdown order

Stop things in the reverse of the order they started. What takes work in stops first, then what does it, then what it writes to, so nothing is handed work after its consumer has gone. Each step has a deadline; together they bound how long the process takes to exit.

```go
// serve runs the poller and the server until ctx ends or the server fails,
// then stops them in reverse order of start, the server within grace.
func serve(ctx context.Context, srv *http.Server, p *Poller, grace time.Duration) error {
	workCtx, stopWork := context.WithCancel(ctx)
	defer stopWork()
	var wg sync.WaitGroup
	wg.Go(func() { p.Run(workCtx) })

	failed := make(chan error, 1) // one send at most; lets the goroutine return if nobody reads it
	wg.Go(func() {
		if err := srv.ListenAndServe(); !errors.Is(err, http.ErrServerClosed) {
			failed <- err
		}
	})

	var err error
	select {
	case <-ctx.Done():
	case err = <-failed:
	}

	shutdownCtx, cancel := context.WithTimeout(context.WithoutCancel(ctx), grace)
	defer cancel()
	if shutErr := srv.Shutdown(shutdownCtx); shutErr != nil {
		err = errors.Join(err, fmt.Errorf("shut down server: %w", shutErr))
	}
	stopWork()
	wg.Wait()
	return err
}
```

- `Shutdown` gets a context of its own. The one passed in is already done when a signal ended it, and would abort the drain at once.
- `context.WithoutCancel` keeps the parent's values for the shutdown's logs, without its cancellation.
- The poller stops after the server, so a request still draining can count on it.
- The poller's own in-flight work is bounded by its per-call timeout, so `wg.Wait` is bounded too.
- A component that buffers writes (a ledger, a batch sender) flushes last, on its own deadline, after everything that writes to it has stopped.

## Checklist

- [ ] Each `go` or `wg.Go` has the four answers, and the owner is visible in the same function or type.
- [ ] Nothing waits on a `select` without a `case <-ctx.Done()`.
- [ ] Each channel has one closer, or none and a context.
- [ ] Every buffer bigger than one has a comment giving its size a reason.
- [ ] Shutdown has a deadline, and runs in reverse order of start.
- [ ] The tests run with `-race`, and the worker has a test in a `synctest` bubble: see [go-testing](../../go-testing/SKILL.md).
