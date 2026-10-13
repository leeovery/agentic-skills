---
name: go-concurrency
description: Go goroutines with an owner and an exit, contexts passed and never stored, locks kept off I/O. Use when starting goroutines or a background worker, using channels, WaitGroup or a mutex, passing or cancelling a context, adding a timeout, or shutting down cleanly.
---

# Go Concurrency

Concurrency is the caller's choice, and every goroutine has an owner that stops it and waits for it.

## Core rules

1. **Write synchronous functions and let the caller add concurrency.** A blocking `Run(ctx)` is easy to test and to compose; a function that quietly starts a goroutine leaves a lifetime nobody owns.
2. **Before `go`, answer four questions:** how it exits, what tells it to stop, who waits for it, and who closes its channels. No answers, no goroutine. A type that starts goroutines waits for them in a blocking `Run(ctx)`, or has a `Close` that stops them and waits.
3. **Wait with `wg.Go(f)`** ([Go 1.25](https://go.dev/doc/go1.25)), never `Add`, `go` and `Done` by hand. `golang.org/x/sync/errgroup` comes in only when tasks return errors that should cancel each other, stated as a new dependency. Gate: modernize's `waitgroupgo`.
4. **`ctx` is the first parameter, passed down and never kept.** No context in a struct, except a long-lived worker's lifetime, noted where it's stored. Never nil. No `context.Background()` below `main` or `run`: use `r.Context()`, `cmd.Context()` or `t.Context()`, as a fresh one drops the caller's deadline, cancellation and values. Gates: containedctx, contextcheck, fatcontext.
5. **Every `WithCancel` and `WithTimeout` defers its `cancel`.** Work that must finish after its caller has gone runs on `context.WithoutCancel(ctx)` with a timeout of its own. Signals become cancellation once, in `run`, through `signal.NotifyContext`. Gate: vet's `lostcancel`.
6. **Every blocking `select` has a `case <-ctx.Done()`, and every call that leaves the process has a deadline.** A wait nothing can cancel is how shutdown hangs. Gate: noctx, for calls made without a context.
7. **Channels are unbuffered or hold one;** any other size says why in a comment. The one sender closes, and signatures give the direction (`<-chan T`, `chan<- T`). A big buffer hides a slow consumer until it fills.
8. **A mutex is a named field beside the data it guards,** never embedded, and never held across I/O, a channel operation or a callback: copy under the lock, act after it. Held across I/O, every caller waits on the disk; across a send or a callback, it can deadlock.
9. **Use typed atomics** (`atomic.Int64`, `atomic.Bool`, `atomic.Pointer[T]`), and `sync.OnceValue` for a value computed on first use, or `OnceValues` when computing it can fail. They can't be read non-atomically by mistake, and the error isn't dropped. Gate: modernize's `atomictypes`.
10. **Shutdown drains in reverse order of start, within a bounded deadline:** stop taking work, then stop the workers, then flush what they write to. An unbounded drain turns one stuck call into a process that won't exit.

## Pattern

```go
// Settler settles the payments waiting to be settled.
type Settler interface {
	SettlePending(ctx context.Context) error
}

// Poller settles pending payments every Interval until its context ends.
type Poller struct {
	Payments Settler
	Interval time.Duration
	Timeout  time.Duration
	Log      *slog.Logger
}

// Run blocks until ctx ends. The caller decides whether it runs in a goroutine.
func (p *Poller) Run(ctx context.Context) {
	tick := time.NewTicker(p.Interval)
	defer tick.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-tick.C:
			p.settle(ctx)
		}
	}
}

func (p *Poller) settle(ctx context.Context) {
	ctx, cancel := context.WithTimeout(ctx, p.Timeout)
	defer cancel()
	if err := p.Payments.SettlePending(ctx); err != nil {
		p.Log.WarnContext(ctx, "settle pending payments", "err", err)
	}
}

// runAlongside owns the poller: it starts it, then stops it and waits once serve returns.
func runAlongside(ctx context.Context, p *Poller, serve func(context.Context) error) error {
	ctx, cancel := context.WithCancel(ctx)
	var wg sync.WaitGroup
	defer wg.Wait() // runs second: waits for Run to return
	defer cancel()  // runs first: tells Run to stop
	wg.Go(func() { p.Run(ctx) })

	return serve(ctx)
}
```

## Decision table

| Need | Reach for |
|---|---|
| Independent tasks, wait for all | `wg.Go`, each writing its own index of a results slice |
| Tasks where one failure makes the rest pointless | `errgroup.WithContext` |
| A cap on how many run at once | errgroup's `SetLimit`, or a channel of that size as a semaphore |
| A few fields several goroutines read and write | `sync.Mutex` |
| A counter or a flag | `atomic.Int64`, `atomic.Bool` |
| A read-mostly value replaced whole | `atomic.Pointer[T]` |
| A value computed once, on first use | `sync.OnceValue`, `sync.OnceValues` |
| Handing work, or ownership of a value, to another goroutine | a channel |
| Something to run when a context ends | `context.AfterFunc` |
| Work that must outlive the request | `context.WithoutCancel` plus its own timeout |

## Anti-patterns

- ❌ `go sendReceipt(o)` with nothing waiting → give it an owner: `wg.Go` and `Wait`, or a worker's `Run(ctx)`.
- ❌ `for { work(); time.Sleep(interval) }` → a `Ticker` and a `select` on `ctx.Done()`; a sleep can't be cancelled.
- ❌ `context.Background()` in a handler, worker or library → the `ctx` you were given, or `context.WithoutCancel(ctx)` when the work must outlive it.
- ❌ A receiver closing a channel, or each of several senders closing it → the sender closes; with several, the owner closes after `wg.Wait()`.
- ❌ An HTTP call or a file write between `Lock` and `Unlock` → copy what you need, unlock, then do the I/O.
- ❌ `sync.Once` beside a variable, with the error dropped → `sync.OnceValues`.
- ❌ `srv.Shutdown(ctx)` with the signal's context, already done → `context.WithTimeout(context.WithoutCancel(ctx), grace)`.
- ❌ `x := x` in a loop → delete it: loop variables are per iteration since [Go 1.22](https://go.dev/doc/go1.22). Gate: modernize's `forvar`.
- ❌ Shunning `time.After` as a leak → since [Go 1.23](https://go.dev/doc/go1.23) an unstopped timer is collected; it's fine outside a hot loop, and inside one a reused `Ticker` or `Timer` saves the allocation.

## References

- **[goroutines.md](references/goroutines.md)** — read before starting a goroutine or a worker: the four questions, a worker and its owner, fan-out, errgroup, a type with `Close`, and shutdown order.
- **[context.md](references/context.md)** — read when deciding where a context comes from, or whether work outlives its request: sources, deadlines, `WithoutCancel`, `AfterFunc`, signals and values.
- **[sync.md](references/sync.md)** — read when goroutines share state: mutex or channel, keeping locks off I/O, atomics and `OnceValues`.

## Related skills

- **[go-testing](../go-testing/SKILL.md)** — testing goroutines, timers and timeouts with `synctest`
- **[go-errors](../go-errors/SKILL.md)** — what a worker does with an error: log it and carry on, or return it
- **[go-architecture](../go-architecture/SKILL.md)** — `main` and `run`, where a program's lifetime and its signals begin
- **[go-actions](../go-actions/SKILL.md)** — the handlers, commands and workers that call operations
