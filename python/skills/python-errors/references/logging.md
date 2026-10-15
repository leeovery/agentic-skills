# Logging

**The standard library's `logging`, configured once in the wiring `main` supplies; every module names its own logger, and every line is a constant message with its values beside it.**

Contents: configuring it · a logger per module · what a library adds · messages · levels · where to log · tracebacks · secrets · a correlation id · in tests.

## Configuring it

Once, in the wiring `main` supplies, from the settings:

```python
# src/shop/cli.py, in from_environment
settings = read_settings()
stderr = logging.StreamHandler()
stderr.addFilter(CorrelationFilter())
logging.basicConfig(
    level=settings.log_level,
    format="%(levelname)s %(name)s [%(correlation_id)s]: %(message)s",
    handlers=[stderr],
)
```

- **The handler writes to stderr,** so log lines never mix with the data a command writes to stdout ([python-actions](../../python-actions/SKILL.md)).
- **`basicConfig` does nothing when the root logger already has handlers,** unless given `force=True`. Needing `force` means something configured logging before `main` did.
- **A larger setup is one `logging.config.dictConfig(...)` call,** in the same place, under the same rule.
- **Nowhere else.** A module that calls `basicConfig`, `addHandler` or `setLevel`, or sets a logger's `propagate`, configures logging for every program that imports it. The source guard in [python-quality](../../python-quality/SKILL.md) fails on each one outside the module that holds `main`.

## A logger per module

```python
# src/shop/order/cancel.py
logger = logging.getLogger(__name__)
```

