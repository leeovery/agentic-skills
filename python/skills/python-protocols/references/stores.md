# Stores

**A store is named for its data and speaks the domain's language, its rows never leave it, and each consumer declares only the methods it calls.**

Contents: naming · each consumer's slice · rows stay inside · transactions · no generic base · the fake · testing the real store.

## Naming

| ❌ | ✅ |
|---|---|
| `OrderRepository.find(customer_id=c, status="pending")` | `orders.cancellable_for(customer_id)` |
| `get(order_id)` returning a row, a dict or an ORM object | `get(order_id)` returning an `Order` |
| `update(order_id, **fields)` for every change | `save(order)` for a whole value |

- **Methods take and return domain types,** never rows, driver objects or ORM models.
- **Not found is the domain's exception,** raised inside the store: `SqliteOrders.get` raises `OrderNotFound`, so no caller checks for `None` or imports `sqlite3`.
- **A query's name says which records, in what order,** so no caller filters what the store hands back. Where the records are picked by a domain rule, the store asks the rule's owner: `cancellable_for` keeps the orders whose `status.cancellable` holds, rather than copying the list of statuses into SQL ([python-architecture](../../python-architecture/SKILL.md)).

## Each consumer's slice

The real store has every method. Each consumer declares the few it calls, beside the code that calls them. The canceller's:

```python
# src/shop/order/cancel.py
class OrderStore(Protocol):
    """Where the canceller loads orders and saves them."""

    def get(self, order_id: OrderId) -> Order:
        """Return the order with the id, or raise OrderNotFound."""
        ...

    def save(self, order: Order) -> None:
        """Store the order whole, replacing the one with its id, once the transaction commits."""
        ...

    def transaction(self) -> AbstractContextManager[None]:
        """Keep every write made inside, or none of them if the block raises."""
        ...
```

The sweeper, which cancels every order a customer can still have cancelled, asks one question of the store and hands each answer to the canceller it holds:

```python
# src/shop/order/sweep.py
class CancellableOrders(Protocol):
    """The one store query the sweeper asks."""

    def cancellable_for(self, customer_id: CustomerId) -> Sequence[Order]:
        """The customer's orders whose status still allows cancelling."""
        ...


@dataclass(frozen=True, slots=True, kw_only=True)
class Sweeper:
    """Cancels each of a customer's cancellable orders through the canceller."""

    orders: CancellableOrders
    canceller: Canceller
```

`SqliteOrders` fills both without knowing either exists, and a fake of `CancellableOrders` needs one method, not the store's four.

## Rows stay inside

The store maps a row to the domain in one private function, and nothing outside the module sees a row:

```python
# src/shop/storage/sqlite.py
type OrderRow = tuple[str, str, str, str | None, str | None, str | None]
type LineRow = tuple[str, int, int]


def _order(order_id: OrderId, row: OrderRow, lines: Sequence[LineRow]) -> Order:
    """The order its row and line rows hold. A value the domain refuses raises ValueError."""
    customer_id, status, currency, payment_id, cancelled_at, cancel_reason = row
    return Order(
        id=order_id,
        customer_id=CustomerId(customer_id),
        status=Status(status),
        lines=tuple(
            Line(
                sku=sku,
                quantity=quantity,
                unit_price=Money(minor=price, currency=currency),
            )
            for sku, quantity, price in lines
        ),
        payment_id=None if payment_id is None else PaymentId(payment_id),
        cancelled_at=None
        if cancelled_at is None
        else datetime.fromisoformat(cancelled_at),
        cancel_reason=None if cancel_reason is None else Reason(cancel_reason),
    )
```

A stored value the domain refuses, a status nobody defined, raises `ValueError: 'refunded' is not a valid Status`, which names no order. `get` adds the one fact the traceback lacks and lets it rise:

```python
# src/shop/storage/sqlite.py, in SqliteOrders.get
try:
    return _order(order_id, row, lines)
except ValueError as err:
    err.add_note(f"reading order {order_id} from the database")
    raise
```

With an ORM the same holds: its models live in the store's module, and the store returns domain values mapped from them. An ORM object that leaves carries a session, lazy loads and the schema into every caller ([python-types](../../python-types/SKILL.md)).

## Transactions

Writes that must land together go through one transaction; [python-actions](../../python-actions/SKILL.md) holds when. The store's side is a context manager: the writes made inside commit together when the block ends, and none of them land if it raises.

```python
# src/shop/storage/sqlite.py
@dataclass(frozen=True, slots=True, kw_only=True)
class SqliteOrders:
    """Orders in SQLite, as the order package's OrderStore. No row leaves it."""

    db: sqlite3.Connection

    @contextmanager
    def transaction(self) -> Generator[None]:
        """Commit the writes made inside together, or roll them all back if the block raises."""
        with self.db:
            yield
```

