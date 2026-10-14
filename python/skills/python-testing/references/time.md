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

A wait between retries, or a pause between polls, is a call to `sleep`, and the code takes it as a parameter: `sleep: Callable[[float], None]`, which `main` fills with `time.sleep`. A test passes `waits.append`, which returns at once and records what it was asked to wait. The suite never sleeps, and the test can check the back-off itself.

The retry loop is the adapter's ([python-protocols](../../python-protocols/SKILL.md)). The tests below drive `Retry`, which takes `attempts`, `first_wait`, the `transient` exception types and `sleep`; whatever shape the project's loop has, the three tests keep theirs.

## The three retry tests

A retry policy has three behaviours, and each gets a test: it recovers from failures that pass, it stops at its cap, and it never retries a failure that won't pass.

```python
# tests/payments/test_retry.py
@dataclass(slots=True)
class Flaky:
    """A call that raises each of `failures` in turn, then returns "done"."""

    failures: list[Exception]
    calls: int = 0

    def __call__(self) -> str:
        self.calls += 1
        if self.failures:
            raise self.failures.pop(0)
        return "done"


def retry(waits: list[float]) -> Retry:
    """Three attempts at transport errors, recording each wait in waits."""
    return Retry(
        attempts=3,
        first_wait=0.5,
        transient=(httpx2.TransportError,),
        sleep=waits.append,
    )


def test_retry_recovers_from_failures_that_pass() -> None:
    waits: list[float] = []
    call = Flaky([httpx2.ConnectError("refused"), httpx2.ReadTimeout("slow")])

    assert retry(waits).call(call) == "done"
    assert call.calls == 3
    assert waits == [0.5, 1.0]


def test_retry_stops_at_its_last_attempt() -> None:
    call = Flaky([httpx2.ConnectError("refused") for _ in range(3)])

    with pytest.raises(httpx2.ConnectError):
        retry([]).call(call)

    assert call.calls == 3


def test_retry_never_retries_a_permanent_failure() -> None:
    waits: list[float] = []
    call = Flaky([PaymentFailed(PaymentId("pay-1"))])

    with pytest.raises(PaymentFailed):
        retry(waits).call(call)

    assert call.calls == 1
    assert waits == []
```

- **Count the calls.** "It recovered" is `calls == 3`; "it stopped" is `calls == 3` and the last error raised; "it didn't retry" is `calls == 1` and no wait.
- **Check the waits** where the back-off is the behaviour: `[0.5, 1.0]` pins the doubling. With jitter, the policy takes its randomness as a parameter too, or the test checks each wait's bounds.
- **Through the adapter,** the same three run against `httpx2.MockTransport`: a handler that answers 503 twice then 201, one that always answers 503, and one that answers 402. A `Retry-After` header gets a fourth: the wait recorded is the one the header asked for.

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
