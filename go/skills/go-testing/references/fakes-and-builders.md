# Fakes and Builders

**A test builds its input with a builder that names what matters, and hands the code under test hand-written fakes of its own interfaces.**

Contents: builders · fakes · a store as its own fake · HTTP clients · real files · when a test can't get in.

## Builders

A builder returns a valid value with sensible defaults; options change only what the test is about.

```go
func newOrder(opts ...func(*Order)) Order {
	o := Order{ID: "ord-1", Status: StatusPending, Total: 1250, Payment: "pay-1"}
	for _, opt := range opts {
		opt(&o)
	}
	return o
}

func paid(o *Order)    { o.Status = StatusPaid }
func shipped(o *Order) { o.Status = StatusShipped }

func withTotal(c Cents) func(*Order) {
	return func(o *Order) { o.Total = c }
}
```

- `newOrder(paid, withTotal(9900))` reads as the case it builds. A literal with every field hides the one that matters among the ones that don't.
- Name options for the state they produce (`paid`, `shipped`), not the field they set (`withStatus(StatusPaid)`), unless the value itself is the point (`withTotal`).
- Each test builds its own value. A shared fixture variable couples tests through whatever one of them changes, and the reader has to go and find it.
- Builders live in the package's `_test.go` files. When several packages need them, they move to an `ordertest` package beside `order`.

## Fakes

A fake is a small struct that records what it was called with and returns what the test set. It lives in the test file, or in the `ordertest` package when shared. No generated mocks: a hand-written fake is a few lines, it reads like the code, and it fails at compile time when the interface changes.

```go
type fakeRefunder struct {
	refunded []PaymentID
	err      error
}

func (f *fakeRefunder) Refund(_ context.Context, payment PaymentID, _ Cents) error {
	f.refunded = append(f.refunded, payment)
	return f.err
}
```

It fills the `Refunder` that [go-interfaces](../../go-interfaces/SKILL.md) declares, and every test of `Cancel` in these skills uses it.

For a one-off behaviour, a function type that satisfies the interface saves declaring a struct:

```go
type refundFunc func(context.Context, PaymentID, Cents) error

func (f refundFunc) Refund(ctx context.Context, payment PaymentID, amount Cents) error {
	return f(ctx, payment, amount)
}
```

```go
func TestCancelKeepsAPaidOrderWhenTheRefundFails(t *testing.T) {
	o := newOrder(paid)
	store := newMemoryStore(o)
	declined := errors.New("card declined")
	refunds := refundFunc(func(context.Context, PaymentID, Cents) error { return declined })
	c := Canceller{Orders: store, Payments: refunds, Now: time.Now}

	_, err := c.Cancel(t.Context(), o.ID, ReasonCustomer)

	if !errors.Is(err, declined) {
		t.Errorf("Cancel(%q) error = %v, want %v", o.ID, err, declined)
	}
	if got := store.orders[o.ID].Status; got != StatusPaid {
		t.Errorf("after a failed refund, Cancel(%q) left status %v, want %v", o.ID, got, StatusPaid)
	}
}
```

- Check what the fake recorded as you'd check any value: `slices.Equal(refunds.refunded, want)`. Check call order or counts only when they are the behaviour.
- A fake called from several goroutines guards its fields with a mutex, or counts with an atomic, so the race detector stays quiet for the right reason.
- A fake does no logic of its own. Once it needs some, a memory implementation is the better tool.

## A store as its own fake

A store interface gets a memory implementation, which serves as the fake in every test that needs one. This one fills the `Store` that `Cancel` takes ([go-actions](../../go-actions/SKILL.md)):

