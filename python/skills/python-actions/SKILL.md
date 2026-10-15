---
name: python-actions
description: Python business logic as functions or frozen dataclasses of their collaborators, called from thin typer commands, HTTP handlers and queue consumers. Use when adding or changing business logic, a CLI command or option, an HTTP handler, a queue consumer, a transaction or file write, or a program's exit codes.
---

# Python Actions

Business logic lives in operations: a function, or a frozen dataclass of its collaborators with one verb method. Commands, handlers and consumers only translate to and from them.

## Core rules

1. **An operation is a function until it needs collaborators; then it's a `@dataclass(frozen=True, slots=True, kw_only=True)` of them with one verb method.** `reordered(previous, order_id=..., prices=...)` needs nothing. `Canceller` holds its store, its refunder and its clock. Frozen holds nothing per call: `self.count += 1` is pyrefly's `read-only` error, `self.current = order` its `missing-attribute`, and either raises `FrozenInstanceError` if it runs. *One operation can serve calls that run at once, on an edge's threads or tasks, so state stored on it would be shared between them; and a test swaps a field for a fake.*
2. **Name it by package and verb:** the module `shop/order/cancel.py`, the class `Canceller`, the method `cancel`. No `Service`, `Manager`, `Handler` or `Processor` on a domain class, and no `cancel_order` inside `shop.order`. *The package already says "order"; a suffix adds a word and no meaning.* Held by a source guard, in python-quality.
3. **Calculations take loaded values, not ids to fetch.** `reordered(previous, order_id=..., prices=prices)` takes the order and today's prices, never an id it then fetches. The operation loads, decides through pure functions, then writes. *A pure function is tested with plain values, and any caller holding the values reuses it without a store.*
4. **Guards come first, as named checks:** a property such as `order.status.cancellable`, or a call that raises, as the store's `start_cancelling` raises `NotCancellable` from the write that claims the order. The happy path runs down the left margin, with no `else` after a guard. *The check reads as the rule it enforces, and the property answers the same question for every caller.*
5. **Edges translate and do nothing more.** A typer command, an HTTP handler or a queue consumer parses its input into typed values, calls one operation, then writes the result or maps the exception. *Logic in an edge can't be reused by the other edges, or tested without its transport.* Held by import-linter: the domain imports no typer, pydantic or httpx2, and no edge imports another.
6. **Compose by holding.** An operation that needs another holds it as a field, typed as a one-method Protocol. *It never redoes the other's steps against the store, so that rule keeps one owner, and a test fakes it in a line.*
7. **A write leaves no half state.** Writes that land together go through one store transaction, a context manager: `with self.orders.transaction() as tx:`. A file is replaced whole: written to a temporary file beside it, then renamed over it. A call that leaves the process can't roll back, so it never sits inside a transaction: it follows a claim that a second run can resume from, and comes before the writes that finish the change. *A failure partway leaves the old state or the new, never a mix the next run trips over.*
8. **Anything that can run twice is idempotent:** an upsert or a conditional write, never a check followed by a write, and an idempotency key derived from the work on every write to a provider. A new record takes its id from the caller, picked once for what it means, never minted inside the call. *A queue delivers at least once, a user presses twice, a retry follows a timeout that had succeeded; each must leave the state one run would.*
9. **The typer app is built by `build_app(wiring)`, never at module level.** Commands are closures that call the wiring only when they run, each opening only what it uses, so `--help` and a usage error build nothing. Data goes out through `typer.echo`, diagnostics through `typer.echo(..., err=True)`. *Each test builds a fresh app over fakes, and a mistyped command needs no settings to be told so.* Held by ruff's `T201`, which bans `print`, and the import-time guard, which reports a `typer.Typer()` built at module level.
10. **`run` is the one function that sets exit codes:** 0 for success, 1 for a failure, 2 for a usage error, 130 for an interrupt. `main(argv)` returns what `run` returns; nothing else exits. *A script tells a bad call from a failed one, and Ctrl-C reads as the shell's own.* Held by ruff's `TID251`, which bans `sys.exit` outside `shop/cli.py` and `shop/processes.py`, whose lift is for `subprocess`.

## Pattern

```python
@dataclass(frozen=True, slots=True, kw_only=True)
class Canceller:
    """Cancels orders that haven't shipped, refunding those already paid."""

    orders: OrderStore
    payments: Refunder
    now: Callable[[], datetime]

    def cancel(self, order_id: OrderId, *, reason: Reason) -> Order:
        """Cancel the order for reason, and return it as saved.

        Raises OrderNotFound, NotCancellable, or PaymentFailed when a paid
        order's refund fails. That leaves the order CANCELLING, and a second
        cancel finishes it.
        """
        # Claimed before the refund, which can't be undone, so nothing ships
        # under it; a cancel that stopped after the claim can run again.
        order = self.orders.start_cancelling(order_id)
        # Claimed, it's CANCELLING whatever it was; Order holds a paid one to its
        # payment's id, and a pending one to none.
        if order.payment_id is not None:
            # Keyed by the order, so a cancel run again refunds once.
            self.payments.refund(
                Refund(
                    payment_id=order.payment_id,
                    amount=order.total,
                    key=IdempotencyKey(f"cancel-{order.id}"),
                )
            )
        cancelled = copy.replace(
            order,
            status=Status.CANCELLED,
            cancelled_at=self.now(),
            cancel_reason=reason,
        )
        self.orders.save(cancelled)
        logger.info("order %s cancelled, reason %s", order_id, reason)
        return cancelled
```

