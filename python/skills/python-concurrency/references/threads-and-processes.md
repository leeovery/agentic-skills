# Threads and Processes

**Threads overlap blocking calls; processes run Python in parallel. Each worker gets what it needs passed in and hands its result back, and anything they must share sits behind one named lock.**

Contents: which pool · a thread pool for blocking calls · a process pool for CPU · start methods · locks · `asyncio.Lock` and threads · free-threaded builds.

## Which pool

| The work | Run it on |
|---|---|
| Blocking calls from sync code: a sync HTTP client, files, a sync SDK | a `ThreadPoolExecutor` |
| A blocking call from async code | `asyncio.to_thread`, in [asyncio.md](asyncio.md) |
| Hundreds of concurrent waits | asyncio, in [asyncio.md](asyncio.md) |
| Python computation: parsing, totalling, transforming | a `ProcessPoolExecutor` |
| C code that releases the GIL, such as `hashlib` on large inputs | threads are enough |

In the default build only one thread runs Python at a time. Threads help while they wait on the network or the disk, and do nothing for a loop that computes; that takes processes.

## A thread pool for blocking calls

```python
# src/shop/payments/refunds.py
from shop.payments.limits import REFUNDS_AT_ONCE

...


def refund_all(refunder: Refunder, refunds: Iterable[Refund]) -> list[Refund]:
    """Make each refund, REFUNDS_AT_ONCE at a time, and return those that failed."""
    with ThreadPoolExecutor(
        max_workers=REFUNDS_AT_ONCE, thread_name_prefix="refund"
    ) as pool:
        outcomes = [
            (refund, pool.submit(copy_context().run, _refunded, refunder, refund))
            for refund in refunds
        ]
    return [refund for refund, ok in outcomes if not ok.result()]


def _refunded(refunder: Refunder, refund: Refund) -> bool:
    try:
        refunder.refund(refund)
    except PaymentFailed:
        logger.warning("refund of payment %s failed", refund.payment_id, exc_info=True)
        return False
    return True
```

