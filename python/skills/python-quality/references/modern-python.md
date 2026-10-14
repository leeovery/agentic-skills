# Modern Python

**Write what `requires-python` allows, and when unsure an API exists, ask the installed interpreter rather than memory.**

Contents: checking before writing · upgrading · `from __future__ import annotations` · by version · deprecated and removed.

## Checking before writing

- **The version:** `requires-python` in `pyproject.toml` decides what the code may use, not the interpreter installed. `.python-version` picks the one `uv run` uses ([python-project](../../python-project/SKILL.md)). With no `target-version` set, ruff reads `requires-python`, so `UP` suggests only what the floor allows.
- **Whether an API exists:** ask the floor's interpreter, not memory: `uv run --python 3.14 python -I -c "import copy; help(copy.replace)"`.
- **Which release added it:** the API's page in the Python docs says "Added in version 3.x"; each version's What's New, linked below, lists the rest.

## Upgrading

1. Raise `requires-python` and `.python-version`, then lock and sync ([python-project](../../python-project/SKILL.md)).
2. `uv run ruff check --select UP --fix .`, then again, until it changes nothing. Then `uv run ruff check --fix .` clears the imports a rewrite left unused, and `uv run ruff format .` reformats: on a 3.14 floor it drops the brackets around `except A, B:`.
3. On a 3.14 floor, delete `from __future__ import annotations` by hand (below); `UP037` has already taken out the quotes it stood in for.
4. Run the gates, and commit that alone: it changes how the code is written, not what it does.
5. Make the changes that alter behaviour by hand, each in a change of its own with its tests: `asyncio.get_event_loop()` to `asyncio.run`, a `tarfile` filter made explicit, state passed to a process pool that relied on `fork`.

`--fix` applies only the fixes ruff marks safe. The rows below without a rule are by hand.

## `from __future__ import annotations`

- **Status:** deprecated, and not removed until after 3.13's end of life in 2029 ([3.14](https://docs.python.org/3/whatsnew/3.14.html), [PEP 749](https://peps.python.org/pep-0749/)). From 3.14 annotations are evaluated lazily without it ([PEP 649](https://peps.python.org/pep-0649/)).
- **On a 3.14 floor, delete it.** No rule does: `UP010` (default) removes other obsolete `__future__` imports, never this one. `UP037` removes the quotes.
- **Code that supports 3.13 or older keeps it,** as the way to write unquoted forward references there. Under it, annotations are strings at runtime, so whatever reads them (pydantic, typer, `typing.get_type_hints`) needs every annotated name importable at runtime: no `if TYPE_CHECKING:` import for those names.
- **Code that reads annotations** uses `annotationlib.get_annotations(obj, format=Format.FORWARDREF)`. `RUF063` (default) flags reading `__annotations__` from a class's `__dict__`.

## By version

The ruff column names the rule that flags the old form, where the drop-in runs one.

