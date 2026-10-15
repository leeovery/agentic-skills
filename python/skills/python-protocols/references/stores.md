# Stores

**A store is named for its data and speaks the domain's language, its rows never leave it, and each consumer declares only the methods it calls.**

Contents: naming · each consumer's slice · rows stay inside · transactions · no generic base · the fake · testing the real store.

## Naming

| ❌ | ✅ |
|---|---|
| `OrderRepository.find(customer_id=c, status="pending")` | `orders.cancellable_for(customer_id)` |
| `get(order_id)` returning a row, a dict or an ORM object | `get(order_id)` returning an `Order` |
| `update(order_id, **fields)` for every change | `save(order)` for a whole value |
| `get(order_id)`, a status check, then `save(order)` | `start_cancelling(order_id)`: one write, conditioned on the status |

- **Methods take and return domain types,** never rows, driver objects or ORM models.
- **Not found is the domain's exception,** raised inside the store: `SqliteOrders.get` raises `OrderNotFound`, so no caller checks for `None` or imports `sqlite3`.
- **A query's name says which records, in what order,** so no caller filters what the store hands back. Where the records are picked by a domain rule, the store asks the rule's owner: `cancellable_for` keeps the orders whose `status.cancellable` holds, rather than copying the list of statuses into SQL, and `start_cancelling` binds the statuses `Status` names into its `UPDATE` ([python-architecture](../../python-architecture/SKILL.md)).

## Each consumer's slice

The real store has every method. Each consumer declares the few it calls, beside the code that calls them. The canceller's, a claim and a save:

```python
# src/shop/order/cancel.py
class OrderStore(Protocol):
    """Where the canceller claims orders and saves them."""

    def start_cancelling(self, order_id: OrderId) -> Order:
        """Move the order to CANCELLING and return it, if its status allows cancelling.

        One write, conditioned on the status the order has as it runs, so a
        shipment that lands first is refused, never overwritten. Raises
        NotCancellable, or OrderNotFound.
        """
        ...

    def save(self, order: Order) -> None:
        """Store the order whole, replacing the one with its id."""
        ...
```

The sweeper, which cancels every order a customer can still have cancelled, asks one question of the store and hands each answer to the canceller it holds. It calls one method of that too, so it declares that one:

```python
# src/shop/order/sweep.py
class CancellableOrders(Protocol):
    """The one store query the sweeper asks."""

    def cancellable_for(self, customer_id: CustomerId) -> Sequence[Order]:
        """The customer's orders whose status still allows cancelling."""
        ...


class Cancels(Protocol):
    """Cancels one order, as the order package's Canceller does."""

    def cancel(self, order_id: OrderId, *, reason: Reason) -> Order:
        """Cancel the order for reason, refunding it if it was paid."""
        ...


@dataclass(frozen=True, slots=True, kw_only=True)
class Sweeper:
    """Cancels each of a customer's cancellable orders through the canceller."""

    orders: CancellableOrders
    canceller: Cancels
```

The restorer saves a batch of orders that must land together, so its slice is a transaction and the one write it makes inside:

```python
# src/shop/order/restore.py
class OrderWrites(Protocol):
    """Writes inside one transaction."""

    def save(self, order: Order) -> None:
        """Store the order whole, replacing the one with its id."""
        ...


class TransactionalOrders(Protocol):
    """A store whose writes land together or not at all."""

    def transaction(self) -> AbstractContextManager[OrderWrites]:
        """Open a transaction: committed when the block ends, rolled back if it raises."""
        ...
```

`SqliteOrders` fills all three without knowing any of them exists, as `Canceller` fills `Cancels`, and a fake of `CancellableOrders` needs one method, not the store's four.

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
                unit_price=Money(minor=price, currency=Currency(currency)),
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

A stored value the domain refuses, a status nobody defined, raises `ValueError: 'refunded' is not a valid Status`, which names no order. `_read`, which loads an order for `get` and for the claim, adds the one fact the traceback lacks and lets it rise:

```python
# src/shop/storage/sqlite.py, in SqliteOrders._read, which get and the claim share
try:
    return _order(order_id, row, lines)
except ValueError as err:
    err.add_note(f"reading order {order_id} from the database")
    raise
```

With an ORM the same holds: its models live in the store's module, and the store returns domain values mapped from them. An ORM object that leaves carries a session, lazy loads and the schema into every caller ([python-types](../../python-types/SKILL.md)).

## Transactions

Writes that must land together go through one transaction; [python-actions](../../python-actions/SKILL.md) holds when. The store's side is a context manager that yields a writer: what goes through the writer commits together when the block ends, and none of it lands if the block raises.

