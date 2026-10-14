# Outside Services

**Your Protocol in your domain's words, one adapter that knows the vendor and owns the call's policy, and a fake of your Protocol for everyone else.**

Contents: the shape · the Protocol · the adapter · timeouts · retries · a loop, not a library · translating failures · testing the adapter, and its callers · choosing in the wiring · SDKs.

## The shape

```
src/shop/order/cancel.py       Refunder, declared where cancel calls it
src/shop/payments/gateway.py   Gateway, the HTTP adapter, and Retry, its policy
src/shop/cli.py                the wiring: builds the client and the policy, picks the Gateway
```

`shop.payments` imports `shop.order` for its types and errors; `shop.order` never imports `shop.payments` or `httpx2`. The import-linter `layers` and `forbidden` contracts hold both directions, so the domain and its tests run without the adapter.

## The Protocol

Domain types in and out: `PaymentId`, `Money`, the order package's exceptions. No `httpx2.Response`, vendor dict or request model appears in it. Once the vendor's names are in the signature, the Protocol is the SDK by another name, and replacing the vendor rewrites every caller.

## The adapter

```python
# src/shop/payments/gateway.py
@dataclass(frozen=True, slots=True, kw_only=True)
class Gateway:
    """The provider's refunds endpoint, as the order package's Refunder.

    main hands it a client with the base URL, the token and a timeout set, so
    every call has a deadline, and the policy for calls that fail in passing.
    """

    client: httpx2.Client
    retry: Retry

    def refund(self, payment_id: PaymentId, amount: Money) -> None:
        """Refund amount of the payment, keyed so a repeat refunds once.

        Raises PaymentFailed, from httpx2's error, when the provider refuses, or
        can't be reached in the attempts the policy allows.
        """
        request = self.client.build_request(
            "POST",
            f"/payments/{payment_id}/refunds",
            json={"amount": amount.minor, "currency": amount.currency},
            # The key makes the refund safe to send twice, so _send may retry it.
            headers={"Idempotency-Key": f"refund-{payment_id}"},
        )
        try:
            self._send(request)
        except httpx2.HTTPError as err:
            raise PaymentFailed(payment_id) from err

    def _send(self, request: httpx2.Request) -> httpx2.Response:
        """Send request, and again while it fails in passing and the policy allows.

        Only a request that is safe to repeat comes here: a read, or a write
        with an idempotency key.
        """
        deadline = self.retry.clock() + self.retry.within.total_seconds()
        attempt = 1
        while True:
            try:
                return self.client.send(request).raise_for_status()
            except httpx2.HTTPError as err:
                if not passing(err):
                    raise
                wait = self.retry.wait(attempt, err)
                if (
                    attempt == self.retry.attempts
                    or self.retry.clock() + wait > deadline
                ):
                    err.add_note(f"gave up after {attempt} attempts")
                    raise
                logger.warning(
                    "retrying %s %s in %.1fs, after attempt %d: %s",
                    request.method,
                    request.url.path,
                    wait,
                    attempt,
                    err,
                )
                self.retry.sleep(wait)
                attempt += 1
```

- **`Gateway` is concrete,** and nothing in it names `Refunder`. pyrefly checks the fit where the wiring passes it to `Canceller`.
- **The refund carries an idempotency key from the payment,** so the provider refunds once however often it's asked. That is what makes the write safe to retry, and why `Canceller` can refund before its own write ([python-actions](../../python-actions/SKILL.md)).
- **No decisions.** Whether an order may be refunded is the order package's question. The adapter refunds.
- **The body is the wire's shape,** built here and nowhere else; a response worth reading is parsed here into domain types ([python-types](../../python-types/SKILL.md)).

## Timeouts

- **Every call has one, set on the client the wiring builds** from `Settings.gateway_timeout` ([seams.md](seams.md)). `build_request` copies the client's timeout onto each request. Left unset, httpx2 waits five seconds, a number nobody chose.
- **The timeout bounds one attempt; the policy bounds the whole call.** The deadline is checked before each wait, so a refund gives up within `within` plus one attempt's timeout. Deadlines on waits inside asyncio are [python-concurrency](../../python-concurrency/SKILL.md)'s.

## Retries

