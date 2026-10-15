# Time in Tests

**Code that reads the clock or waits takes the clock and the wait as parameters, so a test sets the instant and skips the wait. asyncio's own timers run on a virtual clock.**

Contents: an injected `now` · an injected `sleep` · the three retry tests · asyncio on a virtual clock · time-machine, and what it can't move · async tests.

## An injected `now`

An operation that stamps or decides on the time takes `now: Callable[[], datetime]` ([python-protocols](../../python-protocols/SKILL.md)). `main` passes `partial(datetime.now, UTC)`; a test passes a fixed instant:

```python
# tests/order/test_cancel.py
def canceller(orders: FakeOrders, payments: FakeRefunder | None = None) -> Canceller:
    """A Canceller over the fakes, its clock stopped at NOW."""
    return Canceller(
        orders=orders, payments=payments or FakeRefunder(), now=lambda: NOW
    )


def test_cancel_records_the_reason_and_the_time() -> None:
    orders = FakeOrders.holding(make_order())

    cancelled = canceller(orders).cancel(OrderId("o-1"), reason=Reason.OUT_OF_STOCK)

    assert cancelled.status is Status.CANCELLED
    assert cancelled.cancelled_at == NOW
    assert cancelled.cancel_reason is Reason.OUT_OF_STOCK
    assert orders.saved == [cancelled]
```

- **The test asserts the exact instant,** not "within a second of now".
- **Pick the instant the case is about:** the last second of a month, 29 February, the hour a clock change skips. An aware UTC `datetime` built in the test, never `datetime.now()`, which the drop-in bans outside `main`.
- **`NOW` lives with the builders,** so every test that doesn't care shares one instant, and a test that does sets its own.

## An injected `sleep`

A wait between retries, or a pause between polls, is a call to `sleep`, and the code takes it as a parameter: `sleep: Callable[[float], None]`, which `main` fills with `time.sleep`. Code that also keeps a deadline takes `clock: Callable[[], float]`, filled with `time.monotonic`. A test passes two bound methods of one fake, so the clock moves exactly as far as the code slept, and at once:

```python
# tests/payments/test_gateway.py
@dataclass(slots=True)
class FakeTime:
    """A clock that moves only when something sleeps, and records each sleep."""

    now: float = 0.0
    slept: list[float] = field(default_factory=list)

    def sleep(self, seconds: float) -> None:
        """Record the wait, and move the clock past it at once."""
        self.slept.append(seconds)
        self.now += seconds

    def clock(self) -> float:
        """The seconds slept so far."""
        return self.now


def gateway(handler: Handler, time: FakeTime | None = None) -> Gateway:
    """A Gateway whose client answers every request with handler, on a fake clock.

    rand returns 1, so each backoff is its whole bound: 0.5s, 1s, 2s.
    """
    time = time or FakeTime()
    client = httpx2.Client(
        base_url="https://pay.example", transport=httpx2.MockTransport(handler)
    )
    retry = Retry(sleep=time.sleep, clock=time.clock, rand=lambda: 1.0)
    return Gateway(client=client, retry=retry)
```

The suite never sleeps, and the test can check the back-off itself. The jitter's `rand` is a seam too: fixed at 1, every wait is its whole bound, so a test asserts each one exactly.

The retry loop is the adapter's ([python-protocols](../../python-protocols/SKILL.md)), so its tests run the adapter on `httpx2.MockTransport`, never a copy of the loop.

## The three retry tests

A retry policy has three behaviours, and each gets a test: it recovers from failures that pass, it stops at its cap, and it never retries a failure that won't pass. `answers(...)` is a handler that gives each answer in turn, raising the errors, with the list of requests it was sent ([fakes-and-builders.md](fakes-and-builders.md)):

```python
# tests/payments/test_gateway.py
def test_refund_recovers_after_passing_failures() -> None:
    time = FakeTime()
    handler, sent = answers(
        httpx2.Response(503),
        httpx2.ConnectError("connection refused"),
        httpx2.Response(201),
    )

    gateway(handler, time).refund(REFUND)

    assert len(sent) == 3
    assert time.slept == [0.5, 1.0]


def test_refund_stops_at_the_attempt_cap() -> None:
    time = FakeTime()
    handler, sent = answers(*[httpx2.Response(503)] * 4)

    with pytest.raises(PaymentFailed) as failed:
        gateway(handler, time).refund(REFUND)

    assert len(sent) == 4
    assert time.slept == [0.5, 1.0, 2.0]
    assert failed.value.__cause__ is not None
    assert failed.value.__cause__.__notes__ == ["gave up after 4 attempts"]


def test_refund_never_retries_a_permanent_failure() -> None:
    time = FakeTime()
    handler, sent = answers(httpx2.Response(400))

    with pytest.raises(PaymentFailed):
        gateway(handler, time).refund(REFUND)

    assert len(sent) == 1
    assert time.slept == []
```

