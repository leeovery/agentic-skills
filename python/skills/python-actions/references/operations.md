# Operations

**An operation does one job: typed values in, a result or an exception out, holding nothing but its collaborators.**

Contents: function or dataclass · nothing per call · naming · guards · composition · transactions · replacing a file · idempotency · edges · checklist.

## Function or dataclass

A function when the job needs only its input. Reordering is a calculation over values already loaded:

```python
def reordered(
    previous: Order, *, order_id: OrderId, prices: Mapping[str, Money]
) -> Order:
    """A new pending order for previous's products still on sale, at today's prices.

    Raises ValueError when none of them is still on sale.
    """
    return Order(
        id=order_id,
        customer_id=previous.customer_id,
        status=Status.PENDING,
        lines=tuple(
            Line(sku=line.sku, quantity=line.quantity, unit_price=prices[line.sku])
            for line in previous.lines
            if line.sku in prices
        ),
    )
```

A frozen dataclass when the job needs a store, a client, a clock or another operation. Its fields are those collaborators and nothing else, and it has one public method, named for the verb. It loads, calls the function, and writes:

```python
@dataclass(frozen=True, slots=True, kw_only=True)
class Reorderer:
    """Places an earlier order again, at today's prices."""

    orders: Orders
    catalogue: Catalogue
    new_id: Callable[[], OrderId]

    def reorder(self, order_id: OrderId) -> Order:
        """Save a new pending order for the products of the order with the id."""
        previous = self.orders.get(order_id)
        prices = self.catalogue.prices(line.sku for line in previous.lines)
        order = reordered(previous, order_id=self.new_id(), prices=prices)
        self.orders.save(order)
        return order
```

- **`Orders` and `Catalogue` are Protocols declared beside it,** with only the methods it calls ([python-protocols](../../python-protocols/SKILL.md)). `new_id` is a seam like the clock, so a test knows the id it gets.
- **`main` builds it with keywords,** and a test builds it the same way with fakes in the fields; `kw_only=True` means two collaborators of one type can't swap. No `__init__` by hand: a field that needs a default takes one.
- **Two jobs are two dataclasses,** even when they hold the same store. A class with `create`, `cancel` and `refund` is a service under another name: it gathers every job's collaborators and grows with each job added.
- **A question about one value is a property on its type,** not an operation: `order.total`, `status.cancellable`.

## Nothing per call

`main` builds an operation once and shares it, so a call never stores anything on it. A count kept as a field, `self.restored += 1`, would be shared by two restores at once. `frozen=True` makes that an error: pyrefly reports ``Cannot set field `restored` [read-only]``, and it raises `FrozenInstanceError` if it runs. `slots=True` gives the instance no `__dict__`, so a new name, `self.current = order`, fails too (`missing-attribute`).

A call's state is local to the call: `Restorer.restore`, below, counts in a local variable. When helpers need the same state, pass it, or build a private, unfrozen dataclass inside the method and hand that to them.

## Naming

- **The module is the job, the class its doer, the method the verb:** `shop/order/cancel.py`, `Canceller`, `cancel`. A reader writes `from shop.order.cancel import Canceller` and `canceller.cancel(order_id, reason=reason)`.
- **A pure function is named for what it returns** (`reordered`, `total`); a method that acts, for the act (`reorder`).
- **No `Service`, `Manager`, `Handler` or `Processor`** on a class in a domain package, and no `cancel_order` in `shop.order`. Guard 6 in [python-quality](../../python-quality/SKILL.md)'s source guards holds the suffixes.

## Guards

