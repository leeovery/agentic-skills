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
- **A query's name says which records, in what order,** so no caller filters what the store hands back. Where the records are picked by a domain rule, the store asks the rule's owner: `cancellable_for`'s query and `start_cancelling`'s `UPDATE` bind the statuses whose `status.cancellable` holds, one JSON list from `_cancellable()`, rather than copy the list into SQL ([python-architecture](../../python-architecture/SKILL.md)).

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

`SqliteOrders` fills all three without knowing any of them exists, as `Canceller` fills `Cancels`, and a fake of `CancellableOrders` needs one method, not every method the store has. Nothing in the wiring passes the store as each slice yet, so `test_sqlite.py` holds it as all five, `OrdersByProduct` and the reorderer's `Orders` among them, and pyrefly checks that it fits each.

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
    """The statuses that allow cancelling, as one JSON list to bind.

    They come from Status, the rule's owner, so no query copies the list.
    """
    return json.dumps([status for status in Status if status.cancellable])


type OrderRow = tuple[str, str, str, str, str | None, str | None, str | None]
type LineRow = tuple[str, int, int]
# An order's row, then one of its lines.
type JoinedRow = tuple[
    str, str, str, str, str | None, str | None, str | None, str, int, int
]


def _orders(rows: Iterable[JoinedRow]) -> list[Order]:
    """The orders in rows of each order joined to its lines, ordered by order.

    The rows come from one statement, which SQLite runs on one state of the file.
    """
    return [_joined(list(joined)) for _, joined in groupby(rows, key=itemgetter(0))]


def _joined(rows: Sequence[JoinedRow]) -> Order:
    """The order whose row is joined to each of its lines in rows."""
    return _order(rows[0][:7], [row[7:] for row in rows])


def _order(row: OrderRow, lines: Sequence[LineRow]) -> Order:
    """The order its row and line rows hold.

    A value the domain refuses raises ValueError, noted with the order's id,
    which the error itself doesn't name.
    """
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