- **A write method never commits on its own;** only `transaction()` does. sqlite3's `with db:` doesn't nest: an inner block commits on its way out, and the outer block's later failure can't take that back. A `save` with its own `with self.db:` would break every transaction it was called in.
- **The connection is opened with `autocommit=False`** ([3.12](https://docs.python.org/3/whatsnew/3.12.html)), so a transaction is always open and nothing lands until a commit.
- **The operation sees only the context manager:** `with self.orders.transaction(): self.orders.save(cancelled)`. It never holds a connection or a cursor, and never learns how the store keeps its promise.
- **A call to another service stays outside the block.** A refund can't roll back, so the canceller refunds before it opens the transaction, keyed so a retry refunds once.

## No generic base

A `Repository[T]` with `find`, `add` and `delete` for every type moves the queries into the callers, as filters, and gives every store methods nobody calls. A generic belongs inside a store, in a helper that maps rows, not in a base class the domain sees.

## The fake

Callers test against a fake that holds orders in a dict and behaves like the real store where a test depends on it: not found, the order of a query's answer, and a transaction that keeps nothing when its block raises.

```python
# tests/order/fakes.py
@dataclass(slots=True)
class FakeOrders:
    """An OrderStore holding orders in a dict, and every order saved."""

    orders: dict[OrderId, Order] = field(default_factory=dict)
    saved: list[Order] = field(default_factory=list)

    @classmethod
    def holding(cls, *orders: Order) -> FakeOrders:
        """A store that holds orders."""
        return cls(orders={order.id: order for order in orders})

    def get(self, order_id: OrderId) -> Order:
        """Return the order, or raise OrderNotFound as the real store does."""
        try:
            return self.orders[order_id]
        except KeyError:
            raise OrderNotFound(order_id) from None

    def cancellable_for(self, customer_id: CustomerId) -> list[Order]:
        """The customer's orders whose status allows cancelling, by id, as the real store answers."""
        return [
            order
            for order in sorted(self.orders.values(), key=lambda order: order.id)
            if order.customer_id == customer_id and order.status.cancellable
        ]

    def save(self, order: Order) -> None:
        """Hold the order and record that it was saved."""
        self.orders[order.id] = order
        self.saved.append(order)

    @contextmanager
    def transaction(self) -> Generator[None]:
        """Keep the writes made inside, or none of them if the block raises."""
        orders, saved = dict(self.orders), list(self.saved)
        try:
            yield
        except BaseException:
            self.orders, self.saved = orders, saved
            raise
```

One fake fills both `OrderStore` and `CancellableOrders`, so a test of the sweeper passes the same object to it and to the canceller it holds, and they agree on what's stored. It lives in `tests/order/fakes.py`, shared by every test that needs it; [python-testing](../../python-testing/SKILL.md) covers where fakes and builders go.

## Testing the real store

Against the real thing: SQLite in a file under `tmp_path`, a fresh database per test. A fake of the driver tests only your guess at the driver.

```python
# tests/storage/test_sqlite.py
@pytest.fixture
def path(tmp_path: Path) -> Path:
    return tmp_path / "shop.db"


@pytest.fixture
def orders(path: Path) -> Iterator[SqliteOrders]:
    with closing(connect(path)) as db:
        yield SqliteOrders(db=db)


def save(orders: SqliteOrders, *saving: Order) -> None:
    """Save each order in one transaction."""
    with orders.transaction():
        for order in saving:
            orders.save(order)


def test_a_committed_transaction_is_seen_by_another_connection(
    orders: SqliteOrders, path: Path
) -> None:
    save(orders, make_order())

    with closing(connect(path)) as other:
        assert SqliteOrders(db=other).get(OrderId("o-1")) == make_order()


def test_a_transaction_that_raises_keeps_none_of_its_writes(
    orders: SqliteOrders,
) -> None:
    def save_then_fail() -> None:
        with orders.transaction():
            orders.save(make_order(order_id="o-1"))
            orders.save(make_order(order_id="o-2"))
            raise RuntimeError("disk full")

    with pytest.raises(RuntimeError):
        save_then_fail()

    assert orders.cancellable_for(CustomerId("c-1")) == []
```

- **A commit is checked from a second connection.** The connection that wrote reads its own uncommitted writes, so a read through it passes whether or not anything was committed.
- **Each query gets a test that puts the records it must skip beside the ones it must find:** a shipped order and another customer's, for `cancellable_for`.
- **A server database** (Postgres, MySQL) runs in a container the test session starts, with a schema per test or a transaction rolled back after each; the same tests run against it.
