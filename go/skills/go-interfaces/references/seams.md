# Seams

**A seam is a field where `main` passes the real thing and a test passes its own.**

Contents: the seams · function field or interface · time · the environment · processes · files · HTTP · what a package may default.

## The seams

| What | Seam | `main` passes | A test passes |
|---|---|---|---|
| the instant | `now func() time.Time` | `time.Now` | a fixed time |
| environment variables | the parsed values, or `getenv func(string) string` | `os.Getenv` | a map lookup |
| the home or a state directory | a path | what `os.UserHomeDir` returned, resolved once | `t.TempDir()` |
| files under a directory | `*os.Root` | `os.OpenRoot(dir)` | `os.OpenRoot(t.TempDir())` |
| files only read | `fs.FS` | `os.DirFS(dir)` | an `fstest.MapFS` |
| another program | a runner interface | the runner over `os/exec` | a fake runner |
| an HTTP API | an `*http.Client`, a base URL and a timeout | `&http.Client{}`, the configured URL and a timeout | `srv.Client()`, `srv.URL` and a short timeout |
| logs | `*slog.Logger` | the one logger | nothing, or one over a buffer ([go-errors' logging.md](../../go-errors/references/logging.md)) |

## Function field or interface

- **One operation is a function field.** The test writes a function literal, and no type is declared for it.
- **Operations that go together are an interface,** faked by one struct: a runner that finds a program and runs it.

## Time

Two tools, for two jobs:

- **Anything that waits runs under `testing/synctest`** ([Go 1.25](https://go.dev/doc/go1.25)). Inside `synctest.Test`, `time.Now`, `time.Sleep`, timers and tickers run on a fake clock that jumps ahead whenever every goroutine in the test is blocked, so an hour of one-minute ticks passes at once. The code calls `time.NewTicker` as usual, with no clock injected. `synctest.Sleep` ([Go 1.27](https://go.dev/doc/go1.27)) sleeps and then waits for the bubble to settle. [go-testing](../../go-testing/SKILL.md) covers these tests.
- **A decision about a particular instant takes `now`:** a 14-day return window, the last day of February, a token's expiry. The bubble's clock starts at midnight UTC on 1 January 2000 and only moves by sleeping, so a test that needs one second past a deadline sets the instant directly instead.

A pure function takes the instant as an argument. A struct that reads the time takes `now`:

```go
// Overdue is pure: the caller passes the instant.
func Overdue(inv Invoice, now time.Time) bool {
	return now.After(inv.Due)
}

// Reminder reads the time itself, so it takes a clock.
type Reminder struct {
	Invoices unpaid
	Now      func() time.Time
}

// Due are the invoices left unpaid for 30 days.
func (r Reminder) Due(ctx context.Context) ([]Invoice, error) {
	return r.Invoices.UnpaidBefore(ctx, r.Now().AddDate(0, 0, -30))
}
```

A decision at one instant takes `now` the same way. A return is accepted until 14 days after delivery:

```go
const returnWindow = 14 * 24 * time.Hour

var (
	// ErrNotDelivered is a return asked for before the order arrived.
	ErrNotDelivered = errors.New("not delivered yet")
	// ErrReturnWindowClosed is a return asked for more than 14 days after delivery.
	ErrReturnWindowClosed = errors.New("return window closed")
)

// Returner takes back orders delivered in the last 14 days.
type Returner struct {
	Orders Store
	Now    func() time.Time
}

// Return takes the order back, if it arrived within the window.
func (r Returner) Return(ctx context.Context, id ID) (Order, error) {
	o, err := r.Orders.Order(ctx, id)
	if err != nil {
		return Order{}, fmt.Errorf("return: %w", err)
	}
	now := r.Now()
	switch {
	case o.DeliveredAt.IsZero():
		return Order{}, fmt.Errorf("return: order %s: %w", id, ErrNotDelivered)
	case now.After(o.DeliveredAt.Add(returnWindow)):
		return Order{}, fmt.Errorf("return: order %s: %w", id, ErrReturnWindowClosed)
	}
	o.ReturnedAt = now
	if err := r.Orders.Save(ctx, o); err != nil {
		return Order{}, fmt.Errorf("return: %w", err)
	}
	return o, nil
}
```

A test then sits exactly on the edge it cares about, here with the fakes in [go-testing's fakes-and-builders.md](../../go-testing/references/fakes-and-builders.md):

```go
var delivered = time.Date(2026, 3, 1, 9, 0, 0, 0, time.UTC)

func fixed(t time.Time) func() time.Time { return func() time.Time { return t } }

func TestReturnRefusesASecondAfterTheWindow(t *testing.T) {
	store := newMemoryStore(Order{ID: "ord-1", Status: StatusShipped, DeliveredAt: delivered})
	r := Returner{Orders: store, Now: fixed(delivered.Add(returnWindow + time.Second))}

	_, err := r.Return(t.Context(), "ord-1")

	if !errors.Is(err, ErrReturnWindowClosed) {
		t.Errorf("Return(ord-1) = %v, want ErrReturnWindowClosed", err)
	}
	if got := store.orders["ord-1"].ReturnedAt; !got.IsZero() {
		t.Errorf("after a refused Return(ord-1), stored ReturnedAt %v, want none", got)
	}
}
```

Its twin sets the clock to `delivered.Add(returnWindow)`, the window's last instant, and expects the return to land. Two tests, one second apart, pin the edge exactly.

## The environment

- **Only `main` reads it.** A component that needs one or two values gets them parsed, as typed fields. Config loading, which reads a family of variables, gets `getenv`.
- **A test passes a lookup over a map:** `Getenv: func(k string) string { return env[k] }`. `t.Setenv` changes the variable for the whole process, so a test that uses it can't run in parallel, and anything else reading the environment sees the change.
- **The home directory, the state directory and the config path** are resolved in `main` and passed as paths. A test passes `t.TempDir()`, and the real ones are never touched.

## Processes

Finding a program and running it go together, so they're one interface:

```go
// runner is the process seam: two operations, so an interface.
type runner interface {
	LookPath(name string) (string, error)
	Output(ctx context.Context, path string, args ...string) ([]byte, error)
}

// ExecRunner runs real programs; main passes it.
type ExecRunner struct{}

// LookPath finds the program name on PATH.
func (ExecRunner) LookPath(name string) (string, error) { return exec.LookPath(name) }

// Output runs the program at path and returns what it printed.
func (ExecRunner) Output(ctx context.Context, path string, args ...string) ([]byte, error) {
	return exec.CommandContext(ctx, path, args...).Output()
}
```

The fake answers `LookPath` from a map and records each command it was asked to run:

```go
type fakeRunner struct {
	paths map[string]string
	out   []byte
	ran   [][]string
}

func (f *fakeRunner) LookPath(name string) (string, error) {
	if p, ok := f.paths[name]; ok {
		return p, nil
	}
	return "", os.ErrNotExist
}

func (f *fakeRunner) Output(_ context.Context, path string, args ...string) ([]byte, error) {
	f.ran = append(f.ran, append([]string{path}, args...))
	return f.out, nil
}
```

`exec.CommandContext` kills the process when the context ends, so a cancelled caller doesn't leave it running.

## Files

- **Pass the directory as an `*os.Root`** ([Go 1.24](https://go.dev/doc/go1.24)). Its methods refuse a name that climbs out (`../`), or a link that leads out, so a name from outside can't reach past it. `Root.ReadFile` and `Root.WriteFile` came in [Go 1.25](https://go.dev/doc/go1.25).
- **A tree that's only read is an `fs.FS`:** `os.DirFS(dir)` in `main`, an `fstest.MapFS` in a test.
- **A test roots the seam in a temporary directory,** and reads back what was written:

```go
root, err := os.OpenRoot(t.TempDir())
if err != nil {
	t.Fatal(err)
}
t.Cleanup(func() {
	if err := root.Close(); err != nil {
		t.Error(err)
	}
})
```

## HTTP

- **Pass an `*http.Client`, a base URL and a timeout,** as the adapter's `Client{HTTP, BaseURL, Timeout}`, which applies the timeout to each call. Never `http.DefaultClient`, a global any package can change, and never a host written into the code.
- **A test points both at an `httptest.NewTestServer`** ([Go 1.27](https://go.dev/doc/go1.27)): `srv.Client()`, then `srv.URL`, which the first call sets. It serves over an in-memory network, so inside a `synctest` bubble the client's timeouts run on the bubble's clock. [services.md](services.md) has the whole test.

## What a package may default

- **`now` may fall back to `time.Now` when it's nil.** Reading the clock harms nothing.
- **Nothing that reaches the machine has a default:** the environment, a directory, a runner, an HTTP client. `main` passes each one. A default would let a test that forgot one read the real home or run the real program, and nothing would fail to say so.
