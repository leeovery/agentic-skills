# Stores

**A store is named for its data and speaks the domain's language, and each consumer asks it for only the methods it calls.**

Contents: naming · each consumer's slice · transactions · no generic base · implementations and their tests.

## Naming

| ❌ | ✅ |
|---|---|
| `OrderRepository.Find(ctx, map[string]any{"status": "pending"})` | `Orders.PendingFor(ctx, customer)` |
| `Get(ctx, id)` returning a row struct | `Order(ctx, id)` returning an `order.Order` |
| `Update(ctx, o)` for every change | `MarkCancelled(ctx, id)`, or `Save(ctx, o)` for a whole value |

- **Methods return domain types,** never rows, wire structs or a driver's types.
- **Not found is your sentinel,** translated inside the store ([go-errors' wrapping.md](../../go-errors/references/wrapping.md)).
- **A query's name says which records, in what order,** so no caller filters what the store hands back.

## Each consumer's slice

The real store has every method. Each consumer declares the few it calls, beside the code that calls them:

```go
// package order

// Store is the orders' store, as Cancel uses it.
type Store interface {
	Order(ctx context.Context, id ID) (Order, error)
	Save(ctx context.Context, o Order) error
	ReleaseStock(ctx context.Context, id ID) error
	InTx(ctx context.Context, fn func(tx Store) error) error
}
```

```go
// package statement: pendingOrders is the one store method a statement needs.
type pendingOrders interface {
	PendingFor(ctx context.Context, c order.CustomerID) ([]order.Order, error)
}

// Drafter drafts statements.
type Drafter struct {
	Orders pendingOrders
}
```

The concrete store fills both without knowing either exists. A statement's test fakes one method, not the dozen the store has.

## Transactions

Writes that must land together go through one store call that takes a closure. The closure gets a `Store` bound to the transaction, and returning an error rolls it back:

```go
err = c.Orders.InTx(ctx, func(tx Store) error {
	if err := tx.Save(ctx, o); err != nil {
		return err
	}
	return tx.ReleaseStock(ctx, o.ID)
})
```

Only the store's writes go inside. `Cancel`'s refund comes before the closure, keyed by the payment so a retry refunds once, because a call to another service can't roll back ([go-actions' operations.md](../../go-actions/references/operations.md#writes)).

The operation never holds a `*sql.Tx` or knows how the store keeps its promise. [go-actions](../../go-actions/SKILL.md) covers when a write needs it.

## No generic base

A `Repository[T any]` with `Find`, `Save` and `Delete` for every type moves the queries into the callers, as filters, and gives every store methods nobody calls. Generics belong inside a store, in a helper such as one that scans rows into a slice, not in a base type the domain sees.

## Implementations and their tests

- **The real store is tested against the real thing:** files under `t.TempDir()`, or a database the test opens in a temporary file or process. A fake of the driver only tests your guess at the driver.
- **Callers test against a fake** that returns what the test set, or against a memory store when an operation makes several calls that have to agree.
- **A memory store that several packages' tests share** lives in a `<pkg>test` package, as `net/http/httptest` does for `net/http`:

```go
// internal/order/ordertest: test doubles of order's interfaces that several packages share.

// Nothing in this package wires Memory in as an order.Store, so this line is what proves it fits.
var _ order.Store = (*Memory)(nil)

// Memory is an order store held in memory. It behaves like the real one,
// transactions included, so tests can run whole operations against it.
type Memory struct {
	mu     sync.Mutex
	orders map[order.ID]order.Order
}

// NewMemory is a store holding orders.
func NewMemory(orders ...order.Order) *Memory {
	m := &Memory{orders: make(map[order.ID]order.Order)}
	for _, o := range orders {
		m.orders[o.ID] = o
	}
	return m
}

// Order is the order with id.
func (m *Memory) Order(_ context.Context, id order.ID) (order.Order, error) {
	m.mu.Lock()
	defer m.mu.Unlock()
	o, ok := m.orders[id]
	if !ok {
		return order.Order{}, fmt.Errorf("order %s: %w", id, order.ErrNotFound)
	}
	return o, nil
}

// InTx runs fn against a copy and keeps the copy only if fn succeeds. It holds
// the lock across fn on purpose: that serialises transactions, as a database would.
func (m *Memory) InTx(ctx context.Context, fn func(tx order.Store) error) error {
	m.mu.Lock()
	defer m.mu.Unlock()
	tx := &Memory{orders: maps.Clone(m.orders)}
	if err := fn(tx); err != nil {
		return err
	}
	m.orders = tx.orders
	return nil
}

// Save, ReleaseStock and PendingFor follow the same shape.
```

The `var _` line is the exception rule 8 allows: no code in `ordertest` assigns a `Memory` to an `order.Store`, so without it a method that drifted from the interface would surface only in some other package's test.

A test elsewhere builds its world in one call:

```go
func TestTotalCountsOnlyPendingOrders(t *testing.T) {
	store := ordertest.NewMemory(
		order.Order{ID: "o-1", Customer: "c-1", Status: order.StatusPending, Total: 1250},
		order.Order{ID: "o-2", Customer: "c-1", Status: order.StatusPaid, Total: 900},
		order.Order{ID: "o-3", Customer: "c-2", Status: order.StatusPending, Total: 400},
	)

	got, err := Drafter{Orders: store}.Total(t.Context(), "c-1")

	if err != nil || got != 1250 {
		t.Errorf("Total(c-1) = %d, %v, want 1250, nil", got, err)
	}
}
```