```go
type memoryStore struct {
	orders   map[ID]Order
	released []ID // the orders whose stock went back
}

func newMemoryStore(orders ...Order) *memoryStore {
	s := &memoryStore{orders: make(map[ID]Order)}
	for _, o := range orders {
		s.orders[o.ID] = o
	}
	return s
}

func (s *memoryStore) Order(_ context.Context, id ID) (Order, error) {
	o, ok := s.orders[id]
	if !ok {
		return Order{}, fmt.Errorf("order %s: %w", id, ErrNotFound)
	}
	return o, nil
}

func (s *memoryStore) Save(_ context.Context, o Order) error {
	s.orders[o.ID] = o
	return nil
}

func (s *memoryStore) ReleaseStock(_ context.Context, id ID) error {
	s.released = append(s.released, id)
	return nil
}

// InTx runs fn against a copy, and keeps the copy only if fn succeeds.
func (s *memoryStore) InTx(_ context.Context, fn func(tx Store) error) error {
	tx := &memoryStore{orders: maps.Clone(s.orders), released: slices.Clone(s.released)}
	if err := fn(tx); err != nil {
		return err
	}
	*s = *tx
	return nil
}
```

- **Its methods are the store's,** in the domain's words, and its not-found is wrapped with the id, as the real store's is ([go-interfaces' stores.md](../../go-interfaces/references/stores.md)).
- **`InTx` writes to a copy,** so a test of a failed write sees nothing land.
- **It has no lock,** as only the test's goroutine uses it. A memory store that several packages or goroutines share locks, as stores.md's `ordertest.Memory` does.

A test then checks the outcome, what was stored, rather than the calls that stored it:

```go
func TestCancelSavesTheOrderAsCancelled(t *testing.T) {
	o := newOrder(paid, withTotal(9900))
	store := newMemoryStore(o)
	at := time.Date(2026, 3, 1, 9, 0, 0, 0, time.UTC)
	c := Canceller{Orders: store, Payments: &fakeRefunder{}, Now: func() time.Time { return at }}

	if _, err := c.Cancel(t.Context(), o.ID, ReasonCustomer); err != nil {
		t.Fatalf("Cancel(%q) error = %v", o.ID, err)
	}

	want := o
	want.Status, want.Reason, want.CancelledAt = StatusCancelled, ReasonCustomer, at
	if got := store.orders[o.ID]; got != want {
		t.Errorf("after Cancel(%q), stored %+v, want %+v", o.ID, got, want)
	}
	if !slices.Equal(store.released, []ID{o.ID}) {
		t.Errorf("after Cancel(%q), released stock for %v, want %v", o.ID, store.released, []ID{o.ID})
	}
}
```

## HTTP clients

The adapter that calls an outside API is the only code that knows the vendor's types. Test it for real, against a server you control; every other test fakes your own interface in front of it. Never fake the vendor's SDK.

```go
func TestInvoiceReadsTheProvidersAnswer(t *testing.T) {
	srv := httptest.NewTestServer(t, http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/invoices/INV-7" {
			t.Errorf("request path = %q, want /invoices/INV-7", r.URL.Path)
		}
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{"number":"INV-7","total":4200}`))
	}))
	client := srv.Client()
	c := &Client{HTTP: client, BaseURL: srv.URL, Timeout: 5 * time.Second}

	got, err := c.Invoice(t.Context(), "INV-7")

	if err != nil {
		t.Fatalf("Invoice(%q) error = %v", "INV-7", err)
	}
	if want := (Invoice{Number: "INV-7", Total: 4200}); got != want {
		t.Errorf("Invoice(%q) = %+v, want %+v", "INV-7", got, want)
	}
}
```

- `httptest.NewTestServer` ([Go 1.27](https://go.dev/doc/go1.27)) serves over an in-memory network, shuts itself down when the test ends, and fails the test if the handler panics. Its client sends every request to it, whatever the host.
- `srv.URL` is set by the first call to `srv.Client()`, so call `Client` first.
- The handler runs on the server's goroutine: it may call `t.Errorf`, never `t.Fatal`.
- Serve the provider's real responses, recorded once into `testdata/`, error bodies included.
- On a Go before 1.27, `httptest.NewServer` with a deferred `srv.Close()` does the same over loopback.

## Real files

A store that writes files runs for real in `t.TempDir()`, which is removed when the test ends. That's cheaper than faking the filesystem, and it tests the encoding as well. Pass the directory in; never let the code find its own.

## When a test can't get in

If the only way to make a test pass is to swap a package variable, patch a function, or reach the real network, the code is missing a seam. Add the interface or the function field the test needs, as [go-interfaces](../../go-interfaces/SKILL.md) describes, rather than reaching around the design.
