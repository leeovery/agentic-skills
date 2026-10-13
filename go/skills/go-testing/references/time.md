# Time and Goroutines

**Code that waits is tested inside a `synctest` bubble, where the clock is fake and moves only when every goroutine in the bubble is blocked.**

Contents: the bubble · a worker on a ticker · a timeout · stopping · HTTP in a bubble · what blocks durably · rules inside a bubble · when to inject `now` instead.

## The bubble

`testing/synctest` is generally available since [Go 1.25](https://go.dev/doc/go1.25). It replaces real sleeps and injected fake clocks for anything that waits.

- `synctest.Test(t, func(t *testing.T) { … })` runs the function in a bubble with its own clock, starting at midnight UTC on 1 January 2000. Goroutines started inside belong to the bubble.
- Time moves only when every goroutine in the bubble is durably blocked, and then jumps straight to the next timer. A test of an hour-long interval runs in microseconds, and always sees the same order of events.
- `synctest.Wait()` returns once every other goroutine in the bubble is durably blocked: the moment the code under test has done all it can, and the moment to check.
- `synctest.Sleep(d)` ([Go 1.27](https://go.dev/doc/go1.27)) is `time.Sleep(d)` then `Wait()`. Use it to move time on and let the code settle: a bare `time.Sleep` returns at the same fake instant as the code's own timer, and either may run first.
- `t.Context()` inside the bubble is cancelled when the function returns, and `t.Cleanup` functions run inside the bubble.

`synctest.Run` was the experimental API in [Go 1.24](https://go.dev/doc/go1.24) and isn't in the package any more.

## A worker on a ticker

The `Poller` from [go-concurrency](../../go-concurrency/SKILL.md) settles payments once a minute until its context ends.

```go
type countingSettler struct {
	calls atomic.Int32
}

func (s *countingSettler) SettlePending(context.Context) error {
	s.calls.Add(1)
	return nil
}

func newPoller(payments Settler) *Poller {
	return &Poller{Payments: payments, Interval: time.Minute, Timeout: 10 * time.Second, Log: slog.New(slog.DiscardHandler)}
}

func TestPollerSettlesOncePerInterval(t *testing.T) {
	synctest.Test(t, func(t *testing.T) {
		payments := &countingSettler{}
		p := newPoller(payments)
		ctx, cancel := context.WithCancel(t.Context())
		var wg sync.WaitGroup
		wg.Go(func() { p.Run(ctx) })

		synctest.Sleep(3 * time.Minute)

		if got := payments.calls.Load(); got != 3 {
			t.Errorf("settled %d times in 3m, want 3", got)
		}
		cancel()
		wg.Wait()
	})
}
```

Exactly 3, not "at least 2": the fake clock leaves no room for slack, so the test can state the behaviour precisely.

## A timeout

A fake that blocks until its context ends shows when the code under test gives up, to the nanosecond.

```go
type hangingSettler struct {
	gaveUp chan error
}

func (s *hangingSettler) SettlePending(ctx context.Context) error {
	<-ctx.Done()
	s.gaveUp <- ctx.Err()
	return ctx.Err()
}

func TestPollerGivesUpOnASlowSettlement(t *testing.T) {
	synctest.Test(t, func(t *testing.T) {
		payments := &hangingSettler{gaveUp: make(chan error)}
		p := newPoller(payments)
		ctx, cancel := context.WithCancel(t.Context())
		var wg sync.WaitGroup
		wg.Go(func() { p.Run(ctx) })
		start := time.Now()

		err := <-payments.gaveUp

		if !errors.Is(err, context.DeadlineExceeded) {
			t.Errorf("settlement ended with %v, want context.DeadlineExceeded", err)
		}
		if got, want := time.Since(start), time.Minute+10*time.Second; got != want {
			t.Errorf("settlement gave up after %v, want %v", got, want)
		}
		cancel()
		wg.Wait()
	})
}
```

The test blocks on `gaveUp`, the poller on its ticker and then its fake, so time jumps to the first tick and on to the timeout.

## Stopping

```go
func TestPollerStopsWhenItsContextEnds(t *testing.T) {
	synctest.Test(t, func(t *testing.T) {
		p := newPoller(&countingSettler{})
		ctx, cancel := context.WithCancel(t.Context())
		stopped := make(chan struct{})
		go func() {
			p.Run(ctx)
			close(stopped)
		}()

		cancel()
		synctest.Wait()

		select {
		case <-stopped:
		default:
			t.Error("Run is still running after its context ended")
		}
	})
}
```

## HTTP in a bubble

A loopback server does real network I/O, which isn't durable blocking, so the bubble's clock stalls while a request is in flight. `httptest.NewTestServer` ([Go 1.27](https://go.dev/doc/go1.27)) serves over an in-memory network built for bubbles:

```go
func TestInvoiceGivesUpAfterItsTimeout(t *testing.T) {
	synctest.Test(t, func(t *testing.T) {
		srv := httptest.NewTestServer(t, http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			<-r.Context().Done()
		}))
		client := srv.Client()
		c := &Client{HTTP: client, BaseURL: srv.URL, Timeout: 5 * time.Second}
		start := time.Now()

		_, err := c.Invoice(t.Context(), "INV-7")

		if !errors.Is(err, context.DeadlineExceeded) {
			t.Errorf("Invoice(%q) error = %v, want context.DeadlineExceeded", "INV-7", err)
		}
		if got := time.Since(start); got != 5*time.Second {
			t.Errorf("Invoice(%q) gave up after %v, want 5s", "INV-7", got)
		}
	})
}
```

## What blocks durably

| Durably blocked: time can move | Not durable: time waits for it |
|---|---|
| A send, receive or `select` on channels made in the bubble | A `sync.Mutex` or `RWMutex` |
| `time.Sleep`, or waiting on a timer or ticker made in the bubble | Real network or file I/O, and system calls |
| `sync.WaitGroup.Wait`, once `Add` or `Go` ran in the bubble | Channels made outside the bubble |
| `sync.Cond.Wait` | |

A goroutine stuck on the right-hand side keeps the clock still until it returns. Code under test that does real I/O in a bubble is the usual cause of a synctest test that hangs: give it a fake, a temp file read up front, or `NewTestServer`.

## Rules inside a bubble

- No `t.Run`, `t.Parallel` or `t.Deadline` inside the function. A table of timing cases runs `synctest.Test` inside each row's `t.Run`, not the other way round.
- Channels, timers and tickers made in a bubble can't be used from outside it.
- A `WaitGroup` declared as a package-level variable can't join a bubble.
- A goroutine still blocked when the function returns fails the run with "deadlock: main bubble goroutine has exited but blocked goroutines remain". That is the leak check: a worker whose context comes from `t.Context()` stops by itself at the end, while one on a context that is never cancelled, or blocked on a channel nobody will send to, fails it.
- The failure is a panic, so it ends the whole test binary. Run the one test with `-run` to see the stacks clearly.

## When to inject `now` instead

The bubble's clock starts on 1 January 2000. A decision that turns on the calendar (an invoice overdue at the end of a month, a renewal on 29 February, the hour a clock change skips) needs a chosen instant: inject `now func() time.Time`, as [go-interfaces](../../go-interfaces/SKILL.md) describes, and pass the instant the case is about. Waits, timers, tickers and timeouts stay with synctest.

Inside a bubble, build instants from `time.Now()` (`time.Now().Add(-48 * time.Hour)`) rather than from fixed dates, so the case means the same whatever the bubble's epoch.
