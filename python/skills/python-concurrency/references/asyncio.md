# asyncio

**Async earns its place when a program waits on many things at once. It's entered once, every task it starts has an owner, every wait has a deadline, and every cancellation reaches the code that asked for it.**

Contents: sync or async · one way in · tasks and their owner · deadlines · cancellation · bounded fan-out · workers over a queue · a task that outlives its starter · blocking calls · the `ASYNC` rules · a hung program.

## Sync or async

| The program | Write it |
|---|---|
| A command that makes a few calls, one after another | sync, with `httpx2.Client` |
| A command that makes hundreds of independent calls | sync, with one `asyncio.run` around the fan-out |
| A server, a consumer, a worker holding many connections | async throughout, from `main` down |
| CPU-bound work | neither: processes, in [threads-and-processes.md](threads-and-processes.md) |
| Tens of blocking calls, already written sync | a thread pool, in [threads-and-processes.md](threads-and-processes.md) |

Sync is the default because async isn't local. A function that awaits is `async def`, so its caller must be too, and so on up the stack; a blocking call anywhere below stalls every task on the loop. Agents reach for async because it sounds faster: it's faster only when the time goes on waiting, and there's a lot of waiting to overlap.

## One way in

`asyncio.run` starts a loop, runs one coroutine to its end, then closes the loop. It's called once, at the top of the async path: `main` for a program that's async throughout, or the one function in the composition root that a sync command calls:

```python
# src/shop/cli.py
def settle_payments(
    settings: Settings, payment_ids: Sequence[PaymentId]
) -> list[PaymentId]:
    """Settle the payments, and return those that weren't.

    The program's one way into asyncio: the loop starts here and closes here.
    """
    return asyncio.run(_settle(settings, payment_ids))


async def _settle(
    settings: Settings, payment_ids: Sequence[PaymentId]
) -> list[PaymentId]:
    async with httpx2.AsyncClient(
        base_url=str(settings.gateway_url),
        headers=_authorised(settings),
        timeout=settings.gateway_timeout.total_seconds(),
    ) as http:
        settler = Settler(
            provider=Settlements(client=http),
            limit=SETTLE_AT_ONCE,
            deadline=SETTLE_DEADLINE,
        )
        return await settler.settle(payment_ids)
```

`settle_payments` returns a plain list, so the `settle` command, its tests and `main` stay sync; the command reaches it through the `Deps` that `main` builds, and a test of the command passes a lambda in its place.

- **Nothing below it calls `asyncio.run`.** Inside a running loop it raises `RuntimeError: asyncio.run() cannot be called from a running event loop`, so a library function that calls it can never be called from async code.
- **Async clients open inside the coroutine it runs,** with `async with`. `AsyncClient.aclose()` is a coroutine, so sync wiring can't close one, and only the loop that used its connections can.
- **`asyncio.get_running_loop()`** inside a coroutine, when an API wants the loop: `loop.time()` for the loop's clock. Never `get_event_loop()`, which raises on 3.14 when no loop is running, and which the drop-in bans.
- **Ctrl-C cancels the main task.** `asyncio.run` turns the first SIGINT into a cancellation, lets the tasks unwind through their `finally` blocks, then raises `KeyboardInterrupt`, which `main` maps to exit code 130 ([python-actions](../../python-actions/SKILL.md)). A second Ctrl-C raises at once. A task that swallows the cancellation keeps the program running after the first.

## Tasks and their owner

