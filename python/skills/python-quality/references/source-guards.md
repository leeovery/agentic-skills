# Source guards

**A source guard is a test that reads the project's own source and fails where a rule breaks.**

Contents: a linter first · the shape of a guard · where the guards live, and how they're imported · the helpers · guard 1, modules named for a job · guard 2, nothing runs at import · guard 3, values worded by display · guard 4, logging configured by main · guard 5, no patching in tests · guard 6, domain names say what they are or do · what syntax can't see.

## A linter first

A setting is cheaper than a guard, so reach for one where it can say the rule:

| The rule | The setting |
|---|---|
| a module nobody may import, or a call only one file may make | ruff's `banned-api` (`TID251`), with `per-file-ignores` naming that file |
| a package mustn't import another, or a third-party package | an import-linter `layers` or `forbidden` contract |

A guard is for what neither can say: a module's name, what runs at import, which def a call sits in, what a format spec holds, or a rule across the project's files.

## The shape of a guard

- **It finds the project by walking up to `pyproject.toml`** from its own file, never by a fixed `../..` that breaks when the test moves.
- **It reads every `.py` file under `src/`,** or `tests/` for a rule about tests, but those in a directory starting with `.` below it. It never skips the root by that test: a checkout can sit inside a hidden directory.
- **It parses with `ast`** and walks the tree, tracking the qualname of the def or class around each node.
- **It runs on every Python the project supports,** from 3.11, which `tomllib` needs. A node class newer than the floor is looked up only behind a version check.
- **Each breach says where, what, and the fix:** `src/shop/storage/sqlite.py:45: calls connect at import: call it from main, or from the function that needs it`. `check` fails once with every breach, one per line, and no traceback.
- **Its allowance only shrinks.** Each entry is `"path:qualname"`, with a comment saying why it's allowed for now. An entry covers every breach in that def or class; one that covers nothing fails, so it's deleted the day the code is fixed.
- **It's been seen to fail.** A second test writes a module that breaks the rule under `tmp_path`, runs the guard over it, and checks it reports exactly that, and a stale allowance entry too where the guard keeps an allowance. A guard reading the wrong directory otherwise passes forever.

## Where the guards live, and how they're imported

Each guard is a module in `tests/guards/`, beside `source.py`, the helpers. Every directory of `tests/` has an `__init__.py` (`INP001` asks for them anyway), and pytest runs with `--import-mode=prepend`, which puts the first directory above them without one, the project root, on `sys.path`, so `from tests.guards.source import check` resolves. `shop` still comes from the install, and `pythonpath = ["src"]` is never set.

`--import-mode=importlib`, which pytest suggests for new projects, leaves `sys.path` alone, and its docs say helpers under `tests/` then can't be imported, so the guards don't use it. Under `tests/`, the helpers never reach the wheel, and pyrefly finds them through `search-path = ["src", "."]` in [pyproject-tools.toml](pyproject-tools.toml).

## The helpers

`report` is for the tests that prove a guard fails, which compare its list; `check` is for the guard's run over the project. Each guard below elides its imports, these helpers and the standard library's, as `...`.