- **The `with` owns the threads.** Leaving it waits for every call, so nothing outlives `refund_all`.
- **Each call returns its result through its future,** read once the pool has closed. Nothing is shared between the threads, so nothing needs a lock.
- **A pool's threads don't copy the caller's context.** A `ContextVar` the caller set, such as the correlation id, isn't set in the worker thread. `copy_context().run` carries it across, a copy per call, as one `Context` can't be entered on two threads at once. `asyncio.to_thread` makes that copy itself; `loop.run_in_executor` doesn't.
- **The expected failure is caught inside the call,** as a `TaskGroup` task's is. `future.result()` re-raises anything else, a bug, in the caller.
- **What the threads share must be safe to share.** `httpx2.Client` says it can be shared between threads. A `sqlite3.Connection` can't: it raises `ProgrammingError` when used from a thread other than the one that made it. Each thread opens its own ([python-protocols](../../python-protocols/SKILL.md)).
- **A running call can't be stopped from outside.** Its client's timeout bounds each step of it, not the call: a provider that trickles its answer holds the call, and the pool's close, for as long as it trickles. A call that needs a deadline on the whole of it goes through async code, under `asyncio.timeout` ([asyncio.md](asyncio.md#deadlines)).
- **The size has a reason, and one owner.** `refund_all` lives with the adapter, as the provider's connections are its limit, not the domain's; `REFUNDS_AT_ONCE` is split from them in one place ([asyncio.md](asyncio.md#bounded-fan-out)).

## A process pool for CPU

```python
# src/shop/payments/reports.py
class ReportRow(BaseModel):
    """One row of a settlement report: a payment, its currency and the amount."""

    # Lax, unlike a model fed JSON: a CSV holds only strings, the amount among them.
    model_config = ConfigDict(extra="forbid", frozen=True)

    payment_id: str
    currency: Currency
    minor: int


...


def totals(reports: Sequence[Path]) -> Counter[Currency]:
    """The minor units settled in each currency, across the reports."""
    settled = Counter[Currency]()
    with ProcessPoolExecutor() as pool:
        for one_report in pool.map(report_totals, reports):
            settled.update(one_report)
    return settled


def report_totals(report: Path) -> Counter[Currency]:
    """The minor units settled in each currency in one report."""
    settled = Counter[Currency]()
    with report.open(encoding="utf-8", newline="") as rows:
        for payment_id, currency, minor in csv.reader(rows):
            row = ReportRow.model_validate(
                {"payment_id": payment_id, "currency": currency, "minor": minor}
            )
            settled[row.currency] += row.minor
    return settled
```

- **The function a worker runs is at module level.** It's sent by name and imported in the worker; a lambda, a nested function or a method of an object that can't be pickled fails.
- **Arguments, results and exceptions are pickled.** Pass a path, not an open file or a connection; return plain values. A `ValidationError` raised in the worker is raised again in the caller.
- **Each row is parsed where it's read,** into the domain's `Currency`, so the totals are keyed by the type every other module uses ([python-types](../../python-types/SKILL.md)).
- **State is passed in:** as arguments, or with `initializer=` and `initargs=` for something each worker builds once, such as its logging.
- **A process costs an interpreter's start-up and an import of the program.** It pays for work measured in seconds; for less, measure first ([python-testing](../../python-testing/SKILL.md)).
- **The pool's size defaults to `os.process_cpu_count()`,** the CPUs this process may use, which is the right number for CPU-bound work.
- **Coverage misses what runs in a worker** unless coverage measures processes, as [python-testing](../../python-testing/SKILL.md) describes.

## Start methods

A worker process starts from a fresh interpreter, so it has none of the parent's runtime state: no global `main` set, no configured logger, no open connection.

| Platform | Default start method |
|---|---|
| Linux and other Unix | `forkserver`, since [3.14](https://docs.python.org/3/whatsnew/3.14.html); it was `fork` |
| macOS | `spawn` |
| Windows | `spawn` |

- **Code that leaned on `fork`** to copy a global into the workers breaks on 3.14's Linux default. Pass the value in.
- **Both methods import the main module in each worker,** so a module that starts a pool at import starts one per worker. Pools start from functions that `main` calls, never at import ([python-architecture](../../python-architecture/SKILL.md)).
- **Don't ask for `fork`.** It copies a process that has threads (a pool's, a client's) with their locks as they were at that instant, and a lock held then is held for good in the child. POSIX doesn't support it, and since [3.12](https://docs.python.org/3/whatsnew/3.12.html) Python raises a `DeprecationWarning` when it can see the threads.

## Locks

Share as little as you can: a pool that returns results needs none. State that several threads must update, such as a running total a reporter reads, sits behind a lock:

```python
# src/shop/payments/refunds.py
@dataclass(slots=True)
class Tally:
    """What the refund threads have paid back so far, in each currency."""

    lock: threading.Lock = field(default_factory=threading.Lock)  # guards minor
    minor: Counter[Currency] = field(default_factory=Counter[Currency])

    def add(self, amount: Money) -> None:
        """Count amount as refunded."""
        with self.lock:
            self.minor[amount.currency] += amount.minor

    def totals(self) -> dict[Currency, int]:
        """A copy of the totals so far, taken under the lock."""
        with self.lock:
            return dict(self.minor)

    def report(self) -> None:
        """Log the totals so far, after the lock is released."""
        logger.info("refunded so far, in minor units: %s", self.totals())
```

- **A named field, declared beside the data it guards,** with a comment saying which. Taken with `with`, never `acquire()` and a `release()` further down.
- **Memory work only under it.** `totals` copies under the lock; `report` logs after. A slow log handler, an HTTP call or a file write under the lock makes every other thread wait on it.
- **The GIL doesn't make an update one step.** `+=` on a dict entry reads, adds and writes, and a thread can switch between them.
- **Nothing finds a data race in Python.** There's no race detector, and a test rarely loses the race. Review checks every field written from more than one thread.

## `asyncio.Lock` and threads

`asyncio.Lock`, `Semaphore`, `Event` and `Queue` order tasks on one event loop, and aren't thread-safe. From another thread, hand work to the loop with `loop.call_soon_threadsafe(...)` or `asyncio.run_coroutine_threadsafe(coro, loop)`. The reverse also holds: a `threading.Lock` taken inside a coroutine blocks the whole loop while it waits.

## Free-threaded builds

[3.14](https://docs.python.org/3/whatsnew/3.14.html) supports a build without the GIL (`python3.14t`, [PEP 779](https://peps.python.org/pep-0779/)), but it's optional, a C extension must declare that it supports it, and single-threaded code runs a little slower on it. Code here assumes the default build: parallel computation goes to processes. Nor does it lean on the GIL for correctness, so the locks above are right on either build.
