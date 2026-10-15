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
- **Each breach says where, what, and the fix:** `src/shop/storage/sqlite.py:45: calls connect at import: call it from main, or from the function that needs it`. `check` fails once with every breach, one per line, and no traceback.
- **Its allowance only shrinks.** Each entry is `"path:qualname"`, with a comment saying why it's allowed for now. An entry covers every breach in that def or class; one that covers nothing fails, so it's deleted the day the code is fixed.
- **It's been seen to fail.** A second test writes a module that breaks the rule under `tmp_path`, runs the guard over it, and checks it reports exactly that, and a stale allowance entry too where the guard keeps an allowance, as guards 2 and 3 do. A guard reading the wrong directory otherwise passes forever.

## Where the guards live, and how they're imported

Each guard is a module in `tests/guards/`, beside `source.py`, the helpers. `tests/` is a package, every directory of it with an `__init__.py` (`INP001` asks for them anyway), and pytest runs with `--import-mode=prepend`. pytest puts the first directory above the tests without an `__init__.py`, the project root, on `sys.path`, so `from tests.guards.source import check` resolves. `shop` still comes from the install: in the src layout nothing named `shop` sits at the root, and `pythonpath = ["src"]` is never set.

`--import-mode=importlib`, which pytest suggests for new projects, leaves `sys.path` alone, and its docs say helpers under `tests/` then can't be imported and belong in the application package, which would ship them in the wheel. pytest 9 does resolve `tests.guards.source` in that mode, through the packages' `__init__.py` files, but its docs promise the opposite, so the guards don't lean on it. Under `tests/`, the helpers never reach the wheel, and pyrefly finds them through `search-path = ["src", "."]` in [pyproject-tools.toml](pyproject-tools.toml).

## The helpers

`report` is for the tests that prove a guard fails, which compare its list; `check` is for the guard's run over the project.

```python
# tests/guards/source.py
"""Reading the project's own source, for the guards that hold its rules.

Only tests import this. A guard parses the modules under src/ or tests/, walks
them, and reports each breach as "path:line: what: the fix".
"""

import ast
import textwrap
from collections.abc import Iterable, Iterator
from dataclasses import dataclass
from pathlib import Path

import pytest

TOP = "<module>"


def project_root() -> Path:
    """The directory holding pyproject.toml, found from this file upward."""
    for directory in Path(__file__).resolve().parents:
        if (directory / "pyproject.toml").is_file():
            return directory
    pytest.fail("no pyproject.toml above the guards")


@dataclass(frozen=True, slots=True, kw_only=True)
class Module:
    """One of the project's Python files, parsed. Its path is from the root, with slashes."""

    path: str
    tree: ast.Module


def modules(root: Path, under: str) -> list[Module]:
    """Parse every .py file below root/under, but those in a directory starting with "."."""
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
    """The qualname of name, defined in scope: "Canceller.cancel"."""
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
    """Where a rule breaks, in which def or class, what breaks it, and the fix."""

    path: str
    line: int
    scope: str
    what: str
    fix: str

    def __str__(self) -> str:
        return f"{self.path}:{self.line}: {self.what}: {self.fix}"


def report(breaches: Iterable[Breach], *, allowed: Iterable[str] = ()) -> list[str]:
    """Each breach no allowance entry covers, then each entry that covers nothing.

    An entry is "path:qualname", such as "src/shop/report.py:Yearly.render", and
    covers every breach in that def or class. An entry with no breach left is
    reported, so it's deleted and the allowance only shrinks.
    """
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
    """Fail with what report finds, one per line, so every breach shows at once."""
    if problems := report(breaches, allowed=allowed):
        pytest.fail("\n".join(problems), pytrace=False)


def write(root: Path, name: str, source: str) -> None:
    """Write source, dedented, to root/name: a fixture that proves a guard fails."""
    path = root / name
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(textwrap.dedent(source).lstrip("\n"), encoding="utf-8")
```

## Guard 1: modules named for a job