- **One statement, never a query for each order.** Selecting the ids, then calling `get` for each, makes N+1 round trips in N transactions, each reading its own state of the file; one statement reads one. `containing`, the withdrawer's, is the same statement over the orders that hold a line for the product. `test_sqlite.py` counts the statements the two queries run, with `set_trace_callback`: one each.
- **`_orders` groups the joined rows by order,** which the `ORDER BY` keeps together, and `_order` maps one order's row and its lines' rows. An order with no lines, which `save` never writes, falls out of the join; `get` reads it, and the domain refuses it.
- **A stored value the domain refuses,** a status nobody defined, raises `ValueError: 'refunded' is not a valid Status`, which names no order. `_order`, which every read goes through, adds the one fact the traceback lacks with `add_note`, and lets it rise.

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
    What the block raised goes on as it was, with a failed rollback as a note.
    """
    # One that writes takes the write lock as it begins, so a second writer
    # waits its turn rather than failing as it turns a read into a write.
    db.execute("BEGIN IMMEDIATE" if writes else "BEGIN")
    try:
        yield
        db.execute("COMMIT")
    except BaseException as err:
        # A COMMIT that fails, as when another connection holds the file, leaves
        # the transaction open; one SQLite ended itself has nothing to roll back.
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

    def add(self, order: Order) -> Order:
        """Write the order unless one has its id, and return the one stored under it.

        The write is the check: an INSERT that does nothing where the id is taken,
        so a second add of the id, as from a request sent twice, keeps the first.
        """
        with _transaction(self.db, writes=True):
            added = self.db.execute(
                "INSERT INTO orders VALUES (?, ?, ?, ?, ?, ?, ?)"
                " ON CONFLICT (id) DO NOTHING",
                _row(order),
            ).rowcount
            if added:
                self.db.executemany(
                    "INSERT INTO lines VALUES (?, ?, ?, ?, ?)", _line_rows(order)
                )
            return self._read(order.id)

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
- **A transaction doesn't nest.** SQLite refuses a `BEGIN` inside another, so the store's own `save`, `get` or `start_cancelling` called in the block raises `OperationalError: cannot start a transaction within a transaction` and the block rolls back, rather than commit part way. Every write in the block goes through the writer, which holds the connection and opens nothing; a read the block needs comes before it.
- **The connection is opened with `autocommit=True`** ([3.12](https://docs.python.org/3/whatsnew/3.12.html)): a statement outside a transaction is a transaction of its own, and one that spans statements is an explicit `BEGIN` and `COMMIT`. Under `autocommit=False` one is always open, so a read leaves its lock behind, and another connection's write fails with `database is locked` until the reader next commits.
- **A failed rollback never hides what the block raised.** A `ROLLBACK` that raises would take the place of the block's exception, so its failure goes on that exception as a note, and the block's own type still reaches the caller. `test_sqlite.py` makes SQLite refuse the `ROLLBACK`, through an authorizer, and checks.
- **`add` writes only under a free id, in one statement:** `INSERT … ON CONFLICT (id) DO NOTHING`, whose row count says whether it wrote, and the lines go in only then. It returns the order stored under the id, so a reorder sent twice gets the first's back and places one ([python-actions](../../python-actions/SKILL.md)). A `get` before an insert would let two calls both find the id free.
- **A connection serves the thread that opened it.** sqlite3's `check_same_thread`, on by default, makes a connection used from any other thread raise `ProgrammingError`, which `test_sqlite.py` pins. So an operation over the store is shared only where its connection is: the CLI's wiring opens one per command, and an edge that serves on threads gives each thread a connection of its own, or each call one opened and closed around it, as `_canceller` does. Never `check_same_thread=False` over one shared connection: one thread's statements would land inside another's transaction.
- **A transaction that writes begins `IMMEDIATE`,** taking the write lock at once. A second writer then waits its turn, up to the connection's five-second busy timeout, where after a plain `BEGIN` it could fail with `SQLITE_BUSY` as its read turned into a write.
- **The operation sees only the context manager and its writer:** `with self.orders.transaction() as tx: tx.save(order)`. It never holds a connection or a cursor, and never learns how the store keeps its promise.
- **A call to another service stays outside the transaction.** A refund can't roll back, so the canceller claims the order, refunds, then saves, each a write of its own, and the refund's key makes a second run refund once ([python-actions](../../python-actions/SKILL.md)).

## No generic base

A `Repository[T]` with `find`, `add` and `delete` for every type moves the queries into the callers, as filters, and gives every store methods nobody calls. A generic belongs inside a store, in a helper that maps rows, not in a base class the domain sees.

## The fake

Callers test against a fake that holds orders in a dict and behaves like the real store where a test depends on it: not found, a claim refused for the status it finds, the order of a query's answer, a transaction that keeps nothing when its block raises, and a call to the store itself refused inside one.

```python
# tests/order/fakes.py
@dataclass(slots=True)
class FakeOrders:
    """An OrderStore holding orders in a dict, and every order saved."""

    orders: dict[OrderId, Order] = field(default_factory=dict)
    saved: list[Order] = field(default_factory=list)
    in_transaction: bool = False

    @classmethod
    def holding(cls, *orders: Order) -> FakeOrders:
        """A store that holds orders."""
        return cls(orders={order.id: order for order in orders})

    def get(self, order_id: OrderId) -> Order:
        """Return the order, or raise OrderNotFound as the real store does."""
        self._begin()
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
        self._begin()
        self.orders[order.id] = order
        self.saved.append(order)

    def add(self, order: Order) -> Order:
        """Hold and record the order unless one has its id; return the one held under it."""
        self._begin()
        if order.id not in self.orders:
            self.orders[order.id] = order
            self.saved.append(order)
        return self.orders[order.id]

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

- **The transaction's writer is a copy of the fake.** A block that raises never reaches the lines after the `try`, so the copy and its writes are dropped, and the `finally` only lowers `in_transaction`; one that ends cleanly hands them back.
- **Its claim asks `status.cancellable`, as the real store's `UPDATE` does,** so a canceller test of a shipped order meets the same `NotCancellable`, and a claimed order reads back `CANCELLING`.
- **Inside its transaction, a call to the store itself fails as SQLite's does,** with the same `OperationalError`, so a restorer that calls `orders.save` where it means `tx.save` fails its test, as it would in production, rather than lose the write. `tests/order/test_fakes.py` makes the calls `test_sqlite.py` makes of the real store, and checks the queries answer in its order.
- **Its `add` keeps the first order under an id,** as the real store's conditional `INSERT` does, so the reorderer's test of a request sent twice meets the same answer it would in production.
- **One fake fills `OrderStore`, `CancellableOrders`, `TransactionalOrders` and the reorderer's `Orders`.** It lives in `tests/order/fakes.py`, shared by every test that needs it, beside `FakeCanceller`, the fake of `Cancels` that the sweeper's and the withdrawer's tests pass; [python-testing](../../python-testing/SKILL.md) covers where fakes and builders go.

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
- **Each query gets a test that puts the records it must skip beside the ones it must find:** a shipped order and another customer's, for `cancellable_for`; an order without the product, for `containing`, beside one with it and another line, which comes back whole.
- **A server database** (Postgres, MySQL) runs in a container the test session starts, with a schema per test or a transaction rolled back after each; the same tests run against it. `--disable-socket` refuses their connections too, so their module opts in to the one host the container listens on, `pytestmark = pytest.mark.allow_hosts(["127.0.0.1"])`. pytest-socket 0.8.1 then lets those tests connect there and nowhere else, and every other test stays offline.
