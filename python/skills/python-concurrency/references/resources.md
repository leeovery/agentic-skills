# Resources

**Whatever is opened is closed by the code that opened it, through a `with`, however that code ends.**

Contents: `with` owns it · writing a context manager · a number known at runtime · what `__exit__` returns · close order · async resources.

## `with` owns it

Files, database connections, HTTP clients, pools, locks and temporary directories are each opened in a `with` or `async with`, in the function that owns them. The `with` closes them on a return, an exception and a cancellation alike; a `close()` further down runs only on the path that reaches it. ruff's `SIM115` flags `open()`, `Path.open()`, `tempfile.TemporaryFile()` and the rest when no `with` holds them.

- **A function that returns an open resource hands it over,** and its caller takes it with `with`. `shop.storage.sqlite.connect` returns a connection; `main`'s wiring takes it as `closing(connect(settings.database))`.
- **`contextlib.closing(x)`** for anything with a `close()` and no context manager of its own, or the wrong one.
- **A `sqlite3.Connection` used as a context manager commits or rolls back; it doesn't close.** `with db:` is a transaction ([python-actions](../../python-actions/SKILL.md)); `with closing(db):` is the close.
- **A fixture that opens something yields inside its `with`,** so pytest closes it after the test, pass or fail.

## Writing a context manager

Write one as a generator with `@contextmanager`, or `@asynccontextmanager` for an async one, and annotate it `-> Generator[T]` or `-> AsyncGenerator[T]`. typeshed marks the `Iterator` and `AsyncIterator` forms deprecated, and the drop-in's pyrefly fails on a deprecated call. Use a class only when the object lives on between uses, as a pool does.

The body's exception is raised inside the generator, at the `yield`. Cleanup after a bare `yield` runs only when the body succeeds, so it goes in `finally`:

```python
# src/shop/storage/lock.py
@contextmanager
def held(lock: Path) -> Generator[None]:
    """Hold the lock file while the body runs, and remove it however the body ends.

    Raises FileExistsError when another run holds it.
    """
    lock.touch(exist_ok=False)
    try:
        yield
    finally:
        lock.unlink()
```

- **Acquire before the `try`.** If `touch` fails, there's nothing to release, and `finally` mustn't delete another run's lock.
- **Test the failing path,** as it's the one `finally` exists for:

```python
# tests/storage/test_lock.py
def test_the_lock_goes_when_the_run_fails(tmp_path: Path) -> None:
    lock = tmp_path / "settle.lock"

    with pytest.raises(LookupError), held(lock):
        raise LookupError

    assert not lock.exists()
```

## A number known at runtime

When how many things to open is known only at runtime, and all must be open together, an `ExitStack` holds them:

```python
# src/shop/payments/reports.py
def merged(reports: Sequence[Path]) -> list[str]:
    """Every line of the reports, each sorted by payment id, in one sorted list.

    The files are open together; if one fails to open, those already open close.
    """
    with ExitStack() as stack:
        files = [
            stack.enter_context(report.open(encoding="utf-8")) for report in reports
        ]
        return list(heapq.merge(*files))
```

- **It closes in reverse order of entry,** each close running even when another raised.
- **A failure partway closes what opened before it.** Without the stack, the fourth file failing leaves the first three open.
- **`stack.callback(fn, *args)`** registers cleanup that isn't a context manager.
- **`stack.pop_all()`** hands everything to a new stack: open all or none, then pass the lot to the caller to own.
- **`AsyncExitStack`** is the same for async resources: `await stack.enter_async_context(client)`, and `push_async_callback` for a coroutine.

## What `__exit__` returns

A truthy return from `__exit__` swallows the body's exception, whatever it is: `KeyboardInterrupt` and `CancelledError` included. So:

- **`__exit__` returns `None`,** annotated `-> None`. Annotated `-> bool`, it tells the type checker the block may swallow its exceptions, which changes what the checker treats as reachable after it.
- **A context manager that must suppress names what,** in its docstring, and checks the type before returning `True`. `contextlib.suppress(FileNotFoundError)` does exactly that, and is the one to reach for.
- **The same goes for a `@contextmanager`:** an `except` around the `yield` that doesn't re-raise swallows the body's exception.

## Close order

Resources close in the reverse of the order they opened, which is the order shutdown needs: what takes work in stops first, then what does it, then what it writes to.

- **Nested or bracketed `with`** closes last-opened first. `main`'s wiring opens the database, then the gateway's client, in one `with (... as db, ... as http):`, so the client closes before the database.
- **An `AsyncExitStack`** gives the same order when the components are decided at runtime: enter the client, then the workers, then the intake, and the stack stops the intake first and closes the client last.
- **Each close is bounded.** A component whose exit drains work (workers finishing what's queued, a buffer flushing) bounds the drain with `asyncio.timeout`, so one stuck call can't hold the process open. [asyncio.md](asyncio.md) has the queue workers and the poller.

## Async resources

- **`async with`** for an `httpx2.AsyncClient`, an async database connection or an `asyncio.TaskGroup`. Their closes are awaits, so they close inside the loop that used them.
- **An async generator that holds a resource across a `yield`** releases it only when the generator is finished or closed. One abandoned partway (a `break`, an exception in the loop) closes whenever the collector gets to it. Iterate it inside `async with contextlib.aclosing(pending()) as payments:` so it closes when the loop ends.
- **`asyncio.Lock`, `Semaphore` and `Condition`** are taken with `async with`, never `acquire()` and a `release()` further down.