```python
# tests/guards/source.py
"""Reading the project's own source, for the guards that hold its rules. Only tests import this."""

import ast
import textwrap
from collections.abc import Iterable, Iterator
from dataclasses import dataclass
from pathlib import Path

import pytest

TOP = "<module>"


def project_root() -> Path:
    for directory in Path(__file__).resolve().parents:
        if (directory / "pyproject.toml").is_file():
            return directory
    pytest.fail("no pyproject.toml above the guards")


@dataclass(frozen=True, slots=True, kw_only=True)
class Module:
    path: str
    tree: ast.Module


def modules(root: Path, under: str) -> list[Module]:
    top = root / under
    return [
        Module(
            path=path.relative_to(root).as_posix(),
            tree=ast.parse(path.read_text(encoding="utf-8"), filename=str(path)),
        )
        for path in sorted(top.rglob("*.py"))
        if not any(part.startswith(".") for part in path.relative_to(top).parts)
    ]


def qualify(scope: str, name: str) -> str:
    return name if scope == TOP else f"{scope}.{name}"


def scoped(tree: ast.AST, scope: str = TOP) -> Iterator[tuple[str, ast.AST]]:
    """Every node below tree, with the qualname of the def or class it sits in."""
    for child in ast.iter_child_nodes(tree):
        yield scope, child
        if isinstance(child, ast.FunctionDef | ast.AsyncFunctionDef | ast.ClassDef):
            yield from scoped(child, qualify(scope, child.name))
        else:
            yield from scoped(child, scope)


def dotted(node: ast.expr) -> str:
    """The dotted name node spells, as "logging.getLogger"; "" for any other expression."""
    match node:
        case ast.Name(id=name):
            return name
        case ast.Attribute(value=value, attr=attr) if dotted(value):
            return f"{dotted(value)}.{attr}"
        case _:
            return ""


@dataclass(frozen=True, slots=True, kw_only=True)
class Breach:
    path: str
    line: int
    scope: str
    what: str
    fix: str

    def __str__(self) -> str:
        return f"{self.path}:{self.line}: {self.what}: {self.fix}"


def report(breaches: Iterable[Breach], *, allowed: Iterable[str] = ()) -> list[str]:
    """Each breach no "path:qualname" entry covers, then each entry that covers nothing."""
    allowance = frozenset(allowed)
    used: set[str] = set()
    problems: list[str] = []
    for breach in breaches:
        entry = f"{breach.path}:{breach.scope}"
        if entry in allowance:
            used.add(entry)
        else:
            problems.append(str(breach))
    stale = sorted(allowance - used)
    return problems + [
        f"{entry}: breaks the rule no more: delete it from the allowance"
        for entry in stale
    ]


def check(breaches: Iterable[Breach], *, allowed: Iterable[str] = ()) -> None:
    if problems := report(breaches, allowed=allowed):
        pytest.fail("\n".join(problems), pytrace=False)


def write(root: Path, name: str, source: str) -> None:
    path = root / name
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(textwrap.dedent(source).lstrip("\n"), encoding="utf-8")
```

## Guard 1: modules named for a job

A grab-bag name gives code no reason to live anywhere else ([python-architecture](../../python-architecture/SKILL.md)). A package is named by its `__init__.py`, so it's reported once. The guard is the [SKILL.md](../SKILL.md#pattern) pattern.

## Guard 2: nothing runs at import

A module body declares: imports, constants, functions, classes and its logger. A settings object, a client, a connection or a registration at module level runs in every test that imports it, before any test can stop it ([python-architecture](../../python-architecture/SKILL.md)).

