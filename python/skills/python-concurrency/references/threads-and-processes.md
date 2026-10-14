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
# src/shop/order/refunds.py
# The provider takes ten connections per token; two stay free for other calls.
AT_ONCE = 8


def refund_all(
    refunder: Refunder, refunds: Mapping[PaymentId, Money]
) -> list[PaymentId]:
    """Refund each payment its amount, eight at a time, and return those that failed."""
    with ThreadPoolExecutor(max_workers=AT_ONCE, thread_name_prefix="refund") as pool:
        outcomes = {
            payment_id: pool.submit(_refunded, refunder, payment_id, amount)
            for payment_id, amount in refunds.items()
        }
    return [payment_id for payment_id, ok in outcomes.items() if not ok.result()]


def _refunded(refunder: Refunder, payment_id: PaymentId, amount: Money) -> bool:
    try:
        refunder.refund(payment_id, amount)
    except PaymentFailed:
        logger.warning("refund failed", extra={"payment_id": payment_id}, exc_info=True)
        return False
    return True
```

- **The `with` owns the threads.** Leaving it waits for every call, so nothing outlives `refund_all`.
- **Each call returns its result through its future,** read once the pool has closed. Nothing is shared between the threads, so nothing needs a lock.
- **The expected failure is caught inside the call,** as a `TaskGroup` task's is. `future.result()` re-raises anything else, a bug, in the caller.
- **What the threads share must be safe to share.** `httpx2.Client` says it can be shared between threads. A `sqlite3.Connection` can't: it raises `ProgrammingError` when used from a thread other than the one that made it.
- **A running call can't be stopped from outside.** Each one is bounded by its client's timeout, so the pool's close is bounded too.
- **The size has a reason,** written beside it.

## A process pool for CPU

```python
# src/shop/payments/reports.py
def totals(reports: Sequence[Path]) -> Counter[str]:
    """The minor units settled in each currency, across the reports.

    Parsing is CPU-bound, so each report is read in a process of its own.
    """
    settled = Counter[str]()
    with ProcessPoolExecutor() as pool:
        for one_report in pool.map(report_totals, reports):
            settled.update(one_report)
    return settled


def report_totals(report: Path) -> Counter[str]:
    """The minor units settled in each currency in one report.

    At module level, so a worker process can import it by name.
    """
    settled = Counter[str]()
    with report.open(encoding="utf-8", newline="") as rows:
        for _, currency, minor in csv.reader(rows):
            settled[currency] += int(minor)
    return settled
```

- **The function a worker runs is at module level.** It's sent by name and imported in the worker; a lambda, a nested function or a method of an object that can't be pickled fails.
- **Arguments and results are pickled.** Pass a path, not an open file or a connection; return plain values.
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
# src/shop/order/refunds.py
@dataclass(slots=True)
class Tally:
    """What the refund threads have paid back so far, in each currency."""

    lock: threading.Lock = field(default_factory=threading.Lock)  # guards minor
    minor: Counter[str] = field(default_factory=Counter[str])

    def add(self, amount: Money) -> None:
        """Count amount as refunded."""
        with self.lock:
            # A read, an add and a write: two threads between them can lose one.
            self.minor[amount.currency] += amount.minor

    def totals(self) -> dict[str, int]:
        """A copy of the totals so far, taken under the lock."""
        with self.lock:
            return dict(self.minor)

    def report(self) -> None:
        """Log the totals so far, after the lock is released."""
        logger.info("refunded so far", extra={"minor": self.totals()})
```

- **A named field, declared beside the data it guards,** with a comment saying which. Taken with `with`, never `acquire()` and a `release()` further down.
- **Memory work only under it.** `totals` copies under the lock; `report` logs after. A log handler writing to a slow sink, an HTTP call or a file write under the lock makes every other thread wait on it.
- **The GIL doesn't make an update one step.** `+=` on a dict entry reads, adds and writes, and a thread can switch between them.
- **Nothing finds a data race in Python.** There's no race detector, and a test rarely loses the race, so a test that passes proves nothing about a missing lock. Review checks every field written from more than one thread.

## `asyncio.Lock` and threads

`asyncio.Lock`, `Semaphore`, `Event` and `Queue` order tasks on one event loop, and aren't thread-safe. From another thread, hand work to the loop with `loop.call_soon_threadsafe(...)` or `asyncio.run_coroutine_threadsafe(coro, loop)`. The reverse also holds: a `threading.Lock` taken inside a coroutine blocks the whole loop while it waits.

## Free-threaded builds

[3.14](https://docs.python.org/3/whatsnew/3.14.html) supports a build without the GIL (`python3.14t`, [PEP 779](https://peps.python.org/pep-0779/)), but it's optional, a C extension must declare that it supports it, and single-threaded code runs a little slower on it. Code here assumes the default build: parallel computation goes to processes. Nor does it lean on the GIL for correctness, so the locks above are right on either build.