- **At the top of each module that logs,** after the imports. It's a name, not configuration: the logger exists before `main` runs, and takes its handlers and level from the root once `main` configures it. The import-time guard counts `logging.getLogger` as a declaration.
- **The name is the module's path,** so the configuration can reach a part of the program: `shop.payments` at DEBUG, the rest at INFO.
- **Not injected.** logging's configuration is process-wide by design, and `caplog` captures each test's lines without anything passed in.
- **Never the root logger:** `logging.info(...)` (ruff's `LOG015`), `logging.getLogger()` with no name, or `getLogger(__file__)` (`LOG002`). Never `logging.Logger(...)` built by hand (`LOG001`): it belongs to no hierarchy, so no configuration reaches it.

## What a library adds

The [Logging HOWTO](https://docs.python.org/3/howto/logging.html#configuring-logging-for-a-library) says it plainly:

- **A library adds no handler other than a `NullHandler`.** Handlers are the application's choice.
- **A `NullHandler` is optional.** With no configuration at all, logging prints WARNING and above to stderr, which the HOWTO calls the best default; a `NullHandler` exists only to silence that.
- **A library never logs to the root logger,** and documents the names of the loggers it uses.

So a library here adds nothing: no handler, no level, only `logger = logging.getLogger(__name__)` in each module.

## Messages

- **The message is a constant naming the event; the values are `%`-style arguments,** formatted only if the line is emitted:

```python
# src/shop/order/cancel.py, in Canceller.cancel
logger.info("order %s cancelled, reason %s", order_id, reason)
```

- **`extra` is for fields a structured formatter writes as keys of their own.** The text format above prints only the message, so until `main`'s formatter writes `extra`, every value the line needs is an argument.
- **ruff's `G` rules hold the shape:** `G004` an f-string, `G001` `str.format`, `G003` `+`, `G002` the `%` operator, and `G101` an `extra` key that clashes with a record's own attributes.
- **One name per thing, everywhere:** the order is `order_id` in every module.

## Levels

| Level | For | Such as |
|---|---|---|
| ERROR | a failure someone must act on | an unexpected exception at the edge, data that couldn't be saved |
| WARNING | degraded, carrying on | a call retried, a fallback served |
| INFO | what happened in normal running | an order cancelled, a sweep's skipped order |
| DEBUG | detail for diagnosis, off by default | a request's timing |

An order that can't be cancelled is an outcome the user is told about, not a failure of the program. Logged at ERROR, it buries the lines that need someone.

## Where to log

Where an exception stops, and nowhere it passes through ([exceptions.md](exceptions.md)):

- **The edge's last resort:** `run` logs an unexpected exception once, with its traceback, before it returns 1.
- **Code that absorbs a failure and carries on:** the gateway logs each retry at WARNING; a loop that skips an item logs the item it skipped.
- **Not code that re-raises,** nor an expected failure the edge already reports.

## Tracebacks

- **`logger.exception(...)` inside the `except` where the exception stops.** It logs at ERROR with the traceback. ruff's `LOG004` flags it outside an `except`, where it has no exception to log, and `TRY400` flags `logger.error` inside one, which drops the traceback.
- **Don't put the exception in the message as well:** `logger.exception("refund failed: %s", err)` prints it twice (`TRY401`).
- **A traceback below ERROR** goes with `exc_info=True`: `logger.warning("served the cached rates", exc_info=True)`.
- **Notes print with the traceback,** so context added with `add_note` reaches the log with no extra code.

## Secrets

- **Never log a whole request, header map, `Settings` object or the environment.** Log the fields you need. A secret is a type that redacts itself ([python-types](../../python-types/SKILL.md)): `SecretStr` prints as `**********`.
- **httpx2's errors carry the URL, query and all,** so credentials travel in a header, never in the query string.

## A correlation id

One id for a piece of work, on every line it causes, so a log reader can pull out one run, request or message from many. It lives in a `ContextVar`, which a logging filter copies onto each record:

```python
# src/shop/correlation.py
CORRELATION_ID: ContextVar[str] = ContextVar("correlation_id", default="-")


class CorrelationFilter(logging.Filter):
    """Stamps each record with the correlation id of the work that logged it."""

    @override
    def filter(self, record: logging.LogRecord) -> bool:
        record.correlation_id = CORRELATION_ID.get()
        return True
```

```python
# src/shop/cli.py
def main(argv: Sequence[str] | None = None) -> int:
    """Run the command line on argv, or on the process's own arguments."""
    with CORRELATION_ID.set(str(uuid.uuid7())):
        return run(build_app(from_environment), argv)
```

- **The filter goes on the handler,** as the wiring above does. A filter on a logger sees only the records logged through that logger, not its children's, so one on the root would miss every module's lines.
- **The token `set` returns resets the variable when its `with` ends** ([3.14](https://docs.python.org/3/whatsnew/3.14.html)), so no id outlives its work.
- **The `ContextVar` is declared at module level,** as the `contextvars` docs require; the import-time guard counts `ContextVar` as a declaration.
- **asyncio copies the context into each task it creates,** so a task logs with the id of the work that started it, and `asyncio.to_thread` copies it into its thread. **A thread pool doesn't:** run each call in a copy, `pool.submit(copy_context().run, ...)` ([python-concurrency](../../python-concurrency/SKILL.md)).
- **A server or a worker sets it per request or message,** from the caller's header when there is one, minted with `uuid.uuid7()` when not, and sends it on in a header on outbound calls, so another system's logs can be matched to these.
- A line logged with no id set shows the default, `-`.

## In tests

pytest's `caplog` holds the records each test logged. Check the level and the message, not the formatted line:

```python
# tests/payments/test_gateway.py
def test_each_retry_is_logged_as_a_warning(caplog: pytest.LogCaptureFixture) -> None:
    handler, _ = answers(httpx2.Response(502), httpx2.Response(201))

    with gateway(handler) as payments:
        payments.refund(REFUND)

    [record] = caplog.records
    assert record.levelname == "WARNING"
    assert record.getMessage().startswith(
        "retrying POST /payments/pay-1/refunds in 0.5s, after attempt 1:"
    )
```

`answers`, `gateway` and `REFUND` are the gateway tests' own ([python-protocols](../../python-protocols/SKILL.md)).

- **The root logger stops at WARNING** until something sets it lower. A test of an INFO line calls `caplog.set_level(logging.INFO)` first.
- **A logged traceback is checked as `record.exc_info is not None`.**
- **A filter is tested on `caplog.handler`,** and the record through a `logging.Formatter` that uses its field.
- Most tests don't look at logs at all. A line is checked when logging it is the behaviour under test: a retry absorbed, a failure skipped.
