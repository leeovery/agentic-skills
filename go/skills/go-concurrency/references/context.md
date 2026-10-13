# Context

**A context comes from the caller and goes to every call that can block. It's never invented partway down, and never kept.**

Contents: where it comes from · deadlines · work that outlives its caller · running something when a context ends · signals · the one place a context is kept · values · loops.

## Where a context comes from

| Where the code runs | Its context |
|---|---|
| `main` or `run` | `context.Background()`, wrapped once by `signal.NotifyContext` |
| An HTTP handler | `r.Context()` |
| A cobra command | `cmd.Context()` |
| A test | `t.Context()` ([Go 1.24](https://go.dev/doc/go1.24)) |
| A worker's loop | the one its `Run` was given |
| Anywhere else | its `ctx` parameter |

`context.TODO()` marks a call not yet plumbed through, and is a to-do, not a choice. A nil context panics the first time something calls `Done` on it.

A fresh `Background()` deep in the code looks harmless and isn't: it drops the caller's deadline, so the call can outlive the request; its cancellation, so shutdown waits on it; and its values, so its logs lose the request id. contextcheck flags a function that has a context and makes a new one.

## Deadlines on calls that leave the process

Every HTTP request, query, dial or subprocess runs under a deadline: the caller's, or one set where the call is made. A client-wide `http.Client.Timeout` is a backstop for code that forgets, not a substitute.

```go
// Client is the provider's API over HTTP.
type Client struct {
	HTTP    *http.Client
	BaseURL string
	Timeout time.Duration // for each call, reading the answer included
}

// Invoice fetches an invoice by its number, giving up after the client's Timeout.
func (c *Client) Invoice(ctx context.Context, number string) (Invoice, error) {
	ctx, cancel := context.WithTimeout(ctx, c.Timeout)
	defer cancel()
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, c.BaseURL+"/invoices/"+url.PathEscape(number), nil)
	if err != nil {
		return Invoice{}, fmt.Errorf("get invoice %s: %w", number, err)
	}
	resp, err := c.HTTP.Do(req)
	if err != nil {
		return Invoice{}, fmt.Errorf("get invoice %s: %w", number, err)
	}
	defer func() { _ = resp.Body.Close() }()
	if resp.StatusCode != http.StatusOK {
		return Invoice{}, fmt.Errorf("get invoice %s: %s", number, resp.Status)
	}
	var inv Invoice
	if err := json.UnmarshalRead(resp.Body, &inv); err != nil {
		return Invoice{}, fmt.Errorf("decode invoice %s: %w", number, err)
	}
	return inv, nil
}
```

The deadline covers reading the body too. `json` is `encoding/json/v2` ([Go 1.27](https://go.dev/doc/go1.27)), whose `UnmarshalRead` reads the body to its end. Decode before returning: a function that returns `resp` and lets the caller read it has already cancelled the context the body depends on.

noctx flags `http.Get`, `http.NewRequest`, `net.Dial`, `exec.Command` and the other calls that take no context. Use `NewRequestWithContext`, a `net.Dialer`'s `DialContext`, and `exec.CommandContext`.

## Work that outlives its caller

Some work must finish even when the caller has gone: an audit record, a rollback, a cleanup after cancellation. Run it on `context.WithoutCancel(ctx)`, which keeps the caller's values and drops its cancellation and deadline, then give it a deadline of its own.

```go
// Charge records every attempt, even one cut short because the caller went
// away: the audit trail mustn't depend on the customer's connection.
func (c *Charger) Charge(ctx context.Context, id PaymentID) error {
	err := c.charge(ctx, id)

	auditCtx, cancel := context.WithTimeout(context.WithoutCancel(ctx), 2*time.Second)
	defer cancel()
	if auditErr := c.audit.Record(auditCtx, Attempt{Payment: id, Err: err}); auditErr != nil {
		c.log.ErrorContext(ctx, "record charge attempt", "payment", id, "err", auditErr)
	}
	return err
}
```

Work that should carry on after a request ends belongs to a worker that owns it, handed over through a queue, not to a goroutine the handler starts: see [goroutines.md](goroutines.md).

## Running something when a context ends

`context.AfterFunc(ctx, f)` runs `f` in its own goroutine once `ctx` is done, and returns a `stop` that cancels the arrangement. It replaces a goroutine parked on `<-ctx.Done()`. The common use is ending a blocking call that takes no context:

```go
// readFrame fills buf from conn, giving up when ctx ends.
func readFrame(ctx context.Context, conn net.Conn, buf []byte) error {
	stop := context.AfterFunc(ctx, func() { _ = conn.Close() }) // closing conn ends a blocked Read
	defer stop()
	if _, err := io.ReadFull(conn, buf); err != nil {
		if ctx.Err() != nil {
			return context.Cause(ctx)
		}
		return fmt.Errorf("read frame: %w", err)
	}
	return nil
}
```

## Signals

Signals become cancellation once, and everything below sees an ordinary context. A command's `run` turns an interrupt into cancellation ([go-architecture](../../go-architecture/SKILL.md)). A server also stops on SIGTERM, the signal a service manager sends, so its `run` hands the context to this in place of its own `NotifyContext`:

```go
// serveUntilSignal serves until an interrupt or SIGTERM, then shuts down.
func serveUntilSignal(ctx context.Context, srv *http.Server, p *Poller) error {
	ctx, stop := signal.NotifyContext(ctx, os.Interrupt, syscall.SIGTERM)
	defer stop()
	context.AfterFunc(ctx, stop) // after the first signal, a second one exits at once

	return serve(ctx, srv, p, 10*time.Second)
}
```

- Calling `stop` gives the signals back their default, so a second Ctrl-C during a slow shutdown kills the process instead of being ignored.
- Since [Go 1.26](https://go.dev/doc/go1.26), `context.Cause(ctx)` on the context `NotifyContext` returns names the signal that ended it. Log it as the reason for stopping.
- `serve` is the shutdown in [goroutines.md](goroutines.md).

## The one place a context is kept

A context lives in a struct only when nothing can pass it in: the lifetime of a long-lived worker set up once, or a method set fixed by an interface you must satisfy, such as `io.Reader`. Note it where it's stored, since containedctx will flag it:

```go
type tailer struct {
	ctx context.Context //nolint:containedctx // the tailer's whole lifetime; Read can't take a context
	src io.Reader
}
```

A type that starts its own goroutine needs no context at all: it keeps the cancel func and the `Done` channel, as `Receipts` does in [goroutines.md](goroutines.md).

## Values

Context values carry what's scoped to one request and crosses API boundaries: a request id, a trace span. Never dependencies (a logger, a store, a config) and never optional parameters: those are fields or arguments, where the compiler can see them. The key is an unexported type, so no other package can collide with it.

```go
type requestIDKey struct{}

// WithRequestID returns ctx carrying the request's id.
func WithRequestID(ctx context.Context, id string) context.Context {
	return context.WithValue(ctx, requestIDKey{}, id)
}

// RequestID returns the id WithRequestID put in ctx, if there is one.
func RequestID(ctx context.Context) (string, bool) {
	id, ok := ctx.Value(requestIDKey{}).(string)
	return id, ok
}
```

## Loops

`ctx = context.WithValue(ctx, …)` or `ctx, cancel = context.WithTimeout(ctx, …)` inside a loop nests one more context each time round, and every lookup walks the whole chain. Derive into a new variable inside the loop body. fatcontext flags it.