```python
# src/shop/storage/sqlite.py
def connect(path: Path) -> sqlite3.Connection:
    """Open the database at path, creating its tables if they're missing.

    In SQLite's autocommit mode, a statement outside a transaction is one of its
    own, so a read holds no lock once it's done and never blocks another
    connection's write. Statements that belong together go through _transaction.
    """
    db = sqlite3.connect(path, autocommit=True)
    db.executescript(SCHEMA)
    return db


@contextmanager
def _transaction(db: sqlite3.Connection, *, writes: bool) -> Generator[None]:
    """Run the block in one transaction: committed when it ends, rolled back if it raises.

    SQLite refuses a BEGIN inside another, so a transaction can't nest unseen.
    """
    # One that writes takes the write lock as it begins, so a second writer
    # waits its turn rather than failing as it turns a read into a write.
    db.execute("BEGIN IMMEDIATE" if writes else "BEGIN")
    try:
        yield
        db.execute("COMMIT")
    except BaseException:
        # A COMMIT that fails, as when another connection holds the file, leaves
        # the transaction open; one SQLite ended itself has nothing to roll back.
        if db.in_transaction:
            db.execute("ROLLBACK")
        raise


@dataclass(frozen=True, slots=True, kw_only=True)
class SqliteOrders:
    """Orders in SQLite, as the order package's OrderStore. No row leaves it."""

    db: sqlite3.Connection

    def get(self, order_id: OrderId) -> Order:
        """Load the order with its lines, or raise OrderNotFound."""
        # One transaction, so the order and its lines come from one state of the file.
        with _transaction(self.db, writes=False):
            return self._read(order_id)

    ...

    def save(self, order: Order) -> None:
        """Write the order and its lines in one transaction, replacing any under its id."""
        with self.transaction() as tx:
            tx.save(order)

    @contextmanager
    def transaction(self) -> Generator[SqliteTransaction]:
        """Open a transaction: committed when the block ends, rolled back if it raises.

        Every write in the block goes through the writer it yields. This store's
        own save opens a transaction of its own, which SQLite refuses in here.
        """
        with _transaction(self.db, writes=True):
            yield SqliteTransaction(db=self.db)

    ...


@dataclass(frozen=True, slots=True, kw_only=True)
class SqliteTransaction:
    """Writes inside one of SqliteOrders' transactions."""

    db: sqlite3.Connection

    def save(self, order: Order) -> None:
        """Write the order and its lines, replacing any under its id."""
        ...
```

