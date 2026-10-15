# Fakes and Builders

**A test builds its input with a builder that names what matters, and hands the code under test hand-written fakes of its own Protocols.**

Contents: builders · where they live · fakes · an async fake · the HTTP adapter · real files and SQLite · when a test seems to need a patch.

## Builders

A builder is a plain function that returns a valid value, with a keyword default for every field a test might care about:

```python
# tests/order/builders.py
NOW = datetime(2026, 10, 14, 9, 30, tzinfo=UTC)


def make_line(*, sku: str = "mug", quantity: int = 2, unit_minor: int = 1250) -> Line:
    return Line(
        sku=sku,
        quantity=quantity,
        unit_price=Money(minor=unit_minor, currency=Currency.GBP),
    )


def make_order(
    *,
    order_id: str = "o-1",
    customer_id: str = "c-1",
    status: Status = Status.PENDING,
    payment_id: str | None = None,
    lines: tuple[Line, ...] | None = None,
) -> Order:
    """A pending order of one line, unless told otherwise."""
    if payment_id is None and status.paid:
        payment_id = "pay-1"
    cancelled = status is Status.CANCELLED
    return Order(
        id=OrderId(order_id),
        customer_id=CustomerId(customer_id),
        status=status,
        lines=(make_line(),) if lines is None else lines,
        payment_id=None if payment_id is None else PaymentId(payment_id),
        cancelled_at=NOW if cancelled else None,
        cancel_reason=Reason.CUSTOMER if cancelled else None,
    )
```

- **`make_order(status=Status.PAID)` reads as the case it builds.** A literal with every field hides the one that matters among those that don't.
- **Keyword-only, and every default valid, whatever else the test asks for.** The value passes the type's own checks, so a test that doesn't mention a field never trips on one. A test of a refusal breaks a valid value on purpose, `copy.replace(make_order(status=Status.PAID), payment_id=None)`.
- **A default stands in for `None` alone, never through `or`.** `lines or ...` would swap `make_order(lines=())`'s empty tuple for a line, and the test of `Order`'s refusal would pass by building a valid order.
- **Plain strings in, typed values out.** The builder wraps `OrderId` and `PaymentId`, so tests write `"pay-1"`.
- **Each test builds its own value.** A module-level `PAID_ORDER` is fine only while it's frozen; a fixture returning one hides it in another file.
- **The same builders feed hypothesis:** `st.builds(make_order, status=st.sampled_from(Status))` ([properties-snapshots-benchmarks.md](properties-snapshots-benchmarks.md)).

## Where they live

Builders and fakes are modules in the test package of the code they serve, `tests/order/builders.py` and `tests/order/fakes.py`, imported by name: `from tests.order.builders import make_order`. That works because every test directory is a package and pytest runs with `--import-mode=prepend` ([python-quality](../../python-quality/SKILL.md)). Not fixtures in a `conftest.py`: an import says where a name comes from, a function takes the arguments a fixture can't, and pyrefly checks the call.

## Fakes

A fake is a small class that does what the Protocol promises in the simplest way, and records what it was asked:

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

    ...


@dataclass(slots=True)
class FakeRefunder:
    """A Refunder that records each refund it makes, once per key, or raises error, if set."""

    refunded: list[Refund] = field(default_factory=list)
    error: Exception | None = None

    def refund(self, refund: Refund) -> None:
        """Raise error, if set; or record the refund, once per key as the provider makes it."""
        if self.error is not None:
            raise self.error
        if all(made.key != refund.key for made in self.refunded):
            self.refunded.append(refund)
```

- **It fails as the real one fails.** `FakeOrders.get` raises `OrderNotFound` with the id, and its claim refuses with `NotCancellable` what the SQLite store's conditional `UPDATE` refuses, so the code under test meets the same exceptions in a test as in production. `tests/order/test_fakes.py` checks the fake against what `test_sqlite.py` checks of the real store; its transaction is in [python-protocols](../../python-protocols/SKILL.md).
- **It doesn't inherit from the Protocol.** It matches it by shape, and pyrefly checks the match where the test passes it to `Canceller`. A Protocol method the fake lacks fails the type check, not a test run.
- **Check what it recorded as a value:** `assert payments.refunded == [PAID_IN_FULL]`. Check the order or the number of calls only when they are the behaviour.
- **It keeps the Protocol's promises and holds no other logic.** `FakeRefunder` records one refund per key because `Refunder` promises a second does nothing. Once a fake needs logic past its promises, a memory implementation that lives beside the real one is the better tool ([python-protocols](../../python-protocols/SKILL.md)).
- **It records only what happened.** `FakeRefunder` raises the error it was given before it records anything, as a provider that refuses a refund makes none. A fake that records first tells the test a refund went back that never did.
- **It's mutable**, unlike the domain's values: a fake is test equipment that collects what happened.

An operation that holds another, as the sweeper holds a `Cancels`, gets a fake of that one method, `FakeCanceller`, the same shape as `FakeRefunder`: it records each cancel, or raises the error the test set for that order. Its tests check what it asked for and none of the canceller's own steps.

## An async fake

A fake of an async Protocol is the same shape with `async def`. This one also counts the calls in flight, to test a concurrency cap:

```python
# tests/settlement/fakes.py
@dataclass(slots=True)
class FakeProvider:
    """A Provider that records each settlement, and how many ran at once.

    It never answers for the payments in `hang`.
    """

    hang: frozenset[PaymentId] = frozenset()
    settled: list[PaymentId] = field(default_factory=list)
    running: int = 0
    most_at_once: int = 0

    async def settle(self, payment_id: PaymentId) -> None:
        """Settle or hang, as the test set; count the calls in flight."""
        self.running += 1
        self.most_at_once = max(self.most_at_once, self.running)
        try:
            await asyncio.sleep(0)  # lets the other tasks start, so they overlap
            if payment_id in self.hang:
                await asyncio.Event().wait()
            self.settled.append(payment_id)
        finally:
            self.running -= 1