`main`'s wiring builds it when `cancel` runs, `Canceller(orders=SqliteOrders(db=db), payments=Gateway(client=http, retry=Retry(...)), now=partial(datetime.now, UTC))`, and the HTTP handler and the queue consumer call one built the same way. `start_cancelling` moves the order to `CANCELLING` in one write conditioned on its status, so a shipment that lands first is refused rather than overwritten, and nothing ships under the refund. A cancel that stops after the claim leaves the order `CANCELLING`, which still allows cancelling, so it runs again and finishes; whether it refunds comes from the payment id, which `Order` requires of a paid order and refuses on a pending one. The refund's key comes from the work, the order being cancelled, so the second run refunds once, and a refund of the same payment for another reason gets a key of its own.

## Decision table

| Need | Reach for |
|---|---|
| A calculation or rule with no collaborators | a function: `reordered(previous, order_id=..., prices=...)` |
| A question about one value | a property on its type: `order.total`, `status.cancellable` |
| Logic that needs a store, a client or a clock | a frozen dataclass of them, with one verb method |
| Three or more related inputs, or two of one type | keyword-only parameters, or an input dataclass ([python-types](../python-types/SKILL.md)) |
| A precondition | `ensure_x(...)` that raises, or a property on the type |
| One operation needs another | hold it, as a one-method Protocol |
| Writes that land together | `with orders.transaction() as tx:` |
| A file something else reads | `atomic.replace(path, data)` |
| A write that may run twice | an upsert, or `UPDATE … WHERE` the state it expects; a derived idempotency key to a provider |
| A call that can't be undone, on a record something else may change | claim the record first with a conditional write, to a state a second run resumes from: `start_cancelling` |
| A command, handler or consumer | an edge: parse, call one operation, write or map the exception |
| How the process exits | `run`, the one function from outcome to exit code |

## Anti-patterns

- ❌ A loop over the store, a status check or a sum in a command or a handler → an operation; the edge calls it.
- ❌ `OrderService` with a dozen methods → one operation per job, named by its verb.
- ❌ `self.current = order`, or a `@dataclass` left unfrozen so that it can → pass `order`, or build a per-call value inside the method.
- ❌ `OrderManager`, `CancelOrderHandler`, `shop.order.cancel_order` → `shop.order.cancel.Canceller.cancel`.
- ❌ `def discount(customer_id: CustomerId)` that fetches the customer → `discount(customer, orders)`, loaded by the operation.
- ❌ Two `save` calls that must both land, each committing → one `with orders.transaction() as tx:`.
- ❌ `path.write_bytes(data)` over a file something else reads → `atomic.replace(path, data)`: `write_bytes` truncates first.
- ❌ `if not store.exists(order.id): store.insert(order)` → an upsert, or an insert the unique key refuses.
- ❌ `store.get(order_id)`, a status check, the refund, then `store.save(order)` → claim it first, `store.start_cancelling(order_id)`, then refund and save.
- ❌ `"Idempotency-Key": str(uuid.uuid4())`, or a key from the payment alone → a key derived from the work: the canceller's `IdempotencyKey(f"cancel-{order.id}")`.
- ❌ `app = typer.Typer()` at module level, commands registered by decorator at import → `build_app(wiring)`.
- ❌ `print(...)`, `sys.exit(1)`, or `raise typer.Exit(1)` for a failure → `typer.echo`; raise the domain's exception and let `run` map it.

## References

- **[operations.md](references/operations.md)** — read when adding or changing business logic: function or dataclass, nothing per call, naming, guards, composition, transactions, replacing a file, idempotency, and the HTTP and queue edges.
- **[cli.md](references/cli.md)** — read when adding or changing a command, an option or an exit code: `build_app`, a command, output, `run` and its codes, tracebacks and secrets, testing a command, start-up time.

## Related skills

- **[python-architecture](../python-architecture/SKILL.md)** — which package an operation lives in, and `main` as the composition root.
- **[python-types](../python-types/SKILL.md)** — the ids, values and enums an operation takes, and the boundary models an edge parses.
- **[python-errors](../python-errors/SKILL.md)** — the exceptions a guard raises, and mapping them at an edge.
- **[python-protocols](../python-protocols/SKILL.md)** — the stores and Protocols an operation holds, and their fakes.
- **[python-concurrency](../python-concurrency/SKILL.md)** — consumers that run until shutdown, and async operations.
- **[python-testing](../python-testing/SKILL.md)** — testing an operation through its fakes.