A `TaskGroup` ([3.11](https://docs.python.org/3/whatsnew/3.11.html)) owns the tasks created on it: leaving the `async with` waits for all of them. When one raises, the group cancels the rest, waits for them, and raises everything that failed as one `ExceptionGroup`.

- **Catch the failures you expect inside each task,** as `Settler._settled` does with `SettlementFailed` and `TimeoutError`. The group then raises only for a bug, and one refused payment doesn't cancel the others.
- **What does escape arrives as an `ExceptionGroup`,** even a single exception, and even one raised in the group's own body. Handle it with `except*` where something acts on it ([python-errors](../../python-errors/SKILL.md)); an `except SettlementFailed:` around the group never matches.
- **Results come from the tasks** once the group has closed: `task.result()`, as the pattern does. Not from a list the tasks append to, which reads in completion order.
- **Not `gather`.** When one awaitable fails, `gather` raises that error and leaves the others running, owned by nobody.
- **Not a bare `create_task`.** The loop holds tasks weakly, so a task nobody references can be collected before it finishes, and its exception is logged at best. ruff's `RUF006` flags one whose result isn't kept.

## Deadlines

`asyncio.timeout(seconds)` ([3.11](https://docs.python.org/3/whatsnew/3.11.html)) bounds a block. When the deadline passes, it cancels whatever the block is awaiting and, as the block exits, raises `TimeoutError`.

- **Catch `TimeoutError` outside the block.** Inside it, the await sees `CancelledError`; the conversion happens on the way out.
- **The whole operation, not each call.** A deadline around a retry loop bounds the retries too. `timeout_at(when)` takes an absolute time on the loop's clock, and `reschedule()` moves a deadline that's already running.
- **Not `wait_for`.** Since [3.12](https://docs.python.org/3/whatsnew/3.12.html) it's built on `asyncio.timeout` around a single awaitable, so it's a second spelling of the same deadline that can't cover a block. One spelling reads the same everywhere.
- **A coroutine takes no `timeout` parameter.** The caller wraps the call. ruff's `ASYNC109` flags one that does. A deadline that's part of an operation's policy, as `Settler.deadline` is, is a field `main` sets, and the operation wraps its own calls.
- **Clients keep their own timeout.** httpx2's `timeout=` applies to each connect, each read, each write and each wait for a pooled connection, separately. A provider that sends a byte every few seconds never trips a read timeout; only the deadline around the whole call ends that wait. Set both.

## Cancellation

`asyncio.CancelledError` is a `BaseException`, so `except Exception:` lets it through. It's caught only by `except BaseException:`, a bare `except:` (ruff's `E722`, in the default set) and `except asyncio.CancelledError:`.

- **Clean up in `finally`.** It runs on cancellation as on any other exit.
- **An `await` in `finally` still runs,** as a cancellation is delivered once. Bound it with `asyncio.timeout`: a second cancellation, or a stuck close, would otherwise hold the exit.
- **To act on the cancellation itself,** `except asyncio.CancelledError:`, do the work, then a bare `raise`.
- **Never swallow it.** `asyncio.timeout` relies on the cancellation reaching it. A coroutine that catches `CancelledError` and returns ends the timeout block with no error, so the caller carries on as if the call had finished. The same coroutine keeps a program running after Ctrl-C.
- **Never call `task.uncancel()`** outside code that implements a timeout or a group.

## Bounded fan-out

The pattern's `Semaphore` lets `limit` settlements run at once and parks the rest. It suits work already in hand: a task per payment is cheap, and the semaphore caps the connections, not the tasks.

The limit is a number with a reason, written beside it where `main` sets it: `SETTLE_AT_ONCE = 8`, under the comment that the provider takes ten connections per token. A limit with no reason gets doubled by the next person who finds the run slow.

## Workers over a queue

When the work arrives as a stream, or there's too much to hold a task for each, a fixed set of workers takes it from a bounded queue:

```python
# src/shop/settlement/settle.py, on Settler
async def settle_stream(self, payment_ids: AsyncIterable[PaymentId]) -> list[PaymentId]:
    """Settle payments as they arrive, and return those that failed.

    `limit` workers take them from a queue that holds `limit` more, so a long
    stream never sits in memory whole.
    """
    queue: asyncio.Queue[PaymentId] = asyncio.Queue(maxsize=self.limit)
    unsettled: list[PaymentId] = []
    async with asyncio.TaskGroup() as tg:
        for _ in range(self.limit):
            tg.create_task(self._work(queue, unsettled))
        async for payment_id in payment_ids:
            await queue.put(payment_id)
        queue.shutdown()
    return unsettled


async def _work(
    self, queue: asyncio.Queue[PaymentId], unsettled: list[PaymentId]
) -> None:
    while True:
        try:
            payment_id = await queue.get()
        except asyncio.QueueShutDown:
            return
        if not await self._settled(payment_id):
            unsettled.append(payment_id)
```

- **`maxsize` is the back-pressure.** `put` waits while the queue is full, so the producer runs no further ahead than the workers.
- **`Queue.shutdown()`** ([3.13](https://docs.python.org/3/whatsnew/3.13.html)) ends the workers. After it, `put` raises `QueueShutDown`, and `get` hands out what's left, then raises `QueueShutDown` once the queue is empty. `shutdown(immediate=True)` drops what's left instead.
- **No sentinels.** One `None` per worker has to match the number of workers, and a worker that died leaves its `None` for nobody. `shutdown` is one call, and it wakes every waiter.
- **The drain is bounded:** at most `limit` payments are queued at shutdown, each under its deadline.
- **Shared state between tasks needs no lock** when nothing awaits between reading and writing it, as with `unsettled.append`. A read, an `await`, then a write is a race between tasks too.

## A task that outlives its starter

A `TaskGroup` fits work that ends inside one function. A job that runs beside other work, such as settling pending payments every minute while a consumer runs, is held by the code that started it, then cancelled and awaited when that code is done:

```python
# src/shop/settlement/poll.py
@asynccontextmanager
async def polling(
    job: Callable[[], Awaitable[object]], *, every: timedelta
) -> AsyncGenerator[None]:
    """Run job every interval while the body runs; on exit, stop it and wait for it.

    The task is held here, not in a TaskGroup: a group around the yield would
    wrap whatever the body raises in an ExceptionGroup.
    """
    task = asyncio.create_task(_every(job, every), name="poll")
    try:
        yield
    finally:
        task.cancel()
        await asyncio.wait([task])
        if not task.cancelled():
            task.result()  # an exception that ended it early surfaces here


async def _every(job: Callable[[], Awaitable[object]], every: timedelta) -> None:
    while True:
        await asyncio.sleep(every.total_seconds())
        await job()
```

- **`asyncio.wait([task])`** waits without raising the task's own `CancelledError` into the caller, so a cancellation of the caller still propagates, and the task's isn't mistaken for it.
- **`task.result()`** re-raises an exception that stopped the job before the exit, rather than losing it.
- **The job's own failures** (a refused settlement, a timeout) are caught inside `job`, as the settler's are, so the loop runs on; only a bug ends it.
- **Order at shutdown** comes from nesting: the body's work stops first, then the poller, then the client it settles through. [resources.md](resources.md) covers close order.

## Blocking calls

`await asyncio.to_thread(fn, *args)` runs a blocking function on the loop's default thread pool and awaits its result. Reach for it for a library you can't replace with an async one, and for file work big enough to matter.

- **A thread can't be cancelled.** Cancelling the awaiting task, or its deadline passing, leaves the thread running to the end. Give the blocking call its own timeout where it takes one.
- **Context variables are copied** into the thread, so a request id set in a `ContextVar` is still there.
- **A `sqlite3` connection belongs to the thread that made it,** and raises `ProgrammingError` when used from another. Open the connection inside the function you send to the thread.
- **The default pool** has `min(32, os.process_cpu_count() + 4)` threads, shared by every `to_thread` in the program.

## The `ASYNC` rules

The drop-in selects ruff's `ASYNC` family. Inside `async def`, it reports:

| Rule | What it finds |
|---|---|
| `ASYNC109` | a `timeout` parameter |
| `ASYNC100` | an `asyncio.timeout` block with no `await` in it |
| `ASYNC110` | `asyncio.sleep` in a `while` loop, polling where an `Event` would wake it |
| `ASYNC210` | a blocking HTTP call: `urllib`, `requests` |
| `ASYNC220`, `ASYNC221`, `ASYNC222` | a process started, run or waited on with blocking calls |
| `ASYNC230`, `ASYNC240` | `open()`, and blocking `pathlib` calls such as `read_text()` |
| `ASYNC251` | `time.sleep` |

`ASYNC212` looks for `httpx` by name, so it misses `httpx2.Client`: a sync client inside a coroutine is review's to catch. Nor does any rule see a `sqlite3` query or a blocking SDK call there.

## A hung program

[3.14](https://docs.python.org/3/whatsnew/3.14.html) can list the tasks of a running program from outside it:

```sh
python -m asyncio ps 4242      # every task, its name, and what it awaits
python -m asyncio pstree 4242  # the same, as a tree of who awaits whom
```

- It attaches to the process, so it needs the permissions the [remote debugging HOWTO](https://docs.python.org/3.14/howto/remote_debugging.html#permission-requirements) lists. On macOS it refuses with a permissions error unless run with `sudo`.
- Name the tasks you create (`tg.create_task(coro, name=f"settle {payment_id}")`, or the poller's `name="poll"`): the listing shows the names, and a stuck `settle pay-7` says where to look.
