# Fakes and Builders

**A test builds its input with a builder that names what matters, and hands the code under test hand-written fakes of its own Protocols.**

Contents: builders · where they live · fakes · an async fake · the HTTP adapter · real files and SQLite · when a test seems to need a patch.

## Builders

A builder is a plain function that returns a valid value, with a keyword default for every field a test might care about:

```python
# tests/order/builders.py
NOW = datetime(2026, 10, 14, 9, 30, tzinfo=UTC)


def make_line(*, sku: str = "mug", quantity: int = 2, unit_minor: int = 1250) -> Line:
    """A line of two £12.50 mugs, unless told otherwise."""
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
    return Order(
        id=OrderId(order_id),
        customer_id=CustomerId(customer_id),
        status=status,
        lines=lines or (make_line(),),
        payment_id=None if payment_id is None else PaymentId(payment_id),
    )
```

- **`make_order(status=Status.PAID, payment_id="pay-1")` reads as the case it builds.** A literal with every field hides the one that matters among those that don't.
- **Keyword-only, and every default valid.** The value passes the type's own checks (`Order` refuses an order with no lines), so a test that doesn't mention a field never trips on it.
- **Plain strings in, typed values out.** The builder wraps `OrderId` and `PaymentId`, so tests write `"pay-1"`.
- **Each test builds its own value.** A module-level `PAID_ORDER` that tests share is fine only while it's frozen; a fixture returning one hides it in another file.
- **The same builders feed hypothesis:** `st.builds(make_order, status=st.sampled_from(Status))` ([properties-snapshots-benchmarks.md](properties-snapshots-benchmarks.md)).

## Where they live

Builders and fakes are modules in the test package of the code they serve, `tests/order/builders.py` and `tests/order/fakes.py`, imported by name: `from tests.order.builders import make_order`. That works because every test directory is a package and pytest runs with `--import-mode=prepend` ([python-quality](../../python-quality/SKILL.md)).

They aren't fixtures in a `conftest.py`. An import says where a name comes from, a function takes the arguments a fixture can't, and pyrefly checks the call.

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

    ...

    def save(self, order: Order) -> None:
        """Hold the order and record that it was saved."""
        self.orders[order.id] = order
        self.saved.append(order)

    ...


@dataclass(slots=True)
class FakeRefunder:
    """A Refunder that records each refund and raises error, when it's set."""

    refunded: list[tuple[PaymentId, Money]] = field(default_factory=list)
    error: Exception | None = None

    def refund(self, payment_id: PaymentId, amount: Money) -> None:
        """Record the refund, then raise the error the test set, if any."""
        self.refunded.append((payment_id, amount))
        if self.error is not None:
            raise self.error
```

- **It fails as the real one fails.** `FakeOrders.get` raises `OrderNotFound` with the id, so the code under test meets the same exception in a test as in production. The methods left out above, the other consumers' queries and the transaction, answer as the real store does too ([python-protocols](../../python-protocols/SKILL.md)).
- **It doesn't inherit from the Protocol.** It matches it by shape, and pyrefly checks the match where the test passes it to `Canceller`. A Protocol method the fake lacks fails the type check, not a test run.
- **Check what it recorded as a value:** `assert payments.refunded == [(PaymentId("pay-1"), Money(minor=2500, currency=Currency.GBP))]`. Check the order or the number of calls only when they are the behaviour.
- **It holds no logic of its own.** Once a fake needs some, a memory implementation that lives beside the real one is the better tool ([python-protocols](../../python-protocols/SKILL.md)).
- **It's mutable**, unlike the domain's values: a fake is test equipment that collects what happened.

## An async fake

A fake of an async Protocol is the same shape with `async def`. This one also counts the calls in flight, to test a concurrency cap:

```python
# tests/settlement/fakes.py
@dataclass(slots=True)
class FakeProvider:
    """A Provider that records each settlement, and how many ran at once.

    It refuses the payments in `refuse`, and never answers for those in `hang`.
    """

    refuse: frozenset[PaymentId] = frozenset()
    hang: frozenset[PaymentId] = frozenset()
    settled: list[PaymentId] = field(default_factory=list)
    running: int = 0
    most_at_once: int = 0

    async def settle(self, payment_id: PaymentId) -> None:
        """Settle, refuse or hang, as the test set; count the calls in flight."""
        self.running += 1
        self.most_at_once = max(self.most_at_once, self.running)
        try:
            await asyncio.sleep(0)  # lets the other tasks start, so they overlap
            if payment_id in self.hang:
                await asyncio.Event().wait()
            if payment_id in self.refuse:
                raise SettlementFailed(payment_id)
            self.settled.append(payment_id)
        finally:
            self.running -= 1
```

`asyncio.sleep(0)` yields to the loop without waiting. A payment in `hang` waits forever, so the settler's deadline has to end it; [time.md](time.md) runs that test on a virtual clock.

## The HTTP adapter

The adapter is the one place that knows the provider's HTTP API, so it's tested for real: the real `httpx2` client, with `httpx2.MockTransport` answering in place of the network. Everything above the adapter tests against a fake of the Protocol it fills, never against the transport.

```python
# tests/payments/test_gateway.py
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


def test_refund_posts_the_amount_keyed_by_payment() -> None:
    handler, sent = answers(httpx2.Response(201))

    gateway(handler).refund(PaymentId("pay-1"), TEN_POUNDS)

    [request] = sent
    assert request.method == "POST"
    assert request.url == "https://pay.example/payments/pay-1/refunds"
    assert request.headers["Idempotency-Key"] == "refund-pay-1"
    assert json.loads(request.content) == {"amount": 1000, "currency": "GBP"}
```

- **The handler is the provider.** `answers` gives each answer in turn, as the provider would: a 201, a 402, a 503, or an `httpx2.ConnectError` it raises for a provider that can't be reached. It keeps each request it was sent, for the test to read.
- **`gateway(handler)` builds the adapter on a client over that transport,** its retry policy on a fake clock that never waits ([time.md](time.md)).
- **Test the translation** as well as the happy path: a refused refund raises `PaymentFailed` with the payment's id, its `__cause__` the `httpx2.HTTPStatusError`.
- **An `AsyncClient` takes the same transport,** and the test closes it with `async with`:

```python
# tests/payments/test_settlements.py
@asynccontextmanager
async def settlements(
    handler: Callable[[httpx2.Request], httpx2.Response],
) -> AsyncGenerator[Settlements]:
    """Settlements whose client answers every request with handler, closed on exit."""
    async with httpx2.AsyncClient(
        base_url="https://pay.example", transport=httpx2.MockTransport(handler)
    ) as client:
        yield Settlements(client=client)
```

## Real files and SQLite

A store that writes files or a database runs for real on `tmp_path`, which pytest makes per test and keeps the last few runs of. It's cheaper than faking the filesystem, and it tests the encoding too:

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

This is what fixtures are for: a resource with a lifetime, opened before the test and closed after it. `path` is a fixture of its own so a test can open a second connection on the same file and see what was committed. Pass the directory in; code that finds its own directory can't be pointed at `tmp_path`.

## When a test seems to need a patch

`unittest.mock` is banned by ruff's `TID251`, and `monkeypatch.setattr` by a source guard. A test that seems to need either has found a dependency the code reaches for instead of being handed:

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
