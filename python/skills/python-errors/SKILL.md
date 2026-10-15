---
name: python-errors
description: Python exceptions raised with what the traceback lacks, handled once, translated at boundaries and mapped to an exit code at the edge, with stdlib logging configured once in main. Use when raising, catching, translating or reporting an exception, defining an exception class, or adding a logger, a log line or logging config.
---

# Python Errors

An exception is handled once: raised with what the traceback lacks, caught where something acts on it, or logged where it stops.

## Core rules

1. **Exceptions are the error channel.** No `Result` types, and no `None` for a failure the caller needs to tell apart. *A `None` for "no such order" and one for "the database was down" read the same, and a `Result` is a second channel every caller must check beside the exceptions that still come.*
2. **Handle an exception once.** Let it rise, or catch it where something acts on it: retry, fall back, map it to an answer, or log it where it stops. Never log it and re-raise. Catch the narrowest type, around the one call that raises it. *Logged and re-raised, it shows up once per layer with its traceback each time, and the caller loses the choice of what it means.* Held by ruff's `E722` (a bare `except`), `BLE001` (an `except Exception` that neither re-raises nor logs the traceback), `S110` (`except: pass`) and `TRY203` (an `except` that only re-raises).
3. **A package has one base exception.** A subclass exists only where a caller branches on it, and carries what it branches on as attributes (`order_id`, `status`, `payment_id`), never only in its message. *A caller that parses a message breaks when the wording changes, and a subclass per failure is a list nobody reads.* `TRY002` flags raising a bare `Exception`.
4. **Translate at a boundary you own: `raise PaymentFailed(payment_id) from err`.** An adapter or a store turns its library's exception into the domain's; `from None` only where hiding the cause is deliberate. *Otherwise every caller imports the library to catch it, and replacing it breaks them all; `from` keeps the cause for whoever reads the traceback.* Held by ruff's `B904`.
5. **Context the traceback lacks goes on with `err.add_note(...)`** ([3.11](https://docs.python.org/3/whatsnew/3.11.html)), and the exception rises with its type unchanged. *A new exception per layer, to say "while reading order o-1", buries the original type and makes every caller catch the wrapper.*
6. **Independent failures are reported together.** Validation collects every problem into one exception. Independent operations that each fail raise an `ExceptionGroup`, which a caller takes apart with `except*`. *Reporting one problem per run turns three mistakes into three runs, and stopping at the first failure leaves the rest untried.*
7. **An exception becomes an exit code or a status in one function per edge.** Domain exceptions carry no HTTP status or exit code. `except Exception` appears only there, as the edge's last resort, and in a loop that must outlive one failed run, as the poller's; each logs the traceback. *The domain stays free of transports, each edge's mapping is one table to read, and a bug reaches the one place that reports it whole.* [python-actions](../python-actions/SKILL.md) holds the CLI's codes.
8. **`assert` only in tests.** `python -O` strips every `assert` statement. *A check that vanishes under a flag isn't a check: raise instead.* Held by ruff's `S101`, lifted for `tests/`.
9. **Logging is configured once, in the wiring `main` supplies; a module only names its logger,** `logger = logging.getLogger(__name__)`. A library adds no handler. Nothing logs through the root logger. *Configuration in a module runs for whoever imports it and fights the application's own, and a module logger is a name the application's configuration can reach.* Held by ruff's `LOG015` (a root-logger call), `LOG001` and `LOG002`, and a source guard on each call or setting that configures logging, from `basicConfig` to `setLevel` and `propagate`, in [python-quality](../python-quality/SKILL.md).
10. **A log line is a constant message with its values as `%`-style arguments or in `extra`, never an f-string.** `logger.exception` only where the exception stops. ERROR is for what someone must act on; an expected outcome, such as an order that can't be cancelled, is INFO. No `print` for diagnostics. *A constant message is one string to search for, its values are formatted only if the line is emitted, and an ERROR stays worth reading.* Held by ruff's `G`, `TRY400`, `TRY401`, `LOG004` and `T201`.

## Pattern

```python
# src/shop/order/errors.py
class OrderError(Exception):
    """The base of every exception the order package raises."""


...


class NotCancellable(OrderError):
    """The order's status forbids cancelling it."""

    def __init__(self, order_id: OrderId, status: Status) -> None:
        super().__init__(f"order {order_id} is {status}, so it can't be cancelled")
        self.order_id = order_id
        self.status = status
```

The adapter translates at its boundary, and the edge maps what reaches it, in one place:

```python
# src/shop/payments/gateway.py, in Gateway.refund
try:
    self._send(request)
except httpx2.HTTPError as err:
    raise PaymentFailed(refund.payment_id) from err
```

```python
# src/shop/cli.py
def run(app: typer.Typer, argv: Sequence[str] | None) -> int:
    """Run app on argv, and return the exit code for how it ended.

    0 for success, 1 for a failure, 2 for a usage error, 130 for an interrupt.
    """
    try:
        code = app(args=argv, prog_name="shop", standalone_mode=False)
    except typer.TyperException as err:
        ...
    except typer.Abort:
        # A prompt declined, or its input closed with Ctrl-D.
        typer.echo("shop: aborted", err=True)
        return 1
    except (SettingsError, OrderError, SettlementError) as err:
        typer.echo(f"shop: {_said(err)}", err=True)
        return 1
    except Exception:
        logger.exception("shop failed")
        return 1
    ...
```

A `SettingsError`, an `OrderError` or a `SettlementError` is an outcome the user reads, printed once with its notes and no traceback. Anything else is a bug, logged once with its traceback: a pydantic `ValidationError` too, as the wiring turns the settings' into `SettingsError` and an edge turns its input's into an answer of its own. So an outcome the user must be told is a domain exception, as a reorder with nothing still on sale raises `OrderError`; a builtin such as `ValueError` is for a bug, or a value refusing its own rule.

## Decision table

| The caller | Raise |
|---|---|
| is told an outcome, and only reports it | the package's base exception, with a message |
| meets a bug, or a value refusing its own rule | a builtin: `ValueError`, `TypeError` |
| branches on which failure it was | a subclass of the package's base |
| needs data from it | that subclass, with the data as attributes |
| sits past a library you wrap | your exception, `from` the library's |
| needs context the traceback lacks | the same exception, after `err.add_note(...)` |
| gets several problems with one input | one exception carrying them all |
| gets the failures of independent operations | an `ExceptionGroup`, taken apart with `except*` |

## Anti-patterns

- ❌ `except Exception as err: logger.error(err); raise` → let it rise; whoever stops it logs it.
- ❌ `except httpx2.HTTPError: raise PaymentFailed(payment_id)` → `raise PaymentFailed(payment_id) from err`.
- ❌ `def get(order_id: OrderId) -> Order | None`, where `None` also means the query failed → raise `OrderNotFound`, and let the failure rise.
- ❌ `if "can't be cancelled" in str(err):` → `except NotCancellable as err:`, then `err.status`.
- ❌ `class OrderNotFound(OrderError): status_code = 404` → the edge's mapping function.
- ❌ `raise OrderError(f"refund failed: {err}")` in a new class per layer → `err.add_note(...)` and `raise`.
- ❌ `except: pass`, or `contextlib.suppress(Exception)` → name the exception, or use the API's own option: `path.unlink(missing_ok=True)`.
- ❌ `assert order.lines` in `src/` → `if not order.lines: raise ValueError("an order has at least one line")`.
- ❌ `logger.info(f"order {order_id} cancelled")` → `logger.info("order %s cancelled, reason %s", order_id, reason)`.
- ❌ `logging.basicConfig(...)` in a module, or `logging.info(...)` → configure in `main`; log through the module's `logger`.
- ❌ `print(f"error: {err}")` → raise, and the edge reports it.

## References

- **[exceptions.md](references/exceptions.md)** — read when raising, catching, translating or reporting an exception: one base per package, `from` and `from None`, notes, every problem at once, groups and `except*`, the edge's mapping, interrupts and cancellation, `suppress`, `assert`, and checking exceptions in tests.
- **[logging.md](references/logging.md)** — read when adding a logger or a log line, or configuring logging: the wiring, module loggers, what a library adds, messages and `extra`, levels, where to log, tracebacks, secrets, a correlation id, and `caplog`.

## Related skills

- **[python-actions](../python-actions/SKILL.md)** — the edges, `run`, and the exit codes it returns.
- **[python-protocols](../python-protocols/SKILL.md)** — the adapters and stores that translate a library's exceptions, and the retries that absorb a failure in passing.
- **[python-concurrency](../python-concurrency/SKILL.md)** — cancellation, and the groups a `TaskGroup` raises.
- **[python-types](../python-types/SKILL.md)** — boundary models that report every bad field at once, and secrets that redact themselves.
- **[python-testing](../python-testing/SKILL.md)** — checking exceptions by type and attributes, and log lines with `caplog`.
- **[python-quality](../python-quality/SKILL.md)** — the ruff rules and the logging guard behind these rules.
