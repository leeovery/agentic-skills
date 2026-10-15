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
- **A query's name says which records, in what order,** so no caller filters what the store hands back. Where a domain rule picks the records, the store asks the rule's owner: its SQL binds the statuses whose `status.cancellable` holds, from `_cancellable()`, rather than copy the list ([python-architecture](../../python-architecture/SKILL.md)).

## Each consumer's slice

The real store has every method. Each consumer declares the few it calls, beside the code that calls them. The canceller's, a claim and a save:

```python
# src/shop/order/cancel.py
class OrderStore(Protocol):
    """Where the canceller claims orders and saves them."""

    def start_cancelling(self, order_id: OrderId) -> Order:
        """Move the order to CANCELLING, in one write conditioned on its status.

        Raises NotCancellable, or OrderNotFound.
        """
        ...

    def save(self, order: Order) -> None:
        """Store the order whole, replacing the one with its id."""
        ...
```

The sweeper asks the store one question, so it declares that one:

```python
# src/shop/order/sweep.py
class CancellableOrders(Protocol):
    """The one store query the sweeper asks."""

    def cancellable_for(self, customer_id: CustomerId) -> Sequence[Order]:
        """The customer's orders whose status still allows cancelling."""
        ...
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

`SqliteOrders` fills all three without knowing any of them exists, and a fake of `CancellableOrders` needs one method, not every method the store has.

## Rows stay inside

The store maps rows to the domain in private functions, and nothing outside the module sees a row. A query that returns many orders is one statement, each order joined to its lines:

```python
# src/shop/storage/sqlite.py, on SqliteOrders
def cancellable_for(self, customer_id: CustomerId) -> list[Order]:
    """The customer's orders whose status still allows cancelling, by id."""
    return _orders(
        self.db.execute(
            "SELECT orders.id, customer_id, status, currency, payment_id,"
            " cancelled_at, cancel_reason, sku, quantity, unit_price"
            " FROM orders JOIN lines ON order_id = orders.id"
            " WHERE customer_id = ? AND status IN (SELECT value FROM json_each(?))"
            " ORDER BY orders.id, position",
            (customer_id, _cancellable()),
        )
    )
```

The rows it returns, one for each line, become orders here:

```python
# src/shop/storage/sqlite.py
def _cancellable() -> str:
    """The statuses that allow cancelling, as one JSON list to bind."""
    return json.dumps([status for status in Status if status.cancellable])


type OrderRow = tuple[str, str, str, str, str | None, str | None, str | None]
type LineRow = tuple[str, int, int]
# An order's row, then one of its lines.
type JoinedRow = tuple[
    str, str, str, str, str | None, str | None, str | None, str, int, int
]


def _orders(rows: Iterable[JoinedRow]) -> list[Order]:
    """The orders in rows of each order joined to its lines, ordered by order."""
    return [_joined(list(joined)) for _, joined in groupby(rows, key=itemgetter(0))]


def _joined(rows: Sequence[JoinedRow]) -> Order:
    """The order whose row is joined to each of its lines in rows."""
    return _order(rows[0][:7], [row[7:] for row in rows])


def _order(row: OrderRow, lines: Sequence[LineRow]) -> Order:
    """The order its row and line rows hold."""
    order_id, customer_id, status, currency, payment_id, cancelled_at, reason = row
    try:
        return Order(
            id=OrderId(order_id),
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
            cancel_reason=None if reason is None else Reason(reason),
        )
    except ValueError as err:
        err.add_note(f"reading order {order_id} from the database")
        raise
```

- **One statement, never a query for each order.** Selecting the ids, then calling `get` for each, makes N+1 round trips in N transactions, each reading its own state of the file; one statement reads one. `test_sqlite.py` counts the statements a query runs, with `set_trace_callback`.
- **A stored value the domain refuses** raises `ValueError: 'refunded' is not a valid Status`, which names no order. `_order`, which every read goes through, adds the order's id with `add_note` and lets it rise.

With an ORM the same holds: its models live in the store's module, and the store returns domain values mapped from them. An ORM object that leaves carries a session, lazy loads and the schema into every caller ([python-types](../../python-types/SKILL.md)).

## Transactions

Writes that must land together go through one transaction; [python-actions](../../python-actions/SKILL.md) holds when. The store's side is a context manager that yields a writer: what goes through the writer commits together when the block ends, and none of it lands if the block raises.

```python
# src/shop/storage/sqlite.py
def connect(path: Path) -> sqlite3.Connection:
    """Open the database at path, creating its tables if they're missing."""
    db = sqlite3.connect(path, autocommit=True)
    db.executescript(SCHEMA)
    return db


@contextmanager
def _transaction(db: sqlite3.Connection, *, writes: bool) -> Generator[None]:
    """Run the block in one transaction: committed when it ends, rolled back if it raises."""
    db.execute("BEGIN IMMEDIATE" if writes else "BEGIN")
    try:
        yield
        db.execute("COMMIT")
    except BaseException as err:
        # A failed COMMIT leaves the transaction open; one SQLite ended itself doesn't.
        if db.in_transaction:
            try:
                db.execute("ROLLBACK")
            except sqlite3.Error as failure:
                err.add_note(f"rolling back failed too: {failure!r}")
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
        """Open a transaction: committed when the block ends, rolled back if it raises."""
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