- **Count the calls.** "It recovered" is three requests sent; "it stopped" is four sent, and `PaymentFailed` raised from the last error, which notes how many attempts it gave; "it didn't retry" is one sent and no wait.
- **Check the waits** where the back-off is the behaviour: `[0.5, 1.0, 2.0]` pins the doubling. Were `rand` left real, the test could only check each wait's bounds.
- **Each other limit the policy keeps gets a test of its own:** a `Retry-After` header, where the wait recorded is the one it asked for, and a wait that would pass the deadline, where the call stops without waiting.

## asyncio on a virtual clock

`asyncio.timeout`, `asyncio.sleep` and a loop's `call_later` read the event loop's clock, which no injected `sleep` reaches. [looptime](https://pypi.org/project/looptime/) gives a test's loop a virtual clock: when every task is waiting, the clock jumps to the next timer, so a 30-second deadline passes at once and exactly.

```python
# tests/settlement/test_settle.py
@pytest.mark.looptime
async def test_settle_gives_up_on_a_payment_at_its_deadline() -> None:
    provider = FakeProvider(hang=frozenset({PaymentId("pay-3")}))
    loop = asyncio.get_running_loop()
    start = loop.time()

    unsettled = await settler(provider, limit=5).settle(PAYMENTS)

    assert unsettled == [PaymentId("pay-3")]
    assert loop.time() - start == 30
```

- **Mark the tests that wait,** with `@pytest.mark.looptime`, rather than switching it on for the whole run with `--looptime`: a test without the mark that waits is a test to look at.
- **Exact equality holds,** as nothing else moves the virtual clock: `== 30`, not `>= 30`.
- **It moves only the loop's clock.** `time.monotonic()`, a thread's wait and real I/O take real time; give those code paths an injected `sleep` or a fake.
- **It's a pytest plugin** in the test dependency group (`uv add --group test looptime`), working beside pytest-asyncio in the drop-in's auto mode. It's pre-1.0 (0.9 on 2026-10-14), and the lockfile pins it.
- **An interval is tested the same way:** a poller run every 60 seconds inside a body that sleeps 150 runs its job at 60 and 120, read from `loop.time()`.

## time-machine, and what it can't move

time-machine is for code you don't own that reads the clock itself, where no `now` can be passed in. `with time_machine.travel(datetime(2026, 3, 1, 9, tzinfo=UTC), tick=False):` around the call moves `datetime.now()`, `time.time()` and the functions built on them, such as `email.utils.formatdate()`, to that instant, and `tick=False` holds it there.

- **Never for your own code.** Code you own takes `now`; travelling the whole process to test it hides the dependency the signature should show.
- **It doesn't move `time.monotonic()`** (not since 3.0), so it can't speed up a timeout, a deadline or a wait.
- **It doesn't reach a clock read outside Python.** pydantic's `FutureDate` and `PastDatetime` check against the system clock from Rust, and ignore a travel. Validate such a rule in your own code against `now`, or test with dates far enough from today that the real clock can't matter.
- **It's CPython only,** and the drop-in's dependency group already holds it. Never freezegun alongside: time-machine refuses to run while freezegun is active.

## Async tests

- **`async def test_...` runs as it is.** The drop-in sets `asyncio_mode = "auto"`, so pytest-asyncio runs every async test and fixture on asyncio, each on a loop of its own. No `@pytest.mark.asyncio`.
- **A test never calls `asyncio.run`,** and never reaches for a loop: `asyncio.get_running_loop()` inside the test when it needs `loop.time()`.
- **An async resource is opened with `async with` in the test,** or in an async helper like `settlements(handler)` in [fakes-and-builders.md](fakes-and-builders.md), so it's closed on the loop that used it.
- **Concurrency is tested by what it guarantees,** not by timing: the fake counts the calls in flight, and the test checks `most_at_once == 2`. A test that sleeps to let tasks overlap is a test that sometimes doesn't.
- **Code that starts tasks is tested for stopping them,** as the poller's tests do by leaving its `async with` and checking what the job did up to then, and that a failure inside it surfaces.