```

A payment in `hang` waits forever, so the settler's deadline has to end it; [time.md](time.md) runs that test on a virtual clock.

## The HTTP adapter

The adapter is the one place that knows the provider's HTTP API, so it's tested with the real `httpx2` client, `httpx2.MockTransport` answering in place of the network. Everything above it tests against a fake of its Protocol.

```python
# tests/payments/test_gateway.py
REFUND = Refund(
    payment_id=PaymentId("pay-1"),
    amount=Money(minor=1000, currency=Currency.GBP),
    key=IdempotencyKey("cancel-o-1"),
)

type Handler = Callable[[httpx2.Request], httpx2.Response]


...


def answers(
    *responses: httpx2.Response | httpx2.HTTPError,
) -> tuple[Handler, list[httpx2.Request]]:
    """A handler giving each answer in turn, raising the errors, and the requests it saw."""
    seen: list[httpx2.Request] = []
    pending: Iterator[httpx2.Response | httpx2.HTTPError] = iter(responses)

    def handle(request: httpx2.Request) -> httpx2.Response:
        seen.append(request)
        answer = next(pending)
        if isinstance(answer, httpx2.HTTPError):
            raise answer
        return answer

    return handle, seen


def test_refund_posts_the_amount_under_the_callers_key() -> None:
    handler, sent = answers(httpx2.Response(201))

    with gateway(handler) as payments:
        payments.refund(REFUND)

    [request] = sent
    assert request.method == "POST"
    assert request.url == "https://pay.example/payments/pay-1/refunds"
    assert request.headers["Idempotency-Key"] == "cancel-o-1"
    assert json.loads(request.content) == {"amount": 1000, "currency": "GBP"}
```

- **The handler is the provider.** `answers` gives each answer in turn, raising an `httpx2.ConnectError` for a provider that can't be reached, and keeps each request for the test to read.
- **`gateway(handler)` opens the adapter on a client over that transport, in a `with`,** so the client closes when the test's block ends ([python-concurrency](../../python-concurrency/SKILL.md)). Its retry policy runs on a fake clock that never waits ([time.md](time.md)).
- **Test the translation** as well as the happy path: a refused refund raises `PaymentFailed` with the payment's id, its `__cause__` the `httpx2.HTTPStatusError`.
- **An `AsyncClient` takes the same transport,** and the test, or an async helper it calls, closes it with `async with`.

## Real files and SQLite

A store that writes files or a database runs for real on `tmp_path`, which pytest makes per test. It's cheaper than faking the filesystem, and it tests the encoding too:

```python
# tests/storage/test_sqlite.py
@pytest.fixture
def path(tmp_path: Path) -> Path:
    return tmp_path / "shop.db"


@pytest.fixture
def orders(path: Path) -> Iterator[SqliteOrders]:
    with closing(connect(path)) as db:
        yield SqliteOrders(db=db)
```

This is what fixtures are for: a resource with a lifetime, opened before the test and closed after it. `path` is a fixture of its own so a test can open a second connection on the same file. Pass the directory in; code that finds its own can't be pointed at `tmp_path`.

## When a test seems to need a patch

`unittest.mock` is banned by ruff's `TID251`, and `MonkeyPatch`'s `setattr` and its kin by a source guard. A test that seems to need either has found a dependency the code reaches for instead of being handed:

| The test wants to patch | Give the code |
|---|---|
| `datetime.now` | `now: Callable[[], datetime]`, filled by `main` |
| `time.sleep` | `sleep: Callable[[float], None]` |
| `os.environ`, `os.getenv` | the value, from the `Settings` that `main` builds |
| `Path.home()` | the directory |
| a method on `httpx2.Client` | a client built with `MockTransport` |
| `subprocess.run` | a runner Protocol |
| a module-level client or settings object | nothing at import; `main` builds it and passes it down |

Add the parameter, have `main` pass the real thing, and pass a fake from the test. [python-protocols](../../python-protocols/SKILL.md) has each seam; [python-architecture](../../python-architecture/SKILL.md) has why nothing runs at import.