`_read` loads one order and its lines inside the caller's transaction.

- **The store's own `save` is one write in a transaction of its own,** so a consumer whose writes stand alone, as the canceller's claim and save do, declares `save` and calls it. Only a consumer that writes several things declares `transaction()`.
- **A transaction doesn't nest.** SQLite refuses a `BEGIN` inside another, so the store's own `save` or `get` called in the block raises `OperationalError` and the block rolls back, rather than commit part way. Every write in the block goes through the writer, which opens nothing; a read the block needs comes before it.
- **The connection is opened with `autocommit=True`** ([3.12](https://docs.python.org/3/whatsnew/3.12.html)): a statement outside a transaction is a transaction of its own, and one that spans statements is an explicit `BEGIN` and `COMMIT`. Under `autocommit=False` one is always open, so a read leaves its lock behind, and another connection's write fails with `database is locked`.
- **A transaction that writes begins `IMMEDIATE`,** taking the write lock at once, so a second writer waits its turn rather than fail as its read turns into a write.
- **A failed rollback never hides what the block raised.** It goes on the block's exception as a note, and the block's own type still reaches the caller.
- **`add` writes only under a free id, in one statement:** `INSERT … ON CONFLICT (id) DO NOTHING`, and the lines go in only if it wrote. It returns the order stored under the id, so a reorder sent twice places one ([python-actions](../../python-actions/SKILL.md)). A `get` before an insert would let two calls both find the id free.
- **A connection serves the thread that opened it.** sqlite3's `check_same_thread`, on by default, makes a connection used from any other thread raise `ProgrammingError`. The CLI's wiring opens one per command, and an edge that serves on threads gives each thread its own. Never `check_same_thread=False` over one shared connection: one thread's statements would land inside another's transaction.
- **The operation sees only the context manager and its writer,** never a connection or a cursor. A call to another service stays outside the transaction, as [python-actions](../../python-actions/SKILL.md) has it: a refund can't roll back.

## No generic base

A `Repository[T]` with `find`, `add` and `delete` for every type moves the queries into the callers, as filters, and gives every store methods nobody calls. A generic belongs inside a store, in a helper that maps rows, not in a base class the domain sees.

## The fake

Callers test against a fake that holds orders in a dict and behaves like the real store where a test depends on it: not found, a claim refused, a query's order, a transaction that keeps nothing when its block raises, and a call to the store itself refused inside one.

```python
# tests/order/fakes.py
@dataclass(slots=True)
class FakeOrders:
    """An OrderStore holding orders in a dict, and every order saved."""

    orders: dict[OrderId, Order] = field(default_factory=dict)
    saved: list[Order] = field(default_factory=list)
    in_transaction: bool = False

    ...

    @contextmanager
    def transaction(self) -> Generator[FakeOrders]:
        """Stage the block's writes on a copy, and keep them only if it ends cleanly."""
        self._begin()
        staged = FakeOrders(orders=dict(self.orders))
        self.in_transaction = True
        try:
            yield staged
        finally:
            self.in_transaction = False
        self.orders = staged.orders
        self.saved.extend(staged.saved)

    def _begin(self) -> None:
        """Refuse a call made inside the transaction, as SQLite refuses its BEGIN."""
        if self.in_transaction:
            raise sqlite3.OperationalError(
                "cannot start a transaction within a transaction"
            )
```

`get` and `start_cancelling` are in [python-testing](../../python-testing/SKILL.md)'s fakes. Each of them, and `save`, calls `_begin()` first.

- **The transaction's writer is a copy of the fake.** A block that raises never reaches the lines after the `try`, so the copy and its writes are dropped; one that ends cleanly hands them back.
- **Inside its transaction, a call to the store itself fails as SQLite's does,** so a restorer that calls `orders.save` where it means `tx.save` fails its test, as it would in production, rather than lose the write. `tests/order/test_fakes.py` makes the calls `test_sqlite.py` makes of the real store.
- **One fake fills every consumer's slice,** in `tests/order/fakes.py`, shared by every test that needs it; [python-testing](../../python-testing/SKILL.md) covers where fakes and builders go.

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
- **So is a lock left behind.** A read that leaves its transaction open passes every test on one connection, and fails the first write from another, so a test reads, then writes from a second connection.
- **A conditional write is tested against the change it guards against:** the order loaded, then shipped from a second connection, then refused by the claim, still shipped.
- **Each query gets a test that puts the records it must skip beside the ones it must find:** a shipped order and another customer's, for `cancellable_for`.
- **A server database** (Postgres, MySQL) runs in a container the test session starts, with a schema per test or a transaction rolled back after each; the same tests run against it. `--disable-socket` refuses their connections too, so their module opts in to the container's host: `pytestmark = pytest.mark.allow_hosts(["127.0.0.1"])`.