The policy is a value, built in the wiring with its seams:

```python
# src/shop/payments/gateway.py
# The answers that say the provider, or a proxy in front of it, may manage later.
PASSING_STATUSES = (
    HTTPStatus.TOO_MANY_REQUESTS,
    HTTPStatus.BAD_GATEWAY,
    HTTPStatus.SERVICE_UNAVAILABLE,
    HTTPStatus.GATEWAY_TIMEOUT,
)


@dataclass(frozen=True, slots=True, kw_only=True)
class Retry:
    """How often, and for how long, a call that failed for a passing reason is made again.

    sleep, clock and rand are seams: main passes time.sleep, time.monotonic and
    random.random, and a test passes its own, so it never waits.
    """

    sleep: Callable[[float], None]
    clock: Callable[[], float]
    rand: Callable[[], float]
    attempts: int = 4
    within: timedelta = timedelta(seconds=30)
    first_wait: timedelta = timedelta(milliseconds=500)
    longest_wait: timedelta = timedelta(seconds=8)

    def wait(self, attempt: int, err: httpx2.HTTPError) -> float:
        """Seconds to wait after a failed attempt: what the provider asked, or a jittered backoff."""
        if isinstance(err, httpx2.HTTPStatusError):
            asked = retry_after(err.response)
            if asked is not None:
                return asked
        backoff = min(self.first_wait * 2 ** (attempt - 1), self.longest_wait)
        return self.rand() * backoff.total_seconds()


...


def passing(err: httpx2.HTTPError) -> bool:
    """Whether the same request may succeed later: the connection failed or dropped, it timed out, or the provider asked to wait."""
    match err:
        case httpx2.HTTPStatusError(response=response):
            return response.status_code in PASSING_STATUSES
        case httpx2.NetworkError() | httpx2.TimeoutException():
            return True
        case httpx2.RemoteProtocolError():
            # httpx2 raises it when the server closes the connection before its
            # answer is whole. Its sibling LocalProtocolError is our own bug: no retry.
            return True
        case _:
            return False


def retry_after(response: httpx2.Response) -> float | None:
    """The seconds a Retry-After header asks for; None without one, or for a date."""
    value = response.headers.get("Retry-After", "")
    return float(value) if value.isdecimal() else None
```

- **An allow-list, never a deny-list.** The connection failed or dropped (`httpx2.NetworkError`, and `httpx2.RemoteProtocolError` for a server that hangs up before its answer is whole), the call timed out (`httpx2.TimeoutException`), or the answer was 429, 502, 503 or 504. Anything else, a 400, a 401, a 404, a 500, a `LocalProtocolError` from a request we built wrong, a `ValueError`, fails at once: the same request will fail the same way, and a 500 may have done half its work.
- **It stops on attempts or elapsed time,** whichever comes first, and never waits past its deadline: a `Retry-After` longer than the time left gives up now.
- **The backoff doubles and is jittered:** a random fraction of `first_wait × 2ⁿ⁻¹`, capped at `longest_wait`. Without jitter, clients that failed together retry together, and the provider's recovery meets the same spike.
- **`Retry-After` wins over the backoff** in its seconds form. Its HTTP-date form needs the wall clock, so it falls back to the backoff; an adapter whose provider sends dates takes `now` as well.
- **A write is retried only with an idempotency key.** `_send` is reached only by a request safe to repeat; a write without a key sends once, through the client.
- **Each retry is logged at WARNING** with the attempt, the wait and the cause, as a rising retry rate is the first sign of a failing provider. The error that ends the call gets a note, `gave up after 4 attempts`, and is logged once, where it stops ([python-errors](../../python-errors/SKILL.md)).
- **Nothing above the adapter retries.** `Canceller` doesn't, and neither does the CLI: two layers that each try four times make sixteen calls.

## A loop, not a library

The retry is twenty lines in the adapter, not a dependency. Weighed on 2026-10-14:

- **tenacity** (9.2.1): `@retry` fixes the policy, and the real `time.sleep`, when the module is imported. Its `Retrying` object can be built in the wiring and takes `sleep=`, but the allow-list and `Retry-After` still need a predicate and a wait function written here, and the library adds nothing the loop lacks.
- **stamina** (26.1.0, built on tenacity): its `on=` hook may return a wait, which honours `Retry-After`, but it sleeps on its own. Tests turn waiting off with `stamina.set_testing(True)`, a switch held by the module, not passed in, which also sets the attempt count, so no test can check the waits the policy chose.
- **The loop** is a value built in `main`, its every wait visible to a test, and one fewer dependency to review and upgrade ([python-project](../../python-project/SKILL.md)).

## Translating failures

- **`except httpx2.HTTPError as err: raise PaymentFailed(payment_id) from err`.** `HTTPError` is the base of the transport and status errors. Callers branch on `PaymentFailed` and its `payment_id`, and never import httpx2; the cause stays on `__cause__` for the log. ruff's `B904` flags a raise in an `except` without `from`.
- **A failure a caller acts on differently gets its own subclass,** translated here: an unknown payment, a card the issuer refused. [python-errors](../../python-errors/SKILL.md) holds when one is worth it.

## Testing the adapter, and its callers

Against `httpx2.MockTransport`, so the test sees the request the provider would see and decides each answer. A handler that gives scripted answers in turn:

```python
# tests/payments/test_gateway.py
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
```

A retry policy gets three tests, a rule [python-testing](../../python-testing/SKILL.md) holds: it recovers after failures in passing, it stops at the cap, and it never retries a permanent failure. With `gateway` and `FakeTime` from [seams.md](seams.md):

```python
# tests/payments/test_gateway.py
def test_refund_recovers_after_passing_failures() -> None:
    time = FakeTime()
    handler, sent = answers(
        httpx2.Response(503),
        httpx2.ConnectError("connection refused"),
        httpx2.Response(201),
    )

    gateway(handler, time).refund(PaymentId("pay-1"), TEN_POUNDS)

    assert len(sent) == 3
    assert time.slept == [0.5, 1.0]


def test_refund_stops_at_the_attempt_cap() -> None:
    time = FakeTime()
    handler, sent = answers(*[httpx2.Response(503)] * 4)

    with pytest.raises(PaymentFailed) as failed:
        gateway(handler, time).refund(PaymentId("pay-1"), TEN_POUNDS)

    assert len(sent) == 4
    assert time.slept == [0.5, 1.0, 2.0]
    assert failed.value.__cause__ is not None
    assert failed.value.__cause__.__notes__ == ["gave up after 4 attempts"]


def test_refund_never_retries_a_permanent_failure() -> None:
    time = FakeTime()
    handler, sent = answers(httpx2.Response(400))

    with pytest.raises(PaymentFailed):
        gateway(handler, time).refund(PaymentId("pay-1"), TEN_POUNDS)

    assert len(sent) == 1
    assert time.slept == []
```

The adapter's tests add one each for retrying a dropped connection under the same idempotency key, never retrying a `LocalProtocolError`, honouring `Retry-After`, stopping when the wait would pass the deadline, and logging each retry. pytest-socket fails any test that opens a real socket, so a client built without the mock transport fails loudly.

Code that refunds is tested against `FakeRefunder` instead (the [SKILL.md](../SKILL.md#pattern) pattern), and never sees HTTP, so a change in the provider's API breaks the gateway's tests alone.

## Choosing in the wiring

With two implementations, the gateway and a stand-in for local runs that accepts every refund, the wiring picks one with a `match` on a `Literal` setting, ending `case _: assert_never(...)`. `Settings` rejects any other value at startup, naming the choices, so nothing runs with a default nobody picked. The stand-in isn't a test double: it behaves plausibly and never fails, where a fake records what its test asks about and fails when told to.

## SDKs

- **The adapter wraps the SDK's client;** the Protocol the domain sees is still yours, in your words.
- **When the SDK takes an `httpx2.Client`, its test passes one on `MockTransport`.**
- **Never declare a Protocol over the SDK's client to fake it, and never patch its methods.** The fake encodes your guess at the SDK's behaviour, and passes where the real SDK fails.
- **When the SDK takes no client,** keep the adapter thin enough that its check is a run against the vendor's sandbox, outside the unit tests.
- **The SDK's own retries are switched off,** or the adapter's are: one layer retries.