- **The store's own `save` is one write in a transaction of its own,** so a consumer whose writes stand alone, as the canceller's claim and save do, declares `save` and calls it. Only a consumer that writes several things declares `transaction()`.
- **A transaction doesn't nest.** SQLite refuses a `BEGIN` inside another, so the store's own `save` called in the block raises `OperationalError: cannot start a transaction within a transaction` and the block rolls back, rather than commit part way. Every write in the block goes through the writer, which holds the connection and opens nothing; a read the block needs comes before it.
- **The connection is opened with `autocommit=True`** ([3.12](https://docs.python.org/3/whatsnew/3.12.html)): a statement outside a transaction is a transaction of its own, and one that spans statements is an explicit `BEGIN` and `COMMIT`. Under `autocommit=False` one is always open, so a read leaves its lock behind, and another connection's write fails with `database is locked` until the reader next commits.
- **A transaction that writes begins `IMMEDIATE`,** taking the write lock at once. A second writer then waits its turn, up to the connection's five-second busy timeout, where after a plain `BEGIN` it could fail with `SQLITE_BUSY` as its read turned into a write.
- **The operation sees only the context manager and its writer:** `with self.orders.transaction() as tx: tx.save(order)`. It never holds a connection or a cursor, and never learns how the store keeps its promise.
- **A call to another service stays outside the transaction.** A refund can't roll back, so the canceller claims the order, refunds, then saves, each a write of its own, and the refund's key makes a second run refund once ([python-actions](../../python-actions/SKILL.md)).

## No generic base

A `Repository[T]` with `find`, `add` and `delete` for every type moves the queries into the callers, as filters, and gives every store methods nobody calls. A generic belongs inside a store, in a helper that maps rows, not in a base class the domain sees.

## The fake

Callers test against a fake that holds orders in a dict and behaves like the real store where a test depends on it: not found, a claim refused for the status it finds, the order of a query's answer, and a transaction that keeps nothing when its block raises.

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

    def start_cancelling(self, order_id: OrderId) -> Order:
        """Move the order to CANCELLING and return it, refusing it as the real store does."""
        order = self.get(order_id)
        if not order.status.cancellable:
            raise NotCancellable(order.id, order.status)
        claimed = copy.replace(order, status=Status.CANCELLING)
        self.orders[order.id] = claimed
        return claimed

    def cancellable_for(self, customer_id: CustomerId) -> list[Order]:
        """The customer's orders whose status allows cancelling, by id, as the real store answers."""
        return [
            order
            for order in sorted(self.orders.values(), key=lambda order: order.id)
            if order.customer_id == customer_id and order.status.cancellable
        ]

    ...

    def save(self, order: Order) -> None:
        """Hold the order and record that it was saved."""
        self.orders[order.id] = order
        self.saved.append(order)

    @contextmanager
    def transaction(self) -> Generator[FakeOrders]:
        """Stage the block's writes on a copy, and keep them only if it ends cleanly."""
        staged = FakeOrders(orders=dict(self.orders))
        yield staged
        self.orders = staged.orders
        self.saved.extend(staged.saved)
```

- **The transaction's writer is a copy of the fake.** A block that raises never reaches the lines after `yield`, so the copy and its writes are dropped; one that ends cleanly hands them back.
- **Its claim asks `status.cancellable`, as the real store's `UPDATE` does,** so a canceller test of a shipped order meets the same `NotCancellable`, and a claimed order reads back `CANCELLING`.
- **One fake fills `OrderStore`, `CancellableOrders` and `TransactionalOrders`.** It lives in `tests/order/fakes.py`, shared by every test that needs it, beside `FakeCanceller`, the fake of `Cancels` that the sweeper's and the withdrawer's tests pass; [python-testing](../../python-testing/SKILL.md) covers where fakes and builders go.

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


...


def test_a_saved_order_is_seen_by_another_connection(
    orders: SqliteOrders, path: Path
) -> None:
    orders.save(make_order())

    with closing(connect(path)) as other:
        assert SqliteOrders(db=other).get(OrderId("o-1")) == make_order()


def test_a_read_holds_nothing_open_to_block_another_connections_write(
    orders: SqliteOrders, path: Path
) -> None:
    orders.save(make_order())
    orders.get(OrderId("o-1"))
    paid = make_order(status=Status.PAID, payment_id="pay-1")

    with closing(connect(path)) as other:
        SqliteOrders(db=other).save(paid)

    assert orders.get(OrderId("o-1")) == paid


...


def test_a_transaction_that_raises_keeps_none_of_its_writes(
    orders: SqliteOrders,
) -> None:
    def save_then_fail() -> None:
        with orders.transaction() as tx:
            tx.save(make_order(order_id="o-1"))
            tx.save(make_order(order_id="o-2"))
            raise RuntimeError("disk full")

    with pytest.raises(RuntimeError):
        save_then_fail()

    assert orders.cancellable_for(CustomerId("c-1")) == []


def test_a_save_inside_a_transaction_fails_rather_than_commit_part_way(
    orders: SqliteOrders,
) -> None:
    def save_inside() -> None:
        with orders.transaction() as tx:
            tx.save(make_order(order_id="o-1"))
            orders.save(make_order(order_id="o-2"))

    with pytest.raises(sqlite3.OperationalError, match="within a transaction"):
        save_inside()

    assert orders.cancellable_for(CustomerId("c-1")) == []


...


def test_start_cancelling_refuses_an_order_shipped_since_it_was_loaded(
    orders: SqliteOrders, path: Path
) -> None:
    orders.save(make_order(status=Status.PAID, payment_id="pay-1"))
    loaded = orders.get(OrderId("o-1"))
    shipped = copy.replace(loaded, status=Status.SHIPPED)
    with closing(connect(path)) as other:
        SqliteOrders(db=other).save(shipped)

    with pytest.raises(NotCancellable) as refused:
        orders.start_cancelling(loaded.id)

    assert refused.value.status is Status.SHIPPED
    assert orders.get(OrderId("o-1")) == shipped
```

- **A commit is checked from a second connection.** The connection that wrote reads its own uncommitted writes, so a read through it passes whether or not anything was committed.
- **So is a lock left behind.** A read that leaves its transaction open passes every test on one connection, and fails the first write from another. A further test holds a read open on a second connection, so the store's `COMMIT` fails, and checks the next save still works: SQLite leaves a transaction open when its `COMMIT` fails.
- **A conditional write is tested against the change it guards against:** the order loaded, then shipped from a second connection, then refused by the claim, still shipped.
- **Each query gets a test that puts the records it must skip beside the ones it must find:** a shipped order and another customer's, for `cancellable_for`.
- **A server database** (Postgres, MySQL) runs in a container the test session starts, with a schema per test or a transaction rolled back after each; the same tests run against it.
