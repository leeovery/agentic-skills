---
name: go-interfaces
description: Small Go interfaces declared by the code that uses them, as seams for outside services, stores, clocks and the environment, chosen in main. Use when integrating an API or SDK, adding persistence, injecting a dependency or clock, faking one in a test, or splitting a large interface.
---

# Go Interfaces

An interface belongs to the code that calls it, and it exists to be swapped: in `main` by config, in a test by a fake.

## Core rules

1. **The consumer declares the interface, beside its use, with only the methods it calls.** Implementations return concrete types. *The consumer depends on what it uses and no more, and the implementation can grow without breaking it.* iface's `unusedmethod` reports a method nobody in the package calls, and its `opaque` a constructor that returns an interface over one type.
2. **No interface until a second implementation or a test's fake needs one.** *With one implementation and no fake, it's indirection: a reader jumps through it to find the only code there is.* iface's `unused` reports one nothing in its package uses.
3. **One to three methods.** Split a larger one by role (read, decide, write), and embed the parts where one caller needs two. *A big interface is hard to fake, so its tests reach for the real thing or a mock generator.* interfacebloat stops one at 5.
4. **An outside service or SDK sits behind your interface.**
   - A thin adapter package is the real implementation, tested against an `httptest` server.
   - Callers test against a fake of your interface.
   - `main` picks the implementation with a `switch` on config.
   - Nobody fakes the vendor's own types.

   *Your interface is the contract you control, in your domain's words. The adapter is the only code that knows the vendor, so a change on their side breaks one package and its tests.*
5. **A store is an interface named for its data, with methods in the domain's words:** `PendingFor(ctx, customer)`, not `Find(ctx, filter)`. No generic CRUD base. *Each query a caller needs has a name that says what it returns, and the store's implementation is free to answer it however it likes.*
6. **Time, the environment, processes, files and the network are seams, set in `main`.**
   - One operation is a function field: `now func() time.Time`, `getenv func(string) string`.
   - Several are an interface, such as a process runner.
   - Files are a directory or an `*os.Root` passed in; HTTP is an `*http.Client` and a base URL.
   - `now` is injected where a decision depends on the instant. Waits, tickers and timeouts are left to `testing/synctest` ([Go 1.25](https://go.dev/doc/go1.25)).

   *Code that calls `time.Now` or `os.Getenv` itself can only be tested at the real time, on the real machine.* forbidigo keeps the environment and home directory reads in `cmd/`, and depguard keeps `os/exec` out of domain packages.
7. **Fakes are written by hand:** a struct that records its calls and returns what the test set, in the test file, or in a `<pkg>test` package when several packages share it. No mock generators. *A fake reads like the domain, and the test checks outcomes instead of pinning the order of calls.* depguard bans gomock, mockery and testify.
8. **`var _ I = (*T)(nil)` goes only where nothing else proves the fit;** the wiring in `main` already does. An interface stays unexported unless another package must name it. *A check beside every type is noise, and an exported interface draws other packages to reuse it instead of declaring the slice they need.*

## Pattern

```go
// package order

// Refunder is exported because main names it when it picks one.
type Refunder interface {
	Refund(ctx context.Context, payment PaymentID, amount Cents) error
}

// Canceller cancels orders that haven't shipped, refunds those paid, and puts
// their stock back.
type Canceller struct {
	Orders   Store
	Payments Refunder
	Now      func() time.Time
}
```

```go
// package main: the one place that knows there's more than one.
func newRefunder(cfg Config) (order.Refunder, error) {
	switch cfg.Payments {
	case "gateway":
		return &gateway.Client{HTTP: &http.Client{}, BaseURL: cfg.GatewayURL, Timeout: 10 * time.Second}, nil
	case "memory":
		return &gateway.Memory{}, nil
	}
	return nil, fmt.Errorf("payments %q: want gateway or memory", cfg.Payments)
}
```

```go
// order_test.go: the fake records what it was asked and returns what it was told.
type fakeRefunder struct {
	refunded []PaymentID
	err      error
}

func (f *fakeRefunder) Refund(_ context.Context, payment PaymentID, _ Cents) error {
	f.refunded = append(f.refunded, payment)
	return f.err
}
```

The same fake, and the tests that use it, are in [go-testing's fakes-and-builders.md](../go-testing/references/fakes-and-builders.md).

## Decision table

| Need | Reach for |
|---|---|
| A collaborator with one implementation and no fake | the concrete type |
| A collaborator a test must replace | an interface in the consumer, one to three methods |
| One operation to replace: the time, a variable | a function field |
| An outside API or SDK | an adapter package returning a concrete client, tested against `httptest` |
| Persistence | a store named for its data, with methods in the domain's words |
| A different implementation per environment | a `switch` on config in `main` |
| Timers, tickers or timeouts in a test | `synctest`, not a fake clock |
| A test at a particular instant: month end, an expiry | an injected `now` |

## Anti-patterns

- ❌ `type OrderService interface` beside its only implementation, "for mocking" → delete it; each consumer declares what it calls.
- ❌ `func NewClient(…) ClientInterface` → return `*Client`.
- ❌ `IOrderStore`, `OrderStoreInterface` → name the role: `Orders`, `Refunder`.
- ❌ One twelve-method `Store` that every consumer takes → each consumer declares the methods it calls.
- ❌ `Repository[T]` with `Find`, `Save` and `Delete` for every type → the queries the domain asks, each by name.
- ❌ An interface over the SDK's client, or a stub `http.RoundTripper`, to fake the vendor → run the adapter against `httptest.NewTestServer`, and fake your own interface above it.
- ❌ `time.Now()`, `os.Getenv`, `os.UserHomeDir` or `exec.Command` in domain code → a seam, set in `main`.
- ❌ A fake clock advanced by hand to fire timers → `synctest.Test` and `time.Sleep`.
- ❌ gomock, mockery or `testify/mock` → a fake of ten lines.

## References

- **[seams.md](references/seams.md)** — read when a test needs to control time, the environment, a process, files or the network: each seam, what `main` passes, what a test passes, and which defaults are safe.
- **[services.md](references/services.md)** — read when integrating an API or SDK, or choosing an implementation by config: the adapter, its `httptest` tests, the fake for callers, a memory implementation, and the switch in `main`.
- **[stores.md](references/stores.md)** — read when adding persistence: naming, each consumer's slice of the store, transactions, a shared memory store, and testing the real one.

## Related skills

- **[go-actions](../go-actions/SKILL.md)** — the operation structs that hold these interfaces as fields.
- **[go-testing](../go-testing/SKILL.md)** — tests built on the fakes, and `synctest` for time.
- **[go-errors](../go-errors/SKILL.md)** — adapters and stores translate a dependency's errors into yours.
- **[go-types](../go-types/SKILL.md)** — adapters map the wire's shapes to domain types.
- **[go-architecture](../go-architecture/SKILL.md)** — `main` wires it all, and only `main` reads the environment.
