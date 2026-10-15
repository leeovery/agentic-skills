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

Domain types in and out: the order package's `Refund`, made of a `PaymentId`, `Money` and an `IdempotencyKey`, and its exceptions:

```python
# src/shop/order/cancel.py
IdempotencyKey = NewType("IdempotencyKey", str)


...


@dataclass(frozen=True, slots=True, kw_only=True)
class Refund:
    """Money going back to a payment, under a key the caller derives from the work."""

    payment_id: PaymentId
    amount: Money
    key: IdempotencyKey
```

`Refunder`, beside it, takes a `Refund` and returns nothing ([SKILL.md](../SKILL.md#pattern)). No `httpx2.Response`, vendor dict or request model appears in it. Once the vendor's names are in the signature, the Protocol is the SDK by another name, and replacing the vendor rewrites every caller.

## The adapter

```python
# src/shop/payments/gateway.py
@dataclass(frozen=True, slots=True, kw_only=True)
class Gateway:
    """The provider's refunds endpoint, as the order package's Refunder."""

    client: httpx2.Client
    retry: Retry

    def refund(self, refund: Refund) -> None:
        """Make the refund under its key, or raise PaymentFailed."""
        request = self.client.build_request(
            "POST",
            f"/payments/{refund.payment_id}/refunds",
            json={"amount": refund.amount.minor, "currency": refund.amount.currency},
            # The key makes the refund safe to send twice, so _send may retry it.
            headers={"Idempotency-Key": refund.key},
        )
        try:
            self._send(request)
        except httpx2.HTTPError as err:
            raise PaymentFailed(refund.payment_id) from err

    def _send(self, request: httpx2.Request) -> httpx2.Response:
        """Send request, and again while it fails in passing and the policy allows."""
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

- **The refund carries its idempotency key, derived by the caller from the work:** the canceller's is `cancel-{order_id}`. The provider refunds once per key, which makes the write safe to retry, and lets a cancel that stopped after its refund run again ([python-actions](../../python-actions/SKILL.md)).
- **No decisions.** Whether an order may be refunded is the order package's question. The adapter refunds.
- **The body is the wire's shape,** built here and nowhere else; a response worth reading is parsed here into domain types ([python-types](../../python-types/SKILL.md)).

## Timeouts

- **Every call has one, set on the client the wiring builds** from `Settings.gateway_timeout` ([seams.md](seams.md)). Left unset, httpx2 waits five seconds, a number nobody chose.
- **The timeout bounds each step of a call, never the call.** httpx2's `Timeout` covers `connect`, `read`, `write` and `pool`, each wait on its own, so a provider that trickles its answer, a byte inside every `read`, never trips it.
- **The policy bounds the retries, not an attempt.** `_send` checks `within` before each wait, but an attempt under way runs as long as its steps keep arriving.
- **A deadline on the whole call needs async code.** `asyncio.timeout` cancels the call wherever it waits; httpx2's sync client has nothing like it, and a thread can't be stopped from outside, so sync code that needs one moves the call to `httpx2.AsyncClient` ([python-concurrency](../../python-concurrency/SKILL.md)).

## Retries

The policy is a value, built in the wiring with its seams:

```python
# src/shop/payments/gateway.py
PASSING_STATUSES = (
    HTTPStatus.TOO_MANY_REQUESTS,
    HTTPStatus.BAD_GATEWAY,
    HTTPStatus.SERVICE_UNAVAILABLE,
    HTTPStatus.GATEWAY_TIMEOUT,
)


@dataclass(frozen=True, slots=True, kw_only=True)
class Retry:
    """How often, and for how long, a call that failed for a passing reason is made again."""

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
            # The server hung up mid-answer. Its sibling LocalProtocolError is our bug.
            return True
        case _:
            return False


def retry_after(response: httpx2.Response) -> float | None:
    """The seconds a Retry-After header asks for; None without one, or for a date."""
    value = response.headers.get("Retry-After", "")
    return float(value) if value.isdecimal() else None
```

- **An allow-list, never a deny-list.** Anything `passing` doesn't name, a 400, a 404, a 500, fails at once: the same request will fail the same way, and a 500 may have done half its work.
- **It stops on attempts or elapsed time,** whichever comes first, and never waits past its deadline: a `Retry-After` longer than the time left gives up now.
- **The backoff doubles and is jittered:** a random fraction of `first_wait × 2ⁿ⁻¹`, capped at `longest_wait`. Without jitter, clients that failed together retry together, and the provider's recovery meets the same spike.
- **`Retry-After` wins over the backoff** in its seconds form. Its HTTP-date form needs the wall clock, so it falls back to the backoff.
- **A write is retried only with an idempotency key.** Only a request safe to repeat reaches `_send`; a write without a key sends once, through the client.
- **Each retry is logged at WARNING** with the attempt, the wait and the cause, as a rising retry rate is the first sign of a failing provider. The error that ends the call gets a note, `gave up after 4 attempts`, and is logged once, where it stops ([python-errors](../../python-errors/SKILL.md)).

## A loop, not a library

The retry is twenty lines in the adapter, not a dependency. Weighed on 2026-10-14:

- **tenacity** (9.2.1): `@retry` fixes the policy, and the real `time.sleep`, at import. Its `Retrying` can be built in the wiring with `sleep=`, but the allow-list and `Retry-After` still need code written here, so it adds nothing the loop lacks.
- **stamina** (26.1.0, built on tenacity): it sleeps on its own. Tests turn waiting off with `stamina.set_testing(True)`, a module-level switch that also sets the attempt count, so no test can check the waits the policy chose.
- **The loop** is a value built in `main`, its every wait visible to a test, and one fewer dependency to review and upgrade ([python-project](../../python-project/SKILL.md)).

## Translating failures

- **`except httpx2.HTTPError as err: raise PaymentFailed(refund.payment_id) from err`.** `HTTPError` is the base of the transport and status errors. Callers branch on `PaymentFailed` and its `payment_id`, and never import httpx2; the cause stays on `__cause__` for the log. ruff's `B904` flags a raise in an `except` without `from`.
- **A failure a caller acts on differently gets its own subclass,** translated here: an unknown payment, a card the issuer refused. [python-errors](../../python-errors/SKILL.md) holds when one is worth it.

## Testing the adapter, and its callers

Against `httpx2.MockTransport`, so the test sees the request the provider would see and decides each answer. A handler that gives scripted answers in turn:

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
    ...
```

A retry policy gets three tests, a rule [python-testing](../../python-testing/SKILL.md) holds, with the tests themselves: it recovers after failures in passing, it stops at the cap, and it never retries a permanent failure. The adapter's tests add one each for honouring `Retry-After`, stopping at the deadline, and logging each retry. pytest-socket fails any test that opens a network socket, so a client built without the mock transport fails loudly.

Code that refunds is tested against `FakeRefunder` instead (the [SKILL.md](../SKILL.md#pattern) pattern), and never sees HTTP, so a change in the provider's API breaks the gateway's tests alone.

## Choosing in the wiring

With two implementations, the gateway and a stand-in for local runs that accepts every refund, the wiring picks one with a `match` on a `Literal` setting, ending `case _: assert_never(...)`. `Settings` rejects any other value at startup, so nothing runs with a default nobody picked. The stand-in isn't a test double: it never fails, where a fake records what its test asks about and fails when told to.

## SDKs

- **The adapter wraps the SDK's client;** the Protocol the domain sees is still yours, in your words.
- **When the SDK takes an `httpx2.Client`, its test passes one on `MockTransport`.**
- **Never declare a Protocol over the SDK's client to fake it, and never patch its methods.** The fake encodes your guess at the SDK's behaviour, and passes where the real SDK fails.
- **When the SDK takes no client,** keep the adapter thin enough that its check is a run against the vendor's sandbox, outside the unit tests.
- **The SDK's own retries are switched off,** or the adapter's are: one layer retries.
