---
name: python-concurrency
description: Python concurrency where every task and resource has an owner — sync by default, asyncio entered once, TaskGroup, deadlines, cancellation, threads, processes, with. Use when writing async code or deciding whether to, starting tasks or workers, adding a timeout, running work in parallel, sharing state across threads, or opening a file, client or lock.
---

# Python Concurrency

Concurrency is the caller's choice, and every task and resource has an owner that ends it.

## Core rules

1. **Write sync code unless the program waits on many things at once.** A command that makes a few calls in turn is sync; one that makes hundreds of independent calls, or a long-lived program holding many connections, is async. A call path is wholly one or the other: no blocking call inside `async def`, and no `asyncio.run` below the top. *Async changes the signature of every caller, and one blocking call in a coroutine stalls every task on the loop.* Held by ruff's `ASYNC` rules, which flag `time.sleep`, `open`, `pathlib` and subprocess calls inside `async def`. They don't know httpx2, so a sync `httpx2.Client` in a coroutine is review's to catch.
2. **Enter asyncio once, with `asyncio.run`, in the composition root,** and open async clients inside the coroutine it runs. Inside a coroutine, `asyncio.get_running_loop()`; never `get_event_loop()` or a loop built by hand. *`asyncio.run` refuses to start inside a running loop, so a function that calls it can't be called from async code; an `AsyncClient` is closed by an `await`, which only the loop that used it can run; and `get_event_loop()` raises on 3.14 when no loop is running.* Held by the drop-in's ban list (`TID251`), for `get_event_loop`.
3. **Every task has an owner that awaits it.** Work that belongs together runs in a `TaskGroup` ([3.11](https://docs.python.org/3/whatsnew/3.11.html)). A long-lived task is held by the code that started it, then cancelled and awaited when that code stops. Never a bare `create_task`, and never `gather`. *The loop holds a task only weakly, so an unheld one can be collected mid-run with its exception unseen; `gather` leaves the rest running when one fails.* Held by ruff's `RUF006`.
4. **Every wait has a deadline:** `async with asyncio.timeout(...)` around the whole operation, and a timeout on every client as well. Never `wait_for`, which is `timeout` around one awaitable. A coroutine never takes a `timeout` parameter: its caller wraps the call. *A client's timeout bounds each read, so a server that trickles bytes never trips it; only a deadline on the whole call ends that wait.* Held by ruff's `ASYNC109`.
5. **`CancelledError` is re-raised after cleanup, never swallowed.** Clean up in `finally`; `except Exception` already lets it through, as it's a `BaseException`. *Cancellation is how deadlines and shutdown end a task. Swallowed, the `asyncio.timeout` above it ends with no error, and its caller takes a call that never finished for one that did.*
6. **Fan-out is bounded:** a `Semaphore` when the work is in hand, or a fixed set of workers over a bounded `Queue`, stopped with `Queue.shutdown()` ([3.13](https://docs.python.org/3/whatsnew/3.13.html)), never with sentinels. *Unbounded, ten thousand ids open ten thousand connections, and the provider's rate limit fails them all.*
7. **Blocking work you can't make async runs in `asyncio.to_thread`; CPU-bound work runs in a `ProcessPoolExecutor`, with its state passed in.** Blocking calls in sync code go through a `ThreadPoolExecutor`, each returning its result rather than writing to shared state. *A thread can't run Python in parallel with another, and a worker process starts from a fresh interpreter under spawn (macOS) and forkserver (the default elsewhere on Unix since [3.14](https://docs.python.org/3/whatsnew/3.14.html)), so a global the parent set isn't there.*
8. **Every resource is owned by a `with` or `async with`:** files, connections, clients, pools, locks. A number known only at runtime goes on an `ExitStack` or `AsyncExitStack`. A context manager you write cleans up in `finally`, and suppresses only the exceptions it names. *Without a `with`, the first exception skips the close. In a `@contextmanager`, the body's exception is raised at the `yield`, so cleanup after a bare `yield` is skipped whenever the body fails.* Held by ruff's `SIM115`, for files.
9. **A lock is a named field beside the data it guards, held for work on memory only:** copy under it, then do the I/O. `asyncio.Lock` orders tasks on one loop and is no guard between threads. *A lock held across a request makes every caller wait on the network, and a compound update made without one loses writes that no test or tool reports.*
10. **Shutdown runs in reverse order of start, within a deadline:** stop taking work, let the workers finish what they hold, then close what they write to. One `AsyncExitStack`, or nested `with`, gives the order. *Out of order, work arrives after its worker has gone; with no deadline, one stuck call holds the process open.*

## Pattern

```python
# src/shop/settlement/settle.py
@dataclass(frozen=True, slots=True, kw_only=True)
class Settler:
    """Settles payments with the provider, at most `limit` at once.

    `deadline` bounds each payment's whole call, however slowly the provider answers.
    """

    provider: Provider
    limit: int
    deadline: timedelta

    async def settle(self, payment_ids: Iterable[PaymentId]) -> list[PaymentId]:
        """Settle every payment, and return those that failed or ran out of time."""
        gate = asyncio.Semaphore(self.limit)
        async with asyncio.TaskGroup() as tg:
            tasks = {
                payment_id: tg.create_task(self._settle(payment_id, gate))
                for payment_id in payment_ids
            }
        return [payment_id for payment_id, task in tasks.items() if not task.result()]

    async def _settle(self, payment_id: PaymentId, gate: asyncio.Semaphore) -> bool:
        async with gate:
            return await self._settled(payment_id)

    async def _settled(self, payment_id: PaymentId) -> bool:
        try:
            async with asyncio.timeout(self.deadline.total_seconds()):
                await self.provider.settle(payment_id)
        except SettlementFailed, TimeoutError:
            logger.warning(
                "payment not settled", extra={"payment_id": payment_id}, exc_info=True
            )
            return False
        return True
```

Each task catches the failures it expects, so the group raises only for a bug, and then as an `ExceptionGroup`. `except SettlementFailed, TimeoutError:` is [3.14](https://docs.python.org/3/whatsnew/3.14.html)'s form without brackets. The composition root enters asyncio once, `asyncio.run(_settle(settings, payment_ids))`, and opens the `AsyncClient` inside it: [asyncio.md](references/asyncio.md) has it whole.

## Decision table

| Need | Reach for |
|---|---|
| A few calls, one after another | sync code and `httpx2.Client` |
| Many independent calls from a command | one `asyncio.run` over a `TaskGroup` and a `Semaphore` |
| A stream of work, or a consumer that runs until stopped | workers over a bounded `asyncio.Queue`, ended by `shutdown()` |
| A job on an interval beside other work | a task held by its owner, cancelled and awaited on exit |
| A deadline on a call or a block of calls | `asyncio.timeout` |
| A blocking library inside async code | `asyncio.to_thread` |
| Tens of blocking calls in sync code | `ThreadPoolExecutor`, each result returned |
| CPU-bound work | `ProcessPoolExecutor`, at module level, its state passed in |
| A file, client, connection or lock | `with` or `async with`; `ExitStack` when the number is known only at runtime |
| A total several threads update | a `threading.Lock` field beside it |

## Anti-patterns

- ❌ `async def` throughout a CLI that makes three calls → sync functions and `httpx2.Client`.
- ❌ `asyncio.run(...)` inside a library function or a method → make it `async def`; one `asyncio.run` at the top.
- ❌ `loop = asyncio.get_event_loop()` → `asyncio.run(main())`, and `asyncio.get_running_loop()` inside a coroutine.
- ❌ `asyncio.create_task(send_receipt(order))` with nothing holding the task → a `TaskGroup`, or a held task its owner cancels and awaits.
- ❌ `await asyncio.gather(*(settle(p) for p in ids))` → a `TaskGroup` and a `Semaphore`.
- ❌ `await asyncio.wait_for(call(), 5)`, or `async def fetch(timeout: float)` → `async with asyncio.timeout(5): await call()`.
- ❌ `except asyncio.CancelledError: pass`, or `except BaseException:` to log and carry on → cleanup in `finally`, and let it rise.
- ❌ A `None` put on the queue for each worker → `queue.shutdown()`.
- ❌ `httpx2.Client` or `requests` inside `async def` → `httpx2.AsyncClient`, or `asyncio.to_thread` for a library you can't replace.
- ❌ A `@contextmanager` with cleanup after a bare `yield` → `try: yield` then `finally:`.
- ❌ An HTTP call or a log line while a lock is held → copy what you need, release, then act.

## References

- **[asyncio.md](references/asyncio.md)** — read when writing async code or deciding whether to: sync or async, one way in, `TaskGroup`, deadlines, cancellation, `Semaphore`, workers over a `Queue`, a task that outlives its starter, `to_thread`, the `ASYNC` rules, and inspecting a hung program.
- **[resources.md](references/resources.md)** — read when opening a file, connection, client or lock, or writing a context manager: `with` and `async with`, `@contextmanager` with `finally`, `ExitStack`, what `__exit__` returns, and close order.
- **[threads-and-processes.md](references/threads-and-processes.md)** — read when running blocking or CPU-bound work in parallel, or sharing state between threads: thread pools, process pools and their start methods, locks, and free-threaded builds.

## Related skills

- **[python-protocols](../python-protocols/SKILL.md)** — the adapters behind these calls, their client timeouts and their retries
- **[python-testing](../python-testing/SKILL.md)** — async tests, deadlines under a virtual clock, and injected sleeps
- **[python-errors](../python-errors/SKILL.md)** — `ExceptionGroup` and `except*`, and logging a failure where it stops
- **[python-architecture](../python-architecture/SKILL.md)** — `main`, the composition root where the loop starts
- **[python-actions](../python-actions/SKILL.md)** — the operations and commands that call this code, and the exit codes