Every precondition is checked at the top, before any write, as a named check: `ensure_cancellable(order)` in the [SKILL.md](../SKILL.md#pattern) pattern raises `NotCancellable` unless `order.status.cancellable`.

- **A property** when the question is about one value alone: `Status.cancellable`. It lives on the type, so every caller that asks gets the same answer ([python-types](../../python-types/SKILL.md)).
- **`ensure_x(...)`** when the check raises, or needs more than one value. Its exception carries what it checked as attributes ([python-errors](../../python-errors/SKILL.md)).
- **No comment over a guard.** If the check needs one, its name is wrong.
- **No `else` after a guard raises.** The happy path stays at the left margin, and the last line returns the result.

## Composition

An operation that needs another holds it as a field. It never repeats the other's steps:

```python
@dataclass(frozen=True, slots=True, kw_only=True)
class Withdrawer:
    """Withdraws a product, cancelling each open order that holds it."""

    orders: OrdersByProduct
    canceller: Cancels

    def withdraw(self, sku: str) -> tuple[Order, ...]:
        """Cancel every open order holding the product, and return them as saved.

        A failure stops it partway. Run again, it finds only the orders still
        open, so it finishes the job without cancelling any order twice.
        """
        held = self.orders.containing(sku)
        return tuple(
            self.canceller.cancel(order.id, reason=Reason.OUT_OF_STOCK)
            for order in held
            if order.status.cancellable
        )
```

The trap this avoids: a `withdraw` that saves each order as cancelled itself, and forgets the refund, because the refund lives in `cancel`.

- **The field is a one-method Protocol,** `Cancels`, declaring `cancel(order_id, *, reason) -> Order` beside `Withdrawer`. The test chooses: a real `Canceller` over the fakes, when they're cheap to set up, or a fake that records "cancel `o-1`, out of stock".
- **`main` wires the chain:** `Withdrawer(orders=store, canceller=canceller)`, the same `Canceller` the edges call.

## Transactions

Writes that must land together go through one transaction, which the store gives as a context manager:

```python
@dataclass(frozen=True, slots=True, kw_only=True)
class Restorer:
    """Restores exported orders into the store."""

    orders: TransactionalOrders

    def restore(self, orders: Iterable[Order]) -> int:
        """Save every order in one transaction, and return how many it saved.

        Each save replaces the order under its id, so a second run leaves the
        store as the first did, and a failed run leaves it as it was.
        """
        restored = 0
        with self.orders.transaction() as tx:
            for order in orders:
                tx.save(order)
                restored += 1
        return restored
```

`TransactionalOrders` is a Protocol beside it with one method, `transaction() -> AbstractContextManager[OrderWrites]`, and `OrderWrites` has `save`. The SQLite store opens the transaction with `with self.db:`, which commits on a clean exit and rolls back on an exception, and yields a writer bound to it. Its own `save` is one write in a transaction of its own:

```python
@dataclass(frozen=True, slots=True, kw_only=True)
class SqliteOrders:
    """Orders in SQLite, as the order package's OrderStore. No row leaves it."""

    db: sqlite3.Connection

    ...

    def save(self, order: Order) -> None:
        """Write the order and its lines in one transaction, replacing any under its id."""
        with self.transaction() as tx:
            tx.save(order)

    @contextmanager
    def transaction(self) -> Generator[SqliteTransaction]:
        """Open a transaction: committed when the block ends, rolled back if it raises.

        Every write in the block goes through the writer it yields: this store's
        own save would commit, part way through.
        """
        with self.db:
            yield SqliteTransaction(db=self.db)
```

- **Every write in the block goes through `tx`,** never the store's own `save`, which opens a `with self.db:` of its own. sqlite3's doesn't nest: the inner block's exit commits the whole transaction part way through, and a later failure can't take that back.
- **A call that leaves the process can't roll back.** A refund or an email sent inside the block has happened even when the commit fails. Make it before the writes, keyed so a retry is safe, as `Canceller` makes its refund; or record it in the transaction and send it after the commit.
- **The fake stages the block's writes on a copy** and keeps them only when the block ends cleanly, so a test sees the rollback ([python-testing](../../python-testing/SKILL.md)).
- **`-> Generator[T]` on a `@contextmanager`,** not `Iterator[T]`: the stubs mark the `Iterator` form deprecated, and the drop-in's pyrefly fails on it.

## Replacing a file

A file something else reads is never written in place. `Path.write_bytes` and `open(path, "w")` truncate it first, so a crash midway leaves it empty or cut short. Write a temporary file in the same directory, get it to disk, then rename it over the target: a rename within one filesystem is atomic, so a reader sees the old file or the new one.

```python
def replace(path: Path, data: bytes, *, mode: int = 0o644) -> None:
    """Replace the file at path with data.

    The data goes to a temporary file beside path, reaches the disk, then is
    renamed over path: a rename within one filesystem is atomic. On any
    failure the temporary file is removed and path is left as it was.
    """
    with tempfile.NamedTemporaryFile(
        dir=path.parent, prefix=f".{path.name}.", delete_on_close=False
    ) as temp:
        temp.write(data)
        temp.flush()
        os.fsync(temp.fileno())
        # mkstemp makes the file readable by its owner alone.
        os.fchmod(temp.fileno(), mode)
        temp.close()
        Path(temp.name).replace(path)
```

- **The same directory,** never `/tmp`: a rename can't cross filesystems, and `Path.replace` raises `OSError` when asked to.
- **`delete_on_close=False`** ([3.12](https://docs.python.org/3/whatsnew/3.12.html)) keeps the file through `close()`, and the `with` deletes it on the way out if anything failed. After the rename there's nothing under its name, which it ignores.
- **`fsync` before the rename,** or a crash can leave the new name pointing at data that never reached the disk.
- **It lives in one module, `shop/storage/atomic.py`,** called as `atomic.replace(path, data)`, so the steps are written once.

## Idempotency

A message is delivered again, a user presses twice, a retry follows a timeout that had in fact succeeded. Each must leave the state one run would.

- **An upsert, never a check then a write.** `if not store.exists(id): store.insert(order)` lets two runs both see nothing and both insert. The SQLite store's save upserts the order, `INSERT … ON CONFLICT (id) DO UPDATE`, and replaces its lines in the same transaction, so restoring the same export twice leaves one copy of each order.
- **A conditional write when a change must not overwrite another.** Load, check and save lets a cancel overwrite a shipment that landed between the load and the save. The write states what it expects, `UPDATE orders SET status = 'shipped' WHERE id = ? AND status = 'paid'`, and its row count says whether it won: 0 means another run moved the order first, so reload it and decide again.
- **An idempotency key on every write to a provider,** derived from the work: the gateway sends `Idempotency-Key: refund-{payment_id}`, so a retried refund refunds once. A fresh `uuid4()` per call gives every retry a new key, and the provider does it twice. The adapter owns the header ([python-protocols](../../python-protocols/SKILL.md)).
- **A job that stops partway is safe to run again** when it selects what's left to do. `withdraw` cancels only the open orders, so a second run finishes the first's work.

## Edges

An edge parses, calls one operation, and writes the result or maps the exception. That's all it does.

### HTTP

No framework is assumed. A framework's route calls the handler with the path's values and the raw body, and sends the `Reply`:

```python
def cancel_order(canceller: Canceller, *, order_id: str, body: bytes) -> Reply:
    """POST /orders/{order_id}/cancel: cancel the order for the reason in the body."""
    try:
        request = CancelRequest.model_validate_json(body)
    except ValidationError as err:
        problems = err.json(include_url=False, include_input=False)
        return Reply(status=422, body=problems.encode())
    try:
        order = canceller.cancel(OrderId(order_id), reason=request.reason)
    except OrderError as err:
        return Reply(
            status=status_for(err), body=json.dumps({"error": str(err)}).encode()
        )
    return Reply(status=200, body=order_reply(order).model_dump_json().encode())
```

- **`CancelRequest` and `OrderReply` are pydantic models at the edge,** and `order_reply` maps the domain's `Order` onto the reply ([python-types](../../python-types/SKILL.md)). The domain's dataclasses never become the body.
- **`status_for` is the API's one mapping** from exception to status, a `match` on its class: 404 for `OrderNotFound`, 409 for `NotCancellable`, 502 for `PaymentFailed`. The domain's exceptions carry no status ([python-errors](../../python-errors/SKILL.md)). An exception that isn't an `OrderError` rises to the framework's own handler for the unexpected.
- **Where the framework parses the body into the model for you,** the handler takes the model, and the rest is the same.

### Queue consumers

The same shape: parse the message, call one operation, and let an exception rise for the queue to redeliver or dead-letter:

```python
def cancel_requested(canceller: Canceller, body: bytes) -> None:
    """Cancel the order the message names."""
    message = CancelRequested.model_validate_json(body)
    try:
        canceller.cancel(OrderId(message.order_id), reason=message.reason)
    except NotCancellable as err:
        if err.status is not Status.CANCELLED:
            raise
        # The queue delivers a message at least once: this one was handled before.
        logger.info("order %s already cancelled", message.order_id)
```

- **Returning acknowledges the message; raising leaves it on the queue.** A message that can never succeed, such as one for a shipped order, ends in the dead-letter queue, where someone looks at it.
- **A redelivery is success.** The order already cancelled is the state the message asked for, so the consumer acknowledges it rather than failing forever.
- **The loop that pulls messages, and its shutdown,** are [python-concurrency](../../python-concurrency/SKILL.md)'s.

### What an edge never does

- Loops over a store, checks a status or sums money.
- Passes a request, a typer context or a raw message into the domain.
- Calls two operations in a row to make a third. That sequence is an operation of its own, holding the two.
- Words a value its own way. It calls `shop.display` ([python-architecture](../../python-architecture/SKILL.md)).

## Checklist

- [ ] One job, named by its verb: `from shop.order.cancel import Canceller`.
- [ ] A function until it needs collaborators; then a frozen, slotted, keyword-only dataclass holding only them.
- [ ] Calculations take loaded values; the operation loads, decides, then writes.
- [ ] Guards first, each a named check; another operation's steps reached by holding it.
- [ ] Writes that land together share one transaction, with no call that leaves the process inside it; files are replaced whole.
- [ ] A write that can run twice is an upsert or a conditional write; a provider gets a derived key.
- [ ] Each edge parses, calls one operation, and writes or maps the exception.
