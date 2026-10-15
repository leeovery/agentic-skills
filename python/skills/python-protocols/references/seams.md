# Seams

**A seam is a field where `main` passes the real thing and a test passes its own.**

Contents: the seams · callable field or Protocol · the clock · waits · the environment · files · HTTP · processes · ids and randomness · no defaults.

## The seams

| What | Seam | `main` passes | A test passes |
|---|---|---|---|
| the instant | `now: Callable[[], datetime]` | `partial(datetime.now, UTC)` | `lambda: NOW` |
| a wait, and its deadline | `sleep: Callable[[float], None]`, `clock: Callable[[], float]` | `time.sleep`, `time.monotonic` | the bound methods of a fake that moves its clock when slept on |
| settings | the parsed values, as fields | values from `read_settings()` | the values themselves |
| files | a base directory, `labels: Path` | the configured path | `tmp_path` |
| an HTTP API | an `httpx2.Client` with base URL, auth and timeout set | the client the wiring builds and closes | a client on `httpx2.MockTransport` |
| another program | a runner Protocol | `ProcessRunner(timeout=...)` | a fake runner |
| an id the program mints, that a test must predict | `new_id: Callable[[], OrderId]` | `lambda: OrderId(str(uuid.uuid7()))` | `lambda: OrderId("o-1")` |
| a random draw | `rand: Callable[[], float]` | `random.random` | `lambda: 1.0` |
| logs | none: each module names its logger | the logging setup | pytest's `caplog` ([python-errors](../../python-errors/SKILL.md)) |

## Callable field or Protocol

- **One function is a callable field,** typed `Callable[[...], R]`. `main` passes the function itself, or a `partial` of one, and a test passes a lambda or a bound method of its fake. No class is declared for it.
- **An object of several methods is a Protocol,** faked by one class: a runner that finds a program and runs it, a store that loads and saves.
- Functions that travel together, such as a retry policy's `sleep`, `clock` and `rand`, stay separate fields on the one dataclass that uses them. A test that needs two of them to agree passes two bound methods of one fake.

## The clock

- **A decision about an instant takes `now`:** a 14-day return window, a token's expiry, the time an order was cancelled. A pure function takes the instant as an argument instead, and the operation that calls it reads `now` once.
- **`main` passes `partial(datetime.now, UTC)`,** so every instant is aware and in UTC ([python-types](../../python-types/SKILL.md)). ruff's `DTZ` rules flag a naive one.
- **A test stops the clock where it cares:**

```python
# tests/order/test_cancel.py
def canceller(orders: FakeOrders, payments: FakeRefunder | None = None) -> Canceller:
    """A Canceller over the fakes, its clock stopped at NOW."""
    return Canceller(
        orders=orders, payments=payments or FakeRefunder(), now=lambda: NOW
    )
```

time-machine is for the rare library that reads the clock itself; [python-testing](../../python-testing/SKILL.md) covers it.

## Waits

Code that waits takes `sleep`, and `clock` as well when it measures a deadline. `time.monotonic` measures elapsed time; `time.time` is banned, as the wall clock jumps when the system's time is set. The gateway's retry policy takes both, with `rand` for its jitter ([services.md](services.md)), and its tests pass one fake for the two:

```python
# tests/payments/test_gateway.py
@dataclass(slots=True)
class FakeTime:
    """A clock that moves only when something sleeps, and records each sleep."""

    now: float = 0.0
    slept: list[float] = field(default_factory=list)

    def sleep(self, seconds: float) -> None:
        """Record the wait, and move the clock past it at once."""
        self.slept.append(seconds)
        self.now += seconds

    def clock(self) -> float:
        """The seconds slept so far."""
        return self.now
```

The tests build the gateway with `Retry(sleep=time.sleep, clock=time.clock, rand=lambda: 1.0)`, so no test sleeps, and each asserts the exact waits ([python-testing](../../python-testing/SKILL.md)). Waits inside asyncio are [python-concurrency](../../python-concurrency/SKILL.md)'s.

## The environment

- **Only `main` reads it,** through the one `read_settings()` its wiring calls ([python-architecture](../../python-architecture/SKILL.md)). Below `main`, a component takes the values it uses as fields (a path, a URL, a timeout), never the `Settings` object, so a test passes the values and builds no settings at all.
- **`BaseSettings` reads the environment for any field left out,** even when given keyword arguments. A test that must build one passes every field; a test that runs `main` scopes the environment with `CliRunner().isolation(env={...})` ([python-actions](../../python-actions/SKILL.md)).
- **The home directory, a state directory and a config path** are resolved in `main` and passed as `Path`s. A test passes `tmp_path`, and the real ones are never touched.

## Files

- **Pass the directory as a base `Path`;** the code joins names under it, as the labeller does with `self.labels / f"{order.id}.pdf"`. A test passes `tmp_path` and reads back what was written.
- **A name from outside can lead out of the directory:** joined to the base, `../x` climbs out of it, and an absolute name replaces it. Check after joining, `path.resolve().is_relative_to(base.resolve())`, as the labeller does with an order's id.
- **Real files on `tmp_path`,** never a fake filesystem; [python-testing](../../python-testing/SKILL.md) covers isolation.

## HTTP

- **Pass an `httpx2.Client` with its base URL, auth and timeout set,** built in the wiring `main` supplies and closed by the `with` that opened it. `from_environment` binds the settings into a factory, opened only when `cancel` runs:

```python
# src/shop/cli.py: the client from_environment binds, and the headers it sends
    url = str(settings.gateway_url)
    headers = _authorised(settings.gateway_token)
    timeout = settings.gateway_timeout.total_seconds()
    return Deps(
        canceller=partial(
            _canceller,
            database=settings.database,
            client=partial(
                httpx2.Client, base_url=url, headers=headers, timeout=timeout
            ),
        ),
        ...
    )


def _authorised(token: SecretStr) -> dict[str, str]:
    """The headers that authorise a request to the payment gateway."""
    return {"Authorization": f"Bearer {token.get_secret_value()}"}
```

- **Never `httpx2.post(...)`,** which opens a client per call with no shared settings, **and never a client built inside the adapter or at import.** The adapter then can't be pointed at a test transport, and nobody closes the client.

## Processes

Finding a program and running it go together, so they're one Protocol, declared beside the code that calls it:

```python
# src/shop/shipping/label.py
@dataclass(frozen=True, slots=True, kw_only=True)
class Finished:
    """How a program ended: its exit code, and what it wrote to stderr."""

    code: int
    stderr: str


class Runner(Protocol):
    """Finds a program and runs it: the process seam."""

    def which(self, program: str) -> Path | None:
        """The program's path, or None when it isn't installed."""
        ...

    def run(self, program: Path, *args: str) -> Finished:
        """Run program with args to its end, raising TimeoutError if it overruns."""
        ...
```

The labeller turns the runner's `TimeoutError` into a `ShippingError`, `from` it, as it does a missing tool or a failed run, so whoever makes a label is told each as an outcome, never a crash ([python-errors](../../python-errors/SKILL.md)).

One adapter runs real programs. It is the only module besides `main`'s allowed `subprocess`:

```python
# src/shop/processes.py
@dataclass(frozen=True, slots=True, kw_only=True)
class ProcessRunner:
    """Runs each program to its end, killing any that runs past the timeout."""

    timeout: timedelta

    def which(self, program: str) -> Path | None:
        """The program's path on PATH, or None when it isn't installed."""
        found = shutil.which(program)
        return None if found is None else Path(found)

    def run(self, program: Path, *args: str) -> Finished:
        """Run program with args, never through a shell, and say how it ended."""
        try:
            done = subprocess.run(  # noqa: S603  # no shell, and the caller names the program
                [program, *args],
                stdout=subprocess.DEVNULL,
                stderr=subprocess.PIPE,
                encoding="utf-8",
                errors="replace",
                timeout=self.timeout.total_seconds(),
                check=False,
            )
        except subprocess.TimeoutExpired as err:
            raise TimeoutError(f"{program.name} ran past {self.timeout}") from err
        return Finished(code=done.returncode, stderr=done.stderr)
```

- **A list of arguments, never `shell=True`,** so an order id can't become a command. `subprocess.run` kills the child when the timeout passes, before it raises.
- **Only stderr is kept, decoded as UTF-8 with `errors="replace"`.** `text=True` decodes strictly, in the locale's encoding, so one byte of Latin-1 raises `UnicodeDecodeError` and hides how the program ended.
- **The ban lifts for this file alone, with its reason.** `per-file-ignores` lifts all of `TID251` at once ([python-quality](../../python-quality/SKILL.md)), so the file holds the adapter and nothing that reads the clock or the environment:

```toml
[tool.ruff.lint.per-file-ignores]
"src/shop/cli.py" = ["TID251"]
# The one adapter that runs a process. It reads neither the clock nor the environment.
"src/shop/processes.py" = ["TID251"]
```

The fake answers `which` from a dict, records each command it was asked to run, and overruns, raising the runner's `TimeoutError`, when a test asks it to:

```python
# tests/shipping/test_label.py
@dataclass(slots=True)
class FakeRunner:
    """A Runner that knows the programs in installed, and records what it ran."""

    installed: dict[str, Path] = field(default_factory=dict)
    code: int = 0
    stderr: str = ""
    overruns: bool = False
    ran: list[tuple[str, ...]] = field(default_factory=list)

    def which(self, program: str) -> Path | None:
        """The program's path, if the test installed it."""
        return self.installed.get(program)

    def run(self, program: Path, *args: str) -> Finished:
        """Record the command, then finish as the test set, or run past the timeout."""
        self.ran.append((str(program), *args))
        if self.overruns:
            raise TimeoutError(f"{program.name} ran past 0:00:30")
        return Finished(code=self.code, stderr=self.stderr)
```

The adapter's own tests run this interpreter as the child, `Path(sys.executable)`, so they need no tool installed.

## Ids and randomness

- **A value a test must predict is a seam.** The retry policy's jitter comes from `rand`, and an id minted with `uuid.uuid7()` ([3.14](https://docs.python.org/3/whatsnew/3.14.html)) that a test checks comes from a `new_id` field. An id a second try must repeat isn't minted at all: the reorderer takes the new order's from its caller ([python-actions](../../python-actions/SKILL.md)).
- **One nothing asserts on needs no seam.** `main` mints the run's correlation id with `uuid.uuid7()` itself.
- **An id derived from data needs none either:** the canceller's refund key is `cancel-{order_id}`, the same each time that cancel runs.
- ruff's `S311` flags a call to `random.random()` as unfit for secrets; passing the function as a seam isn't flagged, and jitter is no secret. A token or a key comes from `secrets`.

## No defaults

No seam field has a default; `main` passes each one.

- `now` can't default to `datetime.now`: the ban keeps that call in `main`.
- A default runner, directory or client would let a test that forgot one run the real program, write the real disk or call the real provider, and nothing would fail to say so.
