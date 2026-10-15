# Exceptions

**An exception says what went wrong in the terms its caller acts on, rises to the one place that acts, and is handled there once.**

Contents: the error channel · one base per package · translating · notes · catching · every problem at once · groups · the edge · interrupts and cancellation · suppressing · `assert` · in tests.

## The error channel

- **A failure raises.** `SqliteOrders.get` raises `OrderNotFound`; it never returns `None` for a missing order.
- **`None` is for an absence that is no failure,** and the type says so: `Order.payment_id` is `PaymentId | None` because a pending order hasn't been paid.
- **No `Result` type.** The standard library and every dependency raise, so a `Result` adds a second channel beside the first. A `Result` nobody inspects fails silently, where an exception nobody catches reaches the edge.

## One base per package

```python
# src/shop/order/errors.py
"""What can go wrong with an order, as exceptions a caller can branch on."""

from shop.order.order import OrderId, PaymentId


class OrderError(Exception):
    """The base of every exception the order package raises."""


class OrderNotFound(OrderError):
    """No order has the id."""

    def __init__(self, order_id: OrderId) -> None:
        super().__init__(f"order {order_id} not found")
        self.order_id = order_id


...


class PaymentFailed(OrderError):
    """The payment provider refused a refund, or couldn't be reached."""

    def __init__(self, payment_id: PaymentId) -> None:
        super().__init__(f"refunding payment {payment_id} failed")
        self.payment_id = payment_id
```

- **The base lets an edge take every expected failure in one clause:** `run` catches `OrderError` and prints it.
- **Each subclass is there because a caller branches on it.** The sweeper keeps going past `NotCancellable` and `PaymentFailed`; a refund that failed needs someone to act on its `payment_id`. A failure nobody tells apart stays the base with a message.
- **What a caller branches on is an attribute, typed, set in `__init__`,** and the message is built from the same values. The message is for people; no code reads it.
- **A builtin for a bug, or a value refusing its own rule:** `ValueError` for a value that breaks a rule as it's built (`Order` with no lines). `run` logs a builtin as a crash, so whatever the user must be told is the package's own.
- `TRY003` is off: with a subclass only where a caller branches, a message written at the raise is the normal case.

## Translating

