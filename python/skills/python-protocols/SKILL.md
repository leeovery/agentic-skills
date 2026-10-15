---
name: python-protocols
description: Small Python Protocols declared by the code that calls them, as seams for outside services, stores, the clock, files and processes, filled in main. Use when integrating an API or SDK, adding persistence, retries or timeouts, injecting a clock or another dependency, faking one in a test, or splitting a large Protocol.
---

# Python Protocols

A Protocol belongs to the code that calls it, and it exists to be swapped: in `main` by config, in a test by a fake.

## Core rules

1. **The consumer declares a `Protocol` beside its use, with only the methods it calls. Implementations don't inherit from it.** pyrefly checks the fit where the wiring hands an implementation to its consumer, and where a test hands it a fake; nothing else is needed. *The consumer depends on what it uses and no more, and one class can fill several consumers' Protocols without knowing any of them.*
2. **No Protocol until a second implementation or a test's fake needs one.** *With one implementation and no fake, it's indirection: a reader jumps through it to find the only code there is.*
3. **One to three methods.** Split a larger one by role (read, decide, write); a consumer that needs two roles declares one Protocol with both. *A big Protocol is hard to fake, so its tests reach for the real thing or a mock.*
4. **An outside service or SDK sits behind your Protocol.**
   - A thin adapter is the real implementation, tested against `httpx2.MockTransport`.
   - Callers test against a fake of your Protocol.
   - The wiring `main` supplies picks the implementation.
   - Nobody fakes the vendor's own types.

   *Your Protocol is the contract you control, in your domain's words. The adapter is the only code that knows the vendor, so a change on their side breaks one module and its tests.* Held by an import-linter `forbidden` contract: the domain never imports `httpx2`.
5. **The adapter owns the call's policy, and nothing above it retries.** Every call has a timeout, set on the client the wiring builds. A call is retried only for a failure in passing (the connection failed or dropped, it timed out, or the answer was 429, 502, 503 or 504), stopping on attempts or elapsed time, with a jittered backoff, honouring `Retry-After`, and logging each retry. A write is retried only when it carries an idempotency key. The library's exceptions leave the adapter translated, `raise PaymentFailed(...) from err`. *A retry at two layers multiplies, four attempts over four is sixteen calls to a provider already struggling, and a write retried without a key charges twice.*
6. **A store is a Protocol named for its data, its methods in the domain's words:** `cancellable_for(customer_id)`, not `find(status=...)`; `start_cancelling(order_id)`, not `update(order_id, status=...)`. No generic repository base. *Each query and each write a caller needs has a name that says what it does, and the store is free to answer it however it likes, in one conditional statement where it must.*
7. **The clock, the environment, files, the network and processes are seams, filled in `main`.**
   - One function is a callable field, passed as the function itself: `now: Callable[[], datetime]`, which `main` fills with `partial(datetime.now, UTC)`; `sleep`; `rand`.
   - An object of several methods is a Protocol: a process runner that finds a program and runs it.
   - Settings arrive as parsed values; files under a base `Path`; HTTP as an `httpx2.Client` built in the wiring with its base URL, auth and timeout.

   *Code that reads the clock or the environment itself can only be tested at the real time, on the real machine.* Held by ruff's `banned-api` (`TID251`): `datetime.now`, `time.time`, `os.environ`, `os.getenv` and `subprocess` everywhere but the module that holds `main` and the one adapter that runs processes.
8. **Fakes are written by hand:** a class that records its calls and returns what the test set. *A fake reads like the domain, and the test checks outcomes instead of pinning the order of calls.* Held by ruff's `banned-api`, which bans `unittest.mock`, and a source guard on patching through any `MonkeyPatch`, in [python-quality](../python-quality/SKILL.md).
9. **No ABC base classes for seams, and no `@runtime_checkable` dispatch.** *An ABC makes every implementation, fakes included, import and inherit the consumer's class, so one class can't fill a second consumer's need without inheriting that too. `isinstance` against a runtime-checkable Protocol checks only that the names exist: an object whose `refund` is a string passes.*

## Pattern

```python
# src/shop/order/cancel.py: the consumer declares what it calls.
class Refunder(Protocol):
    """What refunds a payment, raising PaymentFailed when it can't."""

    def refund(self, refund: Refund) -> None:
        """Make the refund. A second refund under the same key does nothing."""
        ...


@dataclass(frozen=True, slots=True, kw_only=True)
class Canceller:
    """Cancels orders that haven't shipped, refunding those already paid."""

    orders: OrderStore
    payments: Refunder
    now: Callable[[], datetime]
```