- **Decorators and default values run with the `def`; the body doesn't.** A class body runs. A `__main__.py` is run, never imported, so it's skipped.
- **`DECLARATIONS` build a type, a logger or a constant, and touch nothing outside the process.** A name matches as it's written, `re.compile` not `compile`, and a call among an allowed call's arguments is still checked, so `frozenset(open(path))` fails. A project adds its own on that test, such as `timedelta`, `ContextVar`, which the [contextvars docs](https://docs.python.org/3/library/contextvars.html) ask for at module level, and pydantic's `ConfigDict`, `Field` and validators; never `TypeVar`, which a type parameter replaces from 3.12.
- **Annotations are skipped,** as from 3.14 they're evaluated lazily ([PEP 649](https://peps.python.org/pep-0649/)). Below 3.14 they run where they're written, so a project on an older floor reads them too.

```python
# tests/guards/test_import_time.py
"""Nothing runs at import: a module declares, and main does the work."""

...

DECLARATIONS = frozenset(
    {"NewType", "dataclass", "field", "logging.getLogger", "frozenset", "re.compile"}
)
FIX = "call it from main, or from the function that needs it"

# Calls let stand for now, each "path:qualname" with why. The set only shrinks.
ALLOWED: frozenset[str] = frozenset()


def calls_at_import(root: Path) -> Iterator[Breach]:
    for module in modules(root, "src"):
        if module.path.endswith("/__main__.py"):
            continue
        for scope, call in _runs(module.tree, TOP):
            name = dotted(call.func)
            if name not in DECLARATIONS:
                what = f"calls {name or 'an expression'} at import"
                yield Breach(
                    path=module.path, line=call.lineno, scope=scope, what=what, fix=FIX
                )


def _runs(node: ast.AST, scope: str) -> Iterator[tuple[str, ast.Call]]:
    """The calls node makes when its module is imported, each with its scope."""
    parts: list[ast.AST | None]
    match node:
        case ast.FunctionDef() | ast.AsyncFunctionDef():
            parts = [*node.decorator_list, *node.args.defaults, *node.args.kw_defaults]
        case ast.Lambda():
            parts = [*node.args.defaults, *node.args.kw_defaults]
        case ast.ClassDef():
            parts = [*node.decorator_list, *node.bases, *node.keywords]
            for statement in node.body:
                yield from _runs(statement, qualify(scope, node.name))
        case ast.AnnAssign():
            parts = [node.value]
        case ast.Call():
            yield scope, node
            parts = list(ast.iter_child_nodes(node))
        case _:
            parts = list(ast.iter_child_nodes(node))
    for part in parts:
        if part is not None:
            yield from _runs(part, scope)


def test_nothing_runs_at_import() -> None:
    check(calls_at_import(project_root()), allowed=ALLOWED)


def test_the_import_guard_finds_a_call_at_import(tmp_path: Path) -> None:
    write(
        tmp_path,
        "src/shop/store.py",
        '''
        """Opens the database, and reads the settings, as it's imported."""
        import logging
        import sqlite3

        from shop.settings import Settings

        logger = logging.getLogger(__name__)
        db = sqlite3.connect("shop.db")


        class Store:
            settings = Settings()

            def get(self) -> None:
                db.execute("SELECT 1")
        ''',
    )
    allowed = {"src/shop/store.py:Store", "src/shop/gone.py:old"}

    assert report(calls_at_import(tmp_path), allowed=allowed) == [
        f"src/shop/store.py:8: calls sqlite3.connect at import: {FIX}",
        "src/shop/gone.py:old: breaks the rule no more: delete it from the allowance",
    ]
```

## Guard 3: values worded by display

How a value reads to people is `shop.display`'s to say. Everywhere else, a format spec, `format()` or `strftime()` is a copy of that rule that will drift ([python-architecture](../../python-architecture/SKILL.md)). ruff's `UP031` and `UP032`, in the default set, flag `%` formatting and a literal's `str.format`, so specs end up in f-strings, where this guard reads them. A `.format()` on a template held in a name, which ruff leaves alone, and `strftime` imported from `time`, it reports by the name called. A t-string's interpolations hold format specs too: a project that writes them reads `ast.Interpolation` as well, behind a check for 3.14.

```python
# tests/guards/test_wording.py
"""Values are worded for people by shop.display alone."""

...

DISPLAY = "src/shop/display.py"
FIX = "call shop.display"


def wording(root: Path) -> Iterator[Breach]:
    for module in modules(root, "src"):
        if module.path == DISPLAY:
            continue
        for scope, node in scoped(module.tree):
            if found := _words(node):
                line, what = found
                yield Breach(
                    path=module.path, line=line, scope=scope, what=what, fix=FIX
                )


def _words(node: ast.AST) -> tuple[int, str] | None:
    """Where and how node words a value. A method goes by its name, as its type is unseen."""
    match node:
        case ast.FormattedValue(format_spec=ast.JoinedStr()):
            return node.lineno, "words a value with a format spec"
        case ast.Call(func=ast.Name(id="format")):
            return node.lineno, "words a value with format()"
        case ast.Call(func=ast.Attribute(attr="format" | "format_map")):
            return node.lineno, "words a value with str.format()"
        case ast.Call(func=ast.Attribute(attr="strftime") | ast.Name(id="strftime")):
            return node.lineno, "words a time with strftime()"
        case _:
            return None


def test_values_are_worded_by_display() -> None:
    check(wording(project_root()))


def test_the_wording_guard_finds_a_value_worded_in_place(tmp_path: Path) -> None:
    write(
        tmp_path,
        "src/shop/report.py",
        '''
        """A day's sales, worded where they're totalled."""
        from datetime import datetime
        from time import strftime

        LINE = "{:.2f} sold"


        def headline(total: int, day: datetime) -> str:
            return f"{total / 100:.2f} on {day.strftime('%d %b')}"


        def summary(total: int) -> str:
            return LINE.format(total / 100) + strftime(" at %H:%M")


        def footer(rate: float) -> str:
            return format(rate, ".1%")
        ''',
    )

    assert report(wording(tmp_path), allowed={"src/shop/report.py:footer"}) == [
        f"src/shop/report.py:9: words a value with a format spec: {FIX}",
        f"src/shop/report.py:9: words a time with strftime(): {FIX}",
        f"src/shop/report.py:13: words a value with str.format(): {FIX}",
        f"src/shop/report.py:13: words a time with strftime(): {FIX}",
    ]
```

## Guard 4: logging configured by main

`main` configures logging once; a module names its logger and nothing more ([python-errors](../../python-errors/SKILL.md)). ruff's `LOG015`, in the default set, catches a call on the root logger.

- **It goes by the name called,** so `from logging import captureWarnings` is caught as well as `logging.basicConfig`, and `addHandler` or `setLevel` on any logger; `logging.disable` only by its whole name, as `disable` alone is too common a word. `CONFIGURES` grows with the rest of `logging`'s configuring calls a project meets: `fileConfig`, `addFilter`, `removeHandler`, `setLoggerClass`.
- **A setting assigned on the module's `logger`,** such as `propagate` or `handlers`, is caught too. Those words are common, so one on another logger, a name bound from `getLogger(...)` or `logging.root`, needs the guard to track which names are loggers.

```python
# tests/guards/test_logging.py
"""Logging is configured once, by main; every other module only names its logger."""

...

ENTRY = "src/shop/cli.py"
# Called by these names on any logger or handler, or imported from logging.
CONFIGURES = frozenset(
    {"addHandler", "basicConfig", "captureWarnings", "dictConfig", "setLevel"}
)
DISABLE = "logging.disable"
SETTINGS = frozenset({"disabled", "handlers", "level", "propagate"})
FIX = "leave it to main; a module only calls logging.getLogger(__name__)"


def configures_logging(root: Path) -> Iterator[Breach]:
    for module in modules(root, "src"):
        if module.path == ENTRY:
            continue
        for scope, node in scoped(module.tree):
            if found := _configures(node):
                line, what = found
                yield Breach(
                    path=module.path, line=line, scope=scope, what=what, fix=FIX
                )


def _configures(node: ast.AST) -> tuple[int, str] | None:
    """Where and how node configures logging: a call, or a setting on the logger."""
    match node:
        case ast.Call(func=ast.Attribute(attr=name) | ast.Name(id=name) as func) if (
            name in CONFIGURES or dotted(func) == DISABLE
        ):
            return node.lineno, f"configures logging with {ast.unparse(func)}"
        case ast.Attribute(value=ast.Name(id="logger"), attr=name, ctx=ast.Store()) if (
            name in SETTINGS
        ):
            return node.lineno, f"configures logging by setting {ast.unparse(node)}"
        case _:
            return None


def test_only_main_configures_logging() -> None:
    check(configures_logging(project_root()))


def test_the_logging_guard_finds_a_module_configuring_logging(tmp_path: Path) -> None:
    write(
        tmp_path,
        "src/shop/payments/gateway.py",
        '''
        """Refunds, logging to a file of their own."""
        import logging
        from logging import captureWarnings

        logger = logging.getLogger(__name__)


        def setup() -> None:
            logging.basicConfig(level=logging.DEBUG)
            captureWarnings(True)
            logger.setLevel(logging.DEBUG)
            logger.propagate = False
            logging.disable(logging.INFO)
        ''',
    )

    assert report(configures_logging(tmp_path)) == [
        f"src/shop/payments/gateway.py:9: configures logging with logging.basicConfig: {FIX}",
        f"src/shop/payments/gateway.py:10: configures logging with captureWarnings: {FIX}",
        f"src/shop/payments/gateway.py:11: configures logging with logger.setLevel: {FIX}",
        f"src/shop/payments/gateway.py:12: configures logging by setting logger.propagate: {FIX}",
        f"src/shop/payments/gateway.py:13: configures logging with logging.disable: {FIX}",
    ]
```

## Guard 5: no patching in tests

A test that needs `monkeypatch.setattr` has found a missing seam ([python-testing](../../python-testing/SKILL.md)); `unittest.mock` is banned by ruff, so this guard covers the patch ruff can't see.

- **`setattr`, `delattr`, `setitem` and `delitem`, on every `MonkeyPatch`:** the fixture, a parameter annotated `pytest.MonkeyPatch` (ruff's `ANN` asks every parameter for one), and a name a `context()` of either is bound to. `patchers` searches until it finds no more, as a name can be bound from another. A project that builds one with `pytest.MonkeyPatch()` binds that name in `_bound` too.
- **`setenv` and `delenv` are allowed.** The environment is the process's input, not code, and the session fixture in [python-testing](../../python-testing/SKILL.md)'s isolation uses them to keep the machine's variables out. A component never needs them: it takes the value ([python-architecture](../../python-architecture/SKILL.md)).

```python
# tests/guards/test_patching.py
"""Tests pass a fake through a seam; they never patch an attribute or an item in place."""

...

FIX = "take the dependency as a parameter, and pass a fake from the test"
PATCHES = frozenset({"setattr", "delattr", "setitem", "delitem"})
MONKEYPATCH = frozenset({"pytest.MonkeyPatch", "MonkeyPatch"})


def patchers(tree: ast.Module) -> set[str]:
    """The names a MonkeyPatch goes by in tree, searched until no more are found."""
    names = {"monkeypatch"}
    while True:
        found = {name for node in ast.walk(tree) if (name := _bound(node, names))}
        if found <= names:
            return names
        names |= found


def _bound(node: ast.AST, patchers: set[str]) -> str | None:
    match node:
        case ast.arg(arg=name, annotation=ast.expr() as kind) if (
            dotted(kind) in MONKEYPATCH
        ):
            return name
        case ast.withitem(
            context_expr=ast.Call(func=ast.Attribute(value=owner, attr="context")),
            optional_vars=ast.Name(id=name),
        ) if dotted(owner) in MONKEYPATCH | patchers:
            return name
        case _:
            return None


def patches(root: Path) -> Iterator[Breach]:
    for module in modules(root, "tests"):
        names = patchers(module.tree)
        for scope, node in scoped(module.tree):
            match node:
                case ast.Call(func=ast.Attribute(value=owner, attr=method) as func) if (
                    method in PATCHES and dotted(owner) in names
                ):
                    yield Breach(
                        path=module.path,
                        line=node.lineno,
                        scope=scope,
                        what=f"patches with {ast.unparse(func)}",
                        fix=FIX,
                    )
                case _:
                    pass


def test_no_test_patches_an_attribute() -> None:
    check(patches(project_root()))


def test_the_patching_guard_finds_a_patch_on_any_monkeypatch(tmp_path: Path) -> None:
    write(
        tmp_path,
        "tests/test_cancel.py",
        '''
        """Cancelling, with what it reaches patched."""
        import sys

        import pytest


        def test_cancel_stamps_the_time(monkeypatch: pytest.MonkeyPatch) -> None:
            monkeypatch.setattr("shop.order.cancel.datetime", None)


        def test_cancel_offline(patcher: pytest.MonkeyPatch) -> None:
            patcher.setitem(sys.modules, "shop.payments", None)


        def test_cancel_from_the_environment() -> None:
            with pytest.MonkeyPatch.context() as env:
                env.setenv("SHOP_DATABASE", "shop.db")
                env.delattr("shop.order.cancel.logger")
        ''',
    )

    assert report(patches(tmp_path)) == [
        f"tests/test_cancel.py:8: patches with monkeypatch.setattr: {FIX}",
        f"tests/test_cancel.py:12: patches with patcher.setitem: {FIX}",
        f"tests/test_cancel.py:18: patches with env.delattr: {FIX}",
    ]
```

## Guard 6: domain names say what they are or do

An operation is named by its verb, a value by what it is, and neither repeats the package it sits in ([python-actions](../../python-actions/SKILL.md)). The guard reports a class with a suffix that says nothing, and a function whose name holds its package's last word: `cancel_order` in `shop.order`, `parse_money` in `shop.money`. Only the domain is read, and the guard takes its list from the import-linter `forbidden` contract in `pyproject.toml`, through `tomllib`, so the two can't drift apart; an adapter subclassing `logging.Handler` is rightly a Handler. A name is split on `_`, so `reorder` in `shop.order` passes. Modules are guard 1's: `shop/order/order.py` is rightly named for what an order is.

```python
# tests/guards/test_domain_names.py
"""Domain names say what they are or do: no OrderManager, no cancel_order."""

...

CONTRACT = "The domain imports no framework, client or wire library"
SUFFIXES = ("Service", "Manager", "Handler", "Processor")
FIX = "name an operation by its verb (Canceller), a value by what it is"
REPEATS = "drop the word its package already says (cancel, not cancel_order)"


def domain(root: Path) -> dict[str, str]:
    """The domain contract's packages and modules, as paths from root, with their last words."""
    config = tomllib.loads((root / "pyproject.toml").read_text(encoding="utf-8"))
    for contract in config["tool"]["importlinter"]["contracts"]:
        if contract["name"] == CONTRACT:
            return {
                f"src/{name.replace('.', '/')}": name.rpartition(".")[2]
                for name in contract["source_modules"]
            }
    pytest.fail(f"no import-linter contract named {CONTRACT!r}")


def package_of(path: str, packages: dict[str, str]) -> str | None:
    for base, word in packages.items():
        if path == f"{base}.py" or path.startswith(f"{base}/"):
            return word
    return None


def vague_names(root: Path) -> Iterator[Breach]:
    packages = domain(root)
    for module in modules(root, "src"):
        package = package_of(module.path, packages)
        if package is None:
            continue
        for scope, node in scoped(module.tree):
            match node:
                case ast.ClassDef(name=name) if name.endswith(SUFFIXES):
                    what, fix = f"class {name} is named for no job", FIX
                case ast.FunctionDef(name=name) | ast.AsyncFunctionDef(name=name) if (
                    package in name.strip("_").split("_")
                ):
                    what, fix = f"{name} repeats its package's name", REPEATS
                case _:
                    continue
            yield Breach(
                path=module.path,
                line=node.lineno,
                scope=qualify(scope, name),
                what=what,
                fix=fix,
            )


def test_domain_names_say_what_they_are_or_do() -> None:
    check(vague_names(project_root()))


def test_the_domain_name_guard_finds_a_manager_and_a_repeat(tmp_path: Path) -> None:
    write(
        tmp_path,
        "pyproject.toml",
        f'''
        [[tool.importlinter.contracts]]
        name = "{CONTRACT}"
        type = "forbidden"
        source_modules = ["shop.order", "shop.money"]
        forbidden_modules = ["pydantic"]
        ''',
    )
    write(
        tmp_path,
        "src/shop/order/manage.py",
        '''
        class OrderManager:
            def cancel_order(self) -> None: ...

            def reorder(self) -> None: ...
        ''',
    )
    write(tmp_path, "src/shop/money.py", "def parse_money() -> None: ...")
    write(tmp_path, "src/shop/logs/json.py", "class JsonHandler: ...")
    write(tmp_path, "src/shop/api.py", "def order_reply() -> None: ...")

    assert report(vague_names(tmp_path)) == [
        f"src/shop/money.py:1: parse_money repeats its package's name: {REPEATS}",
        f"src/shop/order/manage.py:1: class OrderManager is named for no job: {FIX}",
        f"src/shop/order/manage.py:2: cancel_order repeats its package's name: {REPEATS}",
    ]
```

## What syntax can't see

- **A name as written, not what it refers to.** `import logging as log` makes `log.getLogger` a different name to guard 2, which reports it; keep imports plain. A guard that must know what a name refers to needs the type checker, not `ast`.
- **What's built at runtime.** A call through `getattr`, `importlib` or a name held in a variable passes unseen, as it does past ruff's ban list. Guards catch the accidental case; review catches the deliberate one.
- **Another tool's rule.** Before writing a guard, check ruff's rules (`uv run ruff rule --all`) and import-linter's contracts: a setting beats a test.