At a boundary you own, an adapter or a store, the library's exception becomes the domain's, as `Gateway.refund` does in the [SKILL.md](../SKILL.md#pattern) pattern; [python-protocols](../../python-protocols/SKILL.md) has the adapter whole.

- **`from err` sets `__cause__`,** and the traceback prints the httpx2 error first, then "The above exception was the direct cause of the following exception". Without `from`, Python still links them, as `__context__`, and prints "During handling of the above exception, another exception occurred", which reads as a second bug. ruff's `B904` flags a `raise` inside `except` with neither.
- **`from None` hides the cause, and only on purpose,** where it's a detail of how the code works, not why it failed: the fake store's `KeyError` says nothing `OrderNotFound` doesn't, so `FakeOrders.get` raises it `from None`.

- **Inside the package, nothing translates.** A helper's `ValueError` rises as itself; translating at every layer turns one failure into a chain of three.

## Notes

`err.add_note(...)` ([3.11](https://docs.python.org/3/whatsnew/3.11.html)) adds a line the traceback prints under the message, and the exception keeps its type, so every `except` that matched it still does:

```python
# src/shop/storage/sqlite.py, in _order, which every read of an order goes through
try:
    return Order(
        id=OrderId(order_id),
        ...
    )
except ValueError as err:
    err.add_note(f"reading order {order_id} from the database")
    raise
```

- **Add what the traceback lacks:** the id, the file, the attempt count. `'refunded' is not a valid Status` names no order; the note does.
- **Add it where the fact is known,** then `raise` the same exception. That is still handling it once: nothing is logged, and the caller decides what it means.
- **Notes live in `err.__notes__`,** a list, which a test can check.

## Catching

- **Catch where something acts on it:** the gateway retries a failure in passing, an edge maps one to an answer, a loop logs one it skips. Code that can do none of these lets it rise.
- **Name the narrowest type, around the one call that raises it.** A wide `try` catches the same exception from a line that wasn't meant to raise it.
- **Never log and re-raise.** The edge logs it again, and the log shows one failure as two.
- **`raise`, not `raise err`,** to re-raise: the bare form keeps the traceback as it was, and ruff's `TRY201` flags the other.
- **Several types share a clause** as `except TimeoutError, ConnectionError:` without brackets from [3.14](https://docs.python.org/3/whatsnew/3.14.html); with `as`, the brackets stay.

## Every problem at once

- **At a trust boundary, pydantic collects every bad field into one `ValidationError`** ([python-types](../../python-types/SKILL.md)).
- **A check written by hand collects the same way:** each rule appends its problem to a list, and after every rule has run, one exception carries them all as a tuple attribute. The caller fixes everything in one pass.

## Groups

Independent operations that each fail raise their failures together, as an `ExceptionGroup`. A `TaskGroup` raises one ([python-concurrency](../../python-concurrency/SKILL.md)); so does code that tries every item and reports what failed:

```python
# src/shop/order/sweep.py, in Sweeper
def sweep(self, customer_id: CustomerId, *, reason: Reason) -> tuple[Order, ...]:
    """Cancel each of the customer's cancellable orders, and return them as saved."""
    cancelled: list[Order] = []
    failed: list[NotCancellable | PaymentFailed] = []
    for order in self.orders.cancellable_for(customer_id):
        try:
            cancelled.append(self.canceller.cancel(order.id, reason=reason))
        except (NotCancellable, PaymentFailed) as err:
            failed.append(err)
    if failed:
        raise ExceptionGroup(f"sweeping {customer_id}'s orders", failed)
    return tuple(cancelled)
```

- **It catches only the failures it expects of one order.** Anything else, a store that's down, stops the sweep at once: the next order would fail the same way.
- **A caller takes the group apart with `except*`.** `except* NotCancellable as shipped:` handles `shipped.exceptions`, and the kinds it doesn't name rise on, still in a group. `return`, `break` and `continue` can't appear in an `except*` block.
- **A plain `except OrderError` doesn't match a group of them.** An edge that calls code raising groups names `ExceptionGroup` in its mapping, or the group reaches its last resort as a bug.
- **Validation is not a group.** Several problems with one input are one failure with a list; a group is for failures that each stand alone.

## The edge

Each edge has one function from exception to answer: `run` for the CLI, a handler wrapper for HTTP. [python-actions](../../python-actions/SKILL.md) holds the codes; the [SKILL.md](../SKILL.md#pattern) pattern shows `run`.

- **Expected failures are answered and not logged:** an `OrderError` prints `shop: <message>`, each note on a line beneath it, and exits 1. There's nothing for anyone else to fix.
- **A library's exception is mapped where it's expected, never at the last resort.** The wiring turns the settings' `ValidationError` into `SettingsError`, and the HTTP edge answers a body's with 422. `run` doesn't catch `ValidationError` itself: one that reaches it came from a bug, and needs its traceback.
- **Anything else is a bug, logged once with its traceback** by the last resort, `except Exception: logger.exception("shop failed")`. The one other `except Exception` is the poller's loop, so the next run can go ahead ([python-concurrency](../../python-concurrency/SKILL.md)). ruff's `BLE001` accepts both because each logs the traceback.
- **Domain exceptions carry no status or exit code.** An HTTP edge maps `OrderNotFound` to 404 and `NotCancellable` to 409 in its own function; the CLI maps both to 1 in `run`.

## Interrupts and cancellation

`KeyboardInterrupt`, `SystemExit` and `asyncio.CancelledError` derive from `BaseException`, not `Exception`, so no `except Exception` swallows them.

- **Ctrl-C is a `KeyboardInterrupt`,** which typer turns into exit code 130, the shell's own for an interrupt, and `run` returns it like any other code.
- **`CancelledError` is how asyncio stops a task:** cleanup runs in `finally` or `async with`, and the error rises on ([python-concurrency](../../python-concurrency/SKILL.md)).
- **`except BaseException` only to clean up and re-raise.** Cleanup that can't fail goes in `finally`, which needs no `except` at all. Cleanup that can fail goes in `except BaseException`, notes its own failure on the exception with `add_note`, and re-raises it ([python-concurrency](../../python-concurrency/SKILL.md)). Raised in `finally`, the cleanup's exception would take the place of the one the caller is waiting for.

## Suppressing

- **`contextlib.suppress` names the exceptions it ignores,** each one you mean to: `with suppress(ProcessLookupError):` around signalling a process that may have gone. `suppress(Exception)` is a blind `except`.
- **The API's own option comes first:** `path.unlink(missing_ok=True)`, `directory.mkdir(parents=True, exist_ok=True)`.
- **`except ...: pass` is a `suppress` written longhand.** ruff's `S110` flags it.

## `assert`

- **`python -O` strips every `assert` statement,** so a check that must hold raises: `if not order.lines: raise ValueError(...)`. ruff's `S101` flags `assert` outside `tests/`.
- **`assert_never(x)` in a `match`'s last `case` is a function call,** not a statement, and still raises under `-O`.
- **In tests, plain `assert`:** pytest rewrites it into a failure that shows both sides.

## In tests

Match the way callers match: by type, then attributes, `pytest.raises(NotCancellable) as refused`, then `refused.value.status is Status.SHIPPED`, as [python-testing](../../python-testing/SKILL.md#pattern)'s pattern does.

- **A translated exception's cause is checked as a type:** `isinstance(failed.value.__cause__, httpx2.HTTPStatusError)`.
- **A group is checked with `pytest.RaisesGroup`** (pytest 8.4), which fails unless the group holds exactly the types named.
- **`match=` checks a property the message must have,** such as naming the order, never its whole text.