| Since | Write | In place of | ruff |
|---|---|---|---|
| [3.10](https://docs.python.org/3/whatsnew/3.10.html) | `X \| None`, `A \| B` | `Optional[X]`, `Union[A, B]` | `UP045`, `UP007` |
| 3.10 | `match` with class, mapping and sequence patterns | `isinstance` and `elif` ladders over shapes | — |
| 3.10 | `@dataclass(frozen=True, slots=True, kw_only=True)`: 3.10, not 3.11 | hand-written `__slots__`; positional fields | — |
| 3.10 | `zip(a, b, strict=True)` | silent truncation | `B905` |
| 3.10 | `with (open(a) as src, open(b) as dst):` | nested `with`; backslashes | `SIM117` |
| 3.10 | `itertools.pairwise(xs)` | `zip(xs, xs[1:])` | `RUF007` |
| [3.11](https://docs.python.org/3/whatsnew/3.11.html) | `ExceptionGroup`, `except* E:` | a list of errors raised as one | — |
| 3.11 | `err.add_note(f"while loading {path}")` | a new exception per layer to add context | — |
| 3.11 | `-> Self` | `TypeVar("T", bound="C")` on methods | `PYI019` |
| 3.11 | `class Status(StrEnum)` | `class Status(str, Enum)` | `UP042` |
| 3.11 | `datetime.now(UTC)`, `from datetime import UTC` | `timezone.utc` | `UP017` |
| 3.11 | `async with asyncio.TaskGroup() as tg:` | `gather`; a bare `create_task` | — |
| 3.11 | `async with asyncio.timeout(5):` | `asyncio.wait_for` | — |
| 3.11 | `except TimeoutError` | `except asyncio.TimeoutError` | `UP041` |
| 3.11 | `case _: assert_never(x)` | `raise AssertionError` | — |
| 3.11 | `tomllib.load(f)` | `toml`, `tomli` | — |
| [3.12](https://docs.python.org/3/whatsnew/3.12.html) | `def first[T](xs: Sequence[T]) -> T`, `class Box[T]:`, `type Pair[T] = tuple[T, T]` | `TypeVar`, `Generic[T]`, `X: TypeAlias = …` | `UP047`, `UP046`, `UP040` |
| 3.12 | `@override` on an overriding method | nothing, so a misspelt override passes | pyrefly strict's `missing-override-decorator` |
| 3.12 | `**kwargs: Unpack[Options]`, with a `TypedDict` | `**kwargs: Any` | `ANN401` |
| 3.12 | `itertools.batched(xs, n, strict=True)`; `strict` from 3.13 | slicing loops; `more_itertools.chunked` | `B911` |
| 3.12 | any expression, and the same quotes, inside an f-string | juggling quote styles | — |
| [3.13](https://docs.python.org/3/whatsnew/3.13.html) | `copy.replace(order, status=Status.CANCELLED)` | `dataclasses.replace`; `nt._replace` | — |
| 3.13 | `@warnings.deprecated("use cancel")` | a docstring note | — |
| 3.13 | `def is_order(v: object) -> TypeIs[Order]` | `TypeGuard`, which narrows only the true branch | — |
| 3.13 | `Queue.shutdown()`, on `queue.Queue` and `asyncio.Queue` | a sentinel sent through the queue | — |
| 3.13 | `class Box[T = int]:` | overloads faking a default | — |
| [3.14](https://docs.python.org/3/whatsnew/3.14.html) | unquoted forward references, evaluated lazily | quoted names; `from __future__ import annotations` | `UP037`; the import by hand |
| 3.14 | `annotationlib.get_annotations(obj, format=Format.FORWARDREF)` | reading `__annotations__` | `RUF063` |
| 3.14 | `except TimeoutError, ConnectionError:`, without `as` | a bracketed tuple | `ruff format` |
| 3.14 | `t"…"` templates, handed to a renderer that escapes | f-strings into SQL, HTML or a shell | — |
| 3.14 | no `return`, `break` or `continue` leaving a `finally` | swallowing the exception in flight; now a `SyntaxWarning` | — |
| 3.14 | `uuid.uuid7()` | a third-party UUIDv7 | — |
| 3.14 | `Path.copy()`, `copy_into()`, `move()`, `move_into()` | `shutil.copy2(str(p), …)` | — |
| 3.14 | `map(f, a, b, strict=True)` | silent truncation | `B912` |
| 3.14 | `from compression import zstd` | the third-party `zstandard` | — |
| [3.15](https://docs.python.org/3/whatsnew/3.15.html) | `lazy import json`, at module scope | imports inside functions to cut start-up | `TID254`, `TID255`, once `require-lazy` or `ban-lazy` is set |
| 3.15 | `frozendict(x=1)` | `MappingProxyType`; tuples of pairs | — |
| 3.15 | `MISSING = sentinel("MISSING")`, usable in `int \| MISSING` | `MISSING = object()` | — |
| 3.15 | `[*xs for xs in groups]` | a nested comprehension; `itertools.chain` | — |
| 3.15 | `open()` reading UTF-8 by default | the locale's encoding; keep `encoding="utf-8"` while the floor is lower | — |
| 3.15 | `class Movie(TypedDict, closed=True)` | an open `TypedDict` | — |
| 3.15 | `python -m profiling.sampling` | `profile`, now deprecated | — |

3.15 shipped on 2026-10-09; its rows are for a project whose floor has moved, once its dependencies have 3.15 wheels.

## Deprecated and removed

What agents still write, and what replaces it.

| Pattern | Status | Write instead | Caught by |
|---|---|---|---|
| `typing.List`, `Dict`, `Set`, `Tuple`, `Callable` | deprecated since 3.9; no removal planned | `list[int]`, `collections.abc.Callable` | `UP006`, `UP035` |
| `datetime.utcnow()`, `utcfromtimestamp()` | deprecated 3.12: naive results | `datetime.now(UTC)`, `fromtimestamp(ts, tz=UTC)` | `DTZ003`, `DTZ004` |
| `asyncio.get_event_loop()` with no loop running | raises `RuntimeError` from 3.14 | `asyncio.run(main())`; `asyncio.get_running_loop()` in a coroutine | the drop-in's ban list |
| `asyncio.set_event_loop_policy` and the policy classes | deprecated 3.14; go in 3.16 | `asyncio.run(main(), loop_factory=…)` | — |
| `asyncio.iscoroutinefunction` | goes in 3.16 | `inspect.iscoroutinefunction` | — |
| `X: TypeAlias = …` | deprecated 3.12 | `type X = …` | `UP040` |
| `typing.AnyStr` | deprecated 3.13; removed 3.18 | `def f[S: (str, bytes)](s: S) -> S` | — |
| `typing.ByteString` | warns on access from 3.15; removed 3.17 | `collections.abc.Buffer` | — |
| `NamedTuple("P", x=int)`, `TypedDict("TD")` | removed 3.15 | class syntax | `UP014`, `UP013` |
| `codecs.open()` | deprecated 3.14 | `open()` | — |
| `shutil.rmtree(onerror=…)` | deprecated 3.12 | `onexc=` | — |
| `ast.Num`, `ast.Str`, `visit_Num` | removed 3.14 | `ast.Constant`, `visit_Constant` | — |
| `distutils`, `imp`, `asyncore`, `asynchat` | removed 3.12 | `packaging`, `importlib` | — |
| `cgi`, `crypt`, `telnetlib`, `pipes`, `imghdr` and the rest of [PEP 594](https://peps.python.org/pep-0594/)'s list | removed 3.13 | a replacement, or PyPI's `standard-*` forks | — |
| `import pkg_resources` | gone from setuptools 82.0.0 | `importlib.metadata`, `importlib.resources` | — |
| `tarfile.extractall()` with no `filter=` | its default became `"data"` in 3.14 | `filter="data"`, written out | — |
| `multiprocessing` leaning on `fork` to copy globals | `forkserver` is the default on Unix but macOS from 3.14 | state passed in explicitly | — |
| `os.popen`, `os.spawn*` | soft-deprecated 3.14 | `subprocess.run`, behind a Protocol | `S605`; the ban list keeps `subprocess` out of the domain |
| `logging.warn()` | deprecated | `logging.warning()` | `G010` |
| `profile` | deprecated 3.15; removed 3.17 | `profiling.tracing` | — |
| `[tool.pytest.ini_options]` | still read; pytest 9 adds `[tool.pytest]`, and both at once is an error | `[tool.pytest]`, with real lists and booleans | — |
| pytest-asyncio's `event_loop` fixture | removed in pytest-asyncio 1.0 | `loop_scope=` on the marker or fixture | — |