```python
# src/shop/cli.py, in the wiring main supplies: pyrefly checks each fit here.
with closing(connect(database)) as db, client() as http:
    yield Canceller(
        orders=SqliteOrders(db=db),
        payments=Gateway(
            client=http,
            retry=Retry(sleep=time.sleep, clock=time.monotonic, rand=random.random),
        ),
        now=partial(datetime.now, UTC),
    )
```

```python
# tests/order/fakes.py: the fake records what it was asked and raises what it was told.
@dataclass(slots=True)
class FakeRefunder:
    """A Refunder that records each refund it makes, once per key, or raises error, if set."""

    refunded: list[Refund] = field(default_factory=list)
    error: Exception | None = None

    def refund(self, refund: Refund) -> None:
        ...
```

No class names `Refunder`. A `refund` that drifts from it fails `pyrefly check` at the line that passes the `Gateway`, naming the parameter that no longer fits. `Refund`, the value it takes, is in [services.md](references/services.md#the-protocol), and the fake whole in [python-testing](../python-testing/SKILL.md).

## Decision table

| Need | Reach for |
|---|---|
| A collaborator with one implementation and no fake | the concrete class |
| A collaborator a test must replace | a Protocol beside the consumer, one to three methods |
| One function to replace: the instant, a wait, a random number | a callable field |
| An outside API or SDK | an adapter over an `httpx2.Client`, tested on `MockTransport` |
| A call that can fail in passing | the adapter's retry policy, its `sleep` a seam |
| Persistence | a store named for its data, with methods in the domain's words |
| Writes that land together | the store's `transaction()` ([python-actions](../python-actions/SKILL.md)) |
| Another program | a runner Protocol, and one adapter allowed `subprocess` |
| A test at a particular instant: month end, an expiry | an injected `now` |

## Anti-patterns

- ❌ `class OrderService(Protocol)` beside its only implementation, "for mocking" → delete it; each consumer declares what it calls.
- ❌ `class SqliteOrders(OrderStore)` → no base class: pyrefly checks the fit where it's passed.
- ❌ `class Refunder(ABC)` with `@abstractmethod` → a `Protocol`, declared by the consumer.
- ❌ `if isinstance(payments, Refunder):` on a `@runtime_checkable` Protocol → take the type you need as a parameter.
- ❌ `IOrderStore`, `OrderStoreProtocol`, `AbstractRepository` → name the role: `OrderStore`, `Refunder`, `Runner`.
- ❌ `Repository[T]` with `find(**filters)`, `add` and `delete` for every type → the queries the domain asks, each by name.
- ❌ A Protocol over `httpx2.Client`, or `mock.patch("httpx2.Client.post")` → run the adapter on `MockTransport`, and fake your own Protocol above it.
- ❌ `@retry` on the operation as well as in the adapter, or `time.sleep(2 ** attempt)` → one policy, in the adapter, sleeping through its seam.
- ❌ `datetime.now(UTC)`, `os.environ["SHOP_LABELS"]` or `subprocess.run` in a module → a seam `main` fills.
- ❌ `MagicMock(spec=Refunder)` → a fake of ten lines.

## References

- **[seams.md](references/seams.md)** — read when code needs the clock, the environment, files, the network, a process, ids or randomness: each seam, what `main` passes, what a test passes, callable field or Protocol, and why no seam has a default.
- **[services.md](references/services.md)** — read when integrating an outside API or SDK: the adapter, timeouts, the retry policy and its tests, translating httpx2's exceptions, testing on `MockTransport`, and SDKs.
- **[stores.md](references/stores.md)** — read when adding persistence: naming, each consumer's slice, rows kept inside, transactions, the fake, and testing the real store on `tmp_path`.

## Related skills

- **[python-actions](../python-actions/SKILL.md)** — the operations that hold these Protocols as fields, and the transaction rule.
- **[python-testing](../python-testing/SKILL.md)** — tests built on the fakes, and the three tests a retry policy needs.
- **[python-errors](../python-errors/SKILL.md)** — adapters and stores translate a dependency's exceptions into the domain's.
- **[python-types](../python-types/SKILL.md)** — adapters map the wire's shapes to domain types.
- **[python-architecture](../python-architecture/SKILL.md)** — `main` wires it all, and only `main` reads the environment.
- **[python-concurrency](../python-concurrency/SKILL.md)** — deadlines on waits, and the resources a seam holds open.