A grab-bag name gives code no reason to live anywhere else ([python-architecture](../../python-architecture/SKILL.md)). A package is named by its `__init__.py`, so it's reported once. It's the [SKILL.md](../SKILL.md#pattern) pattern, there without its imports.

```python
# tests/guards/test_names.py
"""Modules and packages are named for the one job they do."""

from collections.abc import Iterator
from pathlib import Path, PurePosixPath

from tests.guards.source import TOP, Breach, check, modules, project_root, report, write

# These name no job, so any code fits under them.
GRAB_BAGS = frozenset(
    {
        "common",
        "helpers",
        "misc",
        "models",
        "services",
        "shared",
        "types",
        "util",
        "utils",
    }
)
FIX = "name it for the one thing it does, or move each part to the module it serves"


def grab_bags(root: Path) -> Iterator[Breach]:
    """The modules and packages in root's src/ named for no job."""
    for module in modules(root, "src"):
        path = PurePosixPath(module.path)
        is_package = path.stem == "__init__"
        name = path.parent.name if is_package else path.stem
        if name in GRAB_BAGS:
            kind = "package" if is_package else "module"
            yield Breach(
                path=module.path,
                line=1,
                scope=TOP,
                what=f"{kind} {name} names no job",
                fix=FIX,
            )


def test_modules_are_named_for_their_job() -> None:
    check(grab_bags(project_root()))


def test_the_naming_guard_finds_a_grab_bag(tmp_path: Path) -> None:
    write(tmp_path, "src/shop/utils/__init__.py", '"""Odds and ends."""')
    write(tmp_path, "src/shop/order/helpers.py", '"""Odds and ends."""')
    write(tmp_path, "src/shop/order/cancel.py", '"""Cancelling an order."""')

    assert report(grab_bags(tmp_path)) == [
        f"src/shop/order/helpers.py:1: module helpers names no job: {FIX}",
        f"src/shop/utils/__init__.py:1: package utils names no job: {FIX}",
    ]
```

## Guard 2: nothing runs at import

A module body declares: imports, constants, functions, classes and its logger. A settings object, a client, a connection or a registration at module level runs in every test that imports it, before any test can stop it ([python-architecture](../../python-architecture/SKILL.md)).

- Decorators and default values run with the `def`; the body doesn't. A class body runs.
- From 3.14 annotations are evaluated lazily ([PEP 649](https://peps.python.org/pep-0649/)), so a call inside one doesn't run at import, and nor does a `type` alias's value.
- `DECLARATIONS` are calls that build a type, a logger, a context variable or a constant and touch nothing outside the process: `frozenset`, `tuple` and `re.compile` for constants, and `ContextVar` because the [contextvars docs](https://docs.python.org/3/library/contextvars.html) ask for one at module level. A name joins only on that test, with the project's own: `ConfigDict` and `SettingsConfigDict` are pydantic's declarations of how a boundary model parses and where settings come from, and `Field`, `field_validator` and `model_validator` declare a field's constraints and a model's checks, which run when a model parses, never at import. `TypeVar` stays out: on a 3.12 floor a type parameter replaces it (`UP046`, `UP047`).
- A name matches as it's written, `re.compile` not `compile`, and a call among an allowed call's arguments is still checked, so `frozenset(open(path))` fails.
- A `__main__.py` is run, never imported, so it's skipped.

```python
# tests/guards/test_import_time.py
"""Nothing runs at import: a module declares, and main does the work."""

import ast
from collections.abc import Iterator
from pathlib import Path

from tests.guards.source import (
    TOP,
    Breach,
    check,
    dotted,
    modules,
    project_root,
    qualify,
    report,
    write,
)

# Calls that build a type, a logger, a context variable or a constant: each touches
# nothing outside the process and builds the same thing on every import. A call among
# their arguments is checked like any other. The contextvars docs ask for a ContextVar
# to be made at module level, never in a closure. pydantic's Field and validator
# decorators declare how a model parses, as its ConfigDict does.
DECLARATIONS = frozenset(
    {
        "NewType",
        "dataclass",
        "field",
        "logging.getLogger",
        "ContextVar",
        "ConfigDict",
        "SettingsConfigDict",
        "Field",
        "field_validator",
        "model_validator",
        "frozenset",
        "re.compile",
        "timedelta",
        "tuple",
    }
)
FIX = "call it from main, or from the function that needs it"

# Calls let stand for now, each "path:qualname" with why. The set only shrinks.
ALLOWED: frozenset[str] = frozenset()


def calls_at_import(root: Path) -> Iterator[Breach]:
    """The calls root's src/ modules make as they're imported, but declarations."""
    for module in modules(root, "src"):
        if module.path.endswith("/__main__.py"):
            continue  # a __main__ module is run, never imported
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
            # Decorators and defaults run with the def; the body runs when it's called.
            parts = [*node.decorator_list, *node.args.defaults, *node.args.kw_defaults]
        case ast.Lambda():
            parts = [*node.args.defaults, *node.args.kw_defaults]
        case ast.ClassDef():
            parts = [*node.decorator_list, *node.bases, *node.keywords]
            for statement in node.body:
                yield from _runs(statement, qualify(scope, node.name))
        case ast.AnnAssign():
            # Annotations are evaluated lazily from 3.14, so only the value runs.
            parts = [node.value]
        case ast.TypeAlias():
            parts = []  # evaluated lazily, like an annotation
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


def test_the_import_guard_lets_declarations_stand(tmp_path: Path) -> None:
    write(
        tmp_path,
        "src/shop/prices.py",
        '''
        """Prices: each declaration the guard allows, and a file read inside one."""
        import logging
        import re
        from contextvars import ContextVar
        from dataclasses import dataclass, field
        from datetime import timedelta
        from typing import NewType, Self

        from pydantic import BaseModel, ConfigDict, Field, field_validator, model_validator
        from pydantic_settings import BaseSettings, SettingsConfigDict

        logger = logging.getLogger(__name__)
        PRICE_LIST: ContextVar[str] = ContextVar("price_list", default="retail")
        Sku = NewType("Sku", str)
        SKU = re.compile(r"[A-Z]{3}-[0-9]{4}")
        SYMBOLS = {"GBP": "£", "EUR": "€"}
        CURRENCIES = tuple(SYMBOLS)
        ZERO_DECIMAL = frozenset({"JPY", "KRW"})
        BLOCKED = frozenset(open("blocked.txt"))


        class Settings(BaseSettings):
            model_config = SettingsConfigDict(env_prefix="SHOP_")
            grace: timedelta = timedelta(days=14)


        class PriceRequest(BaseModel):
            model_config = ConfigDict(extra="forbid", frozen=True, strict=True)
            sku: Sku
            quantity: int = Field(gt=0)

            @field_validator("sku")
            @classmethod
            def known(cls, sku: Sku) -> Sku:
                return sku

            @model_validator(mode="after")
            def priced(self) -> Self:
                return self


        @dataclass(frozen=True, slots=True)
        class Basket:
            skus: list[Sku] = field(default_factory=list)
        ''',
    )

    assert report(calls_at_import(tmp_path)) == [
        f"src/shop/prices.py:19: calls open at import: {FIX}",
    ]
```

## Guard 3: values worded by display

How a value reads to people is `shop.display`'s to say. Everywhere else, a format spec, `format()` or `strftime()` is a copy of that rule that will drift ([python-architecture](../../python-architecture/SKILL.md)). ruff's `UP031` and `UP032`, in the default set, flag `%` formatting and a literal's `str.format`, so specs end up in f-strings, where this guard reads them, as it does in a t-string's interpolations. A `.format()` on a template held in a name, which ruff leaves alone, and `strftime` imported from `time`, it reports by the name called. A stored or sent value has an encoder of its own, `isoformat()` and the like, never a display function ([python-types](../../python-types/SKILL.md)).

```python
# tests/guards/test_wording.py
"""Values are worded for people by shop.display alone."""

import ast
from collections.abc import Iterator
from pathlib import Path

from tests.guards.source import (
    Breach,
    check,
    modules,
    project_root,
    report,
    scoped,
    write,
)

DISPLAY = "src/shop/display.py"
FIX = "call shop.display"


def wording(root: Path) -> Iterator[Breach]:
    """Where root's src/ modules, but display, word a value themselves."""
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
    """Where and how node words a value: a format spec, format(), str.format() or strftime().

    A spec is read in an f-string and in a t-string alike. A method goes by its
    name, as the guard can't see the type it's called on.
    """
    match node:
        case (
            ast.FormattedValue(format_spec=ast.JoinedStr())
            | ast.Interpolation(format_spec=ast.JoinedStr())
        ):
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
        from string.templatelib import Template
        from time import strftime

        LINE = "{:.2f} sold"


        def headline(total: int, day: datetime) -> str:
            return f"{total / 100:.2f} on {day.strftime('%d %b')}"


        def banner(total: int) -> Template:
            return t"{total / 100:.2f} today"


        def summary(total: int) -> str:
            return LINE.format(total / 100) + strftime(" at %H:%M")


        def footer(rate: float) -> str:
            return format(rate, ".1%")
        ''',
    )

    assert report(wording(tmp_path), allowed={"src/shop/report.py:footer"}) == [
        f"src/shop/report.py:10: words a value with a format spec: {FIX}",
        f"src/shop/report.py:10: words a time with strftime(): {FIX}",
        f"src/shop/report.py:14: words a value with a format spec: {FIX}",
        f"src/shop/report.py:18: words a value with str.format(): {FIX}",
        f"src/shop/report.py:18: words a time with strftime(): {FIX}",
    ]
```

## Guard 4: logging configured by main

`main` configures logging once; a module names its logger and nothing more ([python-errors](../../python-errors/SKILL.md)). It goes by the name called, so `from logging import captureWarnings` is caught as well as `logging.basicConfig`, and `addHandler` or `setLevel` on any logger; `logging.disable` only by its whole name, as `disable` alone is too common a word. A logger changed rather than called is caught too: `propagate`, `disabled`, `level` or `handlers` assigned, and a call on its `handlers` list, such as `handlers.clear()`. Its fixture has each form. ruff's `LOG015`, in the default set, catches a call on the root logger.

```python
# tests/guards/test_logging.py
"""Logging is configured once, by main; every other module only names its logger."""

import ast
from collections.abc import Iterator
from pathlib import Path

from tests.guards.source import (
    Breach,
    check,
    dotted,
    modules,
    project_root,
    report,
    scoped,
    write,
)

ENTRY = "src/shop/cli.py"
# Called by these names on any logger or handler, or imported from logging.
CONFIGURES = frozenset(
    {
        "addFilter",
        "addHandler",
        "basicConfig",
        "captureWarnings",
        "dictConfig",
        "fileConfig",
        "removeFilter",
        "removeHandler",
        "setLevel",
        "setLoggerClass",
        "setLogRecordFactory",
    }
)
# Too common a word to go by alone, so only as logging's own.
DISABLE = "logging.disable"
# A logger's own settings, changed by assigning them.
SETTINGS = frozenset({"disabled", "handlers", "level", "propagate"})
FIX = "leave it to main; a module only calls logging.getLogger(__name__)"


def configures_logging(root: Path) -> Iterator[Breach]:
    """The calls and assignments in root's src/ modules, but main's, that configure logging."""
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
    """Where and how node configures logging: a call, a logger's handlers changed, a setting."""
    match node:
        case ast.Call(func=ast.Attribute(attr=name) | ast.Name(id=name) as func) if (
            name in CONFIGURES or dotted(func) == DISABLE
        ):
            return node.lineno, f"configures logging with {ast.unparse(func)}"
        case ast.Call(func=ast.Attribute(value=ast.Attribute(attr="handlers")) as func):
            return node.lineno, f"configures logging with {ast.unparse(func)}"
        case ast.Attribute(attr=name, ctx=ast.Store()) if name in SETTINGS:
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
        import logging.config
        from logging import captureWarnings

        logger = logging.getLogger(__name__)


        def setup() -> None:
            logging.basicConfig(level=logging.DEBUG)
            logging.config.dictConfig({"version": 1})
            captureWarnings(True)
            logger.addHandler(logging.FileHandler("refunds.log"))
            logger.setLevel(logging.DEBUG)
            logger.propagate = False
            logging.getLogger("httpx2").handlers.clear()
            logging.disable(logging.INFO)
        ''',
    )

    assert report(configures_logging(tmp_path)) == [
        f"src/shop/payments/gateway.py:10: configures logging with logging.basicConfig: {FIX}",
        f"src/shop/payments/gateway.py:11: configures logging with logging.config.dictConfig: {FIX}",
        f"src/shop/payments/gateway.py:12: configures logging with captureWarnings: {FIX}",
        f"src/shop/payments/gateway.py:13: configures logging with logger.addHandler: {FIX}",
        f"src/shop/payments/gateway.py:14: configures logging with logger.setLevel: {FIX}",
        f"src/shop/payments/gateway.py:15: configures logging by setting logger.propagate: {FIX}",
        f"src/shop/payments/gateway.py:16: configures logging with logging.getLogger('httpx2').handlers.clear: {FIX}",
        f"src/shop/payments/gateway.py:17: configures logging with logging.disable: {FIX}",
    ]
```

## Guard 5: no patching in tests

A test that needs `monkeypatch.setattr` has found a missing seam ([python-testing](../../python-testing/SKILL.md)); `unittest.mock` is banned by ruff, so this guard covers the patch ruff can't see.

- **Every way to patch, on every `MonkeyPatch`:** `setattr`, `delattr`, `setitem` and `delitem`, on the `monkeypatch` fixture, on a parameter or a variable of any name annotated `pytest.MonkeyPatch`, on a name bound to one built in place, `pytest.MonkeyPatch()` or a `context()` of the class or of the fixture, by a `with`, an `=` or a `:=`, and on one built in the call itself, `pytest.MonkeyPatch().setattr(...)`. A name bound from another found counts too, so `patchers` searches until it finds no more. Its fixture has each form.
- **`setenv` and `delenv` are allowed.** The environment is the process's input, not code, and the session fixture in [python-testing](../../python-testing/SKILL.md)'s isolation uses them to keep the machine's variables out. A test of a component never needs them: the component takes the value ([python-architecture](../../python-architecture/SKILL.md)).
- **It knows a `MonkeyPatch` by its annotation,** which ruff's `ANN` asks of every parameter, so a fixture passed on under another name is still found.

```python
# tests/guards/test_patching.py
"""Tests pass a fake through a seam; they never patch an attribute or an item in place."""

import ast
from collections.abc import Iterator
from pathlib import Path

from tests.guards.source import (
    Breach,
    check,
    dotted,
    modules,
    project_root,
    report,
    scoped,
    write,
)

FIX = "take the dependency as a parameter, and pass a fake from the test"
# setenv and delenv aren't among them: the environment is what the process is
# given, not code, and the session fixture uses them to keep the machine's out.
PATCHES = frozenset({"setattr", "delattr", "setitem", "delitem"})
MONKEYPATCH = frozenset({"pytest.MonkeyPatch", "MonkeyPatch"})


def patchers(tree: ast.Module) -> set[str]:
    """The names a MonkeyPatch goes by in tree.

    The fixture's own; any name annotated as one, which ruff's ANN asks of every
    parameter; and any name bound to one made in place, by MonkeyPatch() or by a
    context() of the class or of one, in a with, an assignment or a :=. A name
    bound from another found here counts too, so the search runs until it finds
    no more.
    """
    names = {"monkeypatch"}
    while True:
        found = {name for node in ast.walk(tree) if (name := _bound(node, names))}
        if found <= names:
            return names
        names |= found


def _bound(node: ast.AST, patchers: set[str]) -> str | None:
    """The name node binds to a MonkeyPatch, if it binds one."""
    match node:
        case (
            ast.arg(arg=name, annotation=ast.expr() as kind)
            | ast.AnnAssign(target=ast.Name(id=name), annotation=ast.expr() as kind)
        ) if dotted(kind) in MONKEYPATCH:
            return name
        case (
            ast.withitem(context_expr=made, optional_vars=ast.Name(id=name))
            | ast.Assign(targets=[ast.Name(id=name)], value=made)
            | ast.NamedExpr(target=ast.Name(id=name), value=made)
        ) if _makes(made, patchers):
            return name
        case _:
            return None


def _makes(node: ast.expr, patchers: set[str]) -> bool:
    """Whether node makes a MonkeyPatch: MonkeyPatch(), or a context() of the class or of one."""
    match node:
        case ast.Call(func=func) if dotted(func) in MONKEYPATCH:
            return True
        case ast.Call(func=ast.Attribute(value=owner, attr="context")):
            return dotted(owner) in MONKEYPATCH or _is_patcher(owner, patchers)
        case _:
            return False


def _is_patcher(node: ast.expr, patchers: set[str]) -> bool:
    """Whether node is a MonkeyPatch: a name one goes by, or one made in place."""
    match node:
        case ast.Name(id=name):
            return name in patchers
        case _:
            return _makes(node, patchers)


def patches(root: Path) -> Iterator[Breach]:
    """Each setattr, delattr, setitem and delitem on a MonkeyPatch in root's tests/."""
    for module in modules(root, "tests"):
        names = patchers(module.tree)
        for scope, node in scoped(module.tree):
            match node:
                case ast.Call(func=ast.Attribute(value=owner, attr=method) as func) if (
                    method in PATCHES and _is_patcher(owner, names)
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
            monkeypatch.delattr("shop.order.cancel.logger")


        def test_cancel_offline(patcher: pytest.MonkeyPatch) -> None:
            patcher.setitem(sys.modules, "shop.payments", None)
            patcher.delitem(sys.modules, "shop.storage")


        def test_cancel_from_the_environment() -> None:
            with pytest.MonkeyPatch.context() as env:
                env.setenv("SHOP_DATABASE", "shop.db")
                env.delenv("SHOP_GATEWAY_URL")
                env.setattr("shop.order.cancel.datetime", None)


        def test_cancel_in_a_block(monkeypatch: pytest.MonkeyPatch) -> None:
            with monkeypatch.context() as inside:
                inside.setattr("shop.order.cancel.logger", None)


        def test_cancel_with_a_patcher_of_its_own() -> None:
            mp = pytest.MonkeyPatch()
            mp.setattr("shop.order.cancel.datetime", None)
            pytest.MonkeyPatch().delitem(sys.modules, "shop.payments")
            mp.undo()
        ''',
    )

    assert report(patches(tmp_path)) == [
        f"tests/test_cancel.py:8: patches with monkeypatch.setattr: {FIX}",
        f"tests/test_cancel.py:9: patches with monkeypatch.delattr: {FIX}",
        f"tests/test_cancel.py:13: patches with patcher.setitem: {FIX}",
        f"tests/test_cancel.py:14: patches with patcher.delitem: {FIX}",
        f"tests/test_cancel.py:21: patches with env.setattr: {FIX}",
        f"tests/test_cancel.py:26: patches with inside.setattr: {FIX}",
        f"tests/test_cancel.py:31: patches with mp.setattr: {FIX}",
        f"tests/test_cancel.py:32: patches with pytest.MonkeyPatch().delitem: {FIX}",
    ]
```

## Guard 6: domain names say what they are or do

An operation is named by its verb, a value by what it is, and neither repeats the package it sits in ([python-actions](../../python-actions/SKILL.md)). The guard reports a class with a suffix that says nothing, and a function whose name holds its package's last word: `cancel_order` in `shop.order`, `parse_money` in `shop.money`. Only the domain is read, and the guard takes its list from the import-linter `forbidden` contract in `pyproject.toml`, through `tomllib`, so the two can't drift apart; an adapter subclassing `logging.Handler` is rightly a Handler.

```python
# tests/guards/test_domain_names.py
"""Domain names say what they are or do: no OrderManager, no cancel_order."""

import ast
import tomllib
from collections.abc import Iterator
from pathlib import Path

import pytest

from tests.guards.source import (
    Breach,
    check,
    modules,
    project_root,
    qualify,
    report,
    scoped,
    write,
)

# Its source modules are the domain. An adapter may need a suffix: a
# logging.Handler subclass is a Handler.
CONTRACT = "The domain imports no framework, client or wire library"
SUFFIXES = ("Service", "Manager", "Handler", "Processor")
FIX = "name an operation by its verb (Canceller), a value by what it is"
REPEATS = "drop the word its package already says (cancel, not cancel_order)"


def domain(root: Path) -> dict[str, str]:
    """Each domain package and module, as its path from root, and its name's last word.

    They're read from the import-linter contract that keeps frameworks out of
    the domain, so the guard and the contract can't disagree.
    """
    config = tomllib.loads((root / "pyproject.toml").read_text(encoding="utf-8"))
    for contract in config["tool"]["importlinter"]["contracts"]:
        if contract["name"] == CONTRACT:
            return {
                f"src/{name.replace('.', '/')}": name.rpartition(".")[2]
                for name in contract["source_modules"]
            }
    pytest.fail(f"no import-linter contract named {CONTRACT!r}")


def package_of(path: str, packages: dict[str, str]) -> str | None:
    """The last word of the domain package or module that holds path, if one does."""
    for base, word in packages.items():
        if path == f"{base}.py" or path.startswith(f"{base}/"):
            return word
    return None


def vague_names(root: Path) -> Iterator[Breach]:
    """Domain classes named for no job, and functions that repeat their package."""
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
        """Everything to do with orders."""


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
        f"src/shop/order/manage.py:4: class OrderManager is named for no job: {FIX}",
        f"src/shop/order/manage.py:5: cancel_order repeats its package's name: {REPEATS}",
    ]
```

- **Words, not letters.** The name is split on `_`, so `reorder` and `reordered` in `shop.order` pass, and `cancel_order` doesn't.
- **Functions and classes, not modules.** `shop/order/order.py` holds what an order is, and is rightly named for it; guard 1 holds module names to a job.
- **Its fixture writes a `pyproject.toml` too,** with the contract the guard looks for, so the fixture's domain is what the guard reads.

## What syntax can't see

- **A name as written, not what it refers to.** `import logging as log` makes `log.getLogger` a different name to guard 2, which reports it; keep imports plain. A guard that must know what a name refers to needs the type checker, not `ast`.
- **What's built at runtime.** A call through `getattr`, `importlib` or a name held in a variable passes unseen, as it does past ruff's ban list. Guards catch the accidental case; review catches the deliberate one.
- **Another tool's rule.** Before writing a guard, check ruff's rules (`uv run ruff rule --all`) and import-linter's contracts: a setting beats a test.
