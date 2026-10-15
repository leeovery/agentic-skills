# Layout

**An application is a src-layout package: `shop/cli.py` for `main`, and a package per domain or adapter beside it, each module doing one job.**

Contents: from `uv init` to the tree · main, `__main__.py` and their test · naming · `__init__.py` · modules and packages · import cycles · `TYPE_CHECKING` · module state.

## From `uv init` to the tree

`uv init --package --python 3.14 shop` gives the src layout, a `uv_build` backend and one script ([python-project](../../python-project/SKILL.md)):

```
shop/
├── .python-version     3.14
├── pyproject.toml      [project.scripts] shop = "shop:main"
├── README.md
└── src/
    └── shop/
        └── __init__.py     def main(), printing a greeting
```

Grown into the shop's, `main` moves to `cli.py`, the script points at it (`shop = "shop.cli:main"`), and `__init__.py` keeps only its docstring:

```
shop/
├── pyproject.toml        the project, its script, its dependency groups, the tool config
├── uv.lock               every version, pinned; committed
├── .python-version
├── README.md
├── scripts/
│   └── gateway_status.py   a script with its dependencies inline, run by uv
├── src/
│   └── shop/
│       ├── __init__.py       its docstring
│       ├── __main__.py       python -m shop
│       ├── cli.py            build_app, run and main: the one place that wires and exits
│       ├── api.py            the HTTP handlers, framework-neutral
│       ├── consumers.py      the queue consumers
│       ├── settings.py       Settings: everything read from the environment
│       ├── display.py        how values read to people
│       ├── money.py          Money, in a currency's minor unit
│       ├── secret.py         a secret that never shows itself
│       ├── correlation.py    the id stamped on every log line a piece of work causes
│       ├── processes.py      programs run as child processes, as a Runner
│       ├── order/            orders: what one is, what goes wrong, what's done to one
│       │   ├── order.py      the ids, Status, Line and Order
│       │   ├── errors.py     OrderError, and each failure a caller branches on
│       │   ├── cancel.py     Canceller, and the OrderStore and Refunder it declares
│       │   └── ratios.py     figures worked out over many orders
│       ├── shipping/
│       │   └── label.py      shipping labels, made by the carrier's tool
│       ├── settlement/
│       │   ├── settle.py     settling payments many at once, on asyncio
│       │   └── poll.py       a job run on an interval beside other work
│       ├── payments/
│       │   ├── gateway.py    the provider's HTTP API, as a Refunder
│       │   └── settlements.py  the same API from async code, as a settlement Provider
│       └── storage/
│           ├── sqlite.py     orders in SQLite, as an OrderStore
│           └── atomic.py     replacing a file whole
└── tests/                    a package, mirroring src/shop: order/, payments/, guards/
```

- **Every package has an `__init__.py` holding only its docstring,** left out of the tree below `src/shop/`.
- **src layout.** Nothing named `shop` sits at the root, so a test imports the installed package, the one users get, never the tree beside it by accident.
- **Domain packages** (`order`, `shipping`, `settlement`) and the value modules beneath them (`money`, `secret`) hold types, their rules and their operations. **Adapters** (`payments`, `storage`, `processes`) implement the Protocols the domain declares ([python-protocols](../../python-protocols/SKILL.md)). **The edges** (`cli`, `api`, `consumers`) import the domain, and `cli` the adapters it wires; nothing imports an edge.
- **Each package and module sits in a layer** of the import-linter contract in [python-quality](../../python-quality/SKILL.md)'s drop-in config, highest first: `cli`; `api | consumers | settings`; `payments | storage | processes`; `display | shipping | settlement`; `order`; `money | secret | correlation`. A new one joins a layer in the same commit, with its reason.

## main, `__main__.py` and their test

`main` is in the [SKILL.md](../SKILL.md#pattern) pattern; `build_app` and `run` are [python-actions](../../python-actions/SKILL.md)'. Two ways in reach it:

```toml
[project.scripts]
shop = "shop.cli:main"
```

```python
# src/shop/__main__.py
"""Runs the shop's command line as `python -m shop`."""

from shop.cli import main

raise SystemExit(main())
```

- **The installer writes the `shop` command,** a wrapper that calls `sys.exit(main())`. `__main__.py` does the same for `python -m shop`, with `raise SystemExit`, as `sys.exit` is banned. Neither holds anything else, so there is nothing in them to test.
- **`main` returns the code; it never exits.** A test calls it and reads what it returns, and every `with` in the wiring has closed by then.
- **The wiring runs only when a command does.** `main` passes `from_environment` uncalled, so `--help`, a usage error and a test of the parser build nothing and read no settings.
- **A test runs `main` end to end** with argv, an environment scoped by `CliRunner().isolation`, and a database on `tmp_path`:

```python
# tests/test_cli.py
def test_main_exits_1_for_an_order_it_cant_find(tmp_path: Path) -> None:
    code, _, stderr = run_main(environment(tmp_path), ["cancel", "o-404"])

    assert code == 1
    assert stderr == "shop: order o-404 not found\n"
```

`run_main` calls `main` inside the isolation, and `environment` sets every `SHOP_` variable and unsets the rest; both are [python-testing](../../python-testing/SKILL.md)'s.

## Naming

- **A module is named for its job,** short and lower case: `cancel.py`, `ratios.py`, `gateway.py`. A package is named for its domain or for what it adapts: `order`, `payments`, `storage`.
- **Never a grab bag:** `utils`, `helpers`, `common`, `misc`, `shared`, `models`, `services`, `types`. Each collects one kind of code from every job, so every change touches it. python-quality's guard on names reports them.
- **Never a module per class.** `order_canceller.py` holding `OrderCanceller` names a noun; `cancel.py` holding `Canceller`, its guard and the Protocols it declares names the job.
- **A name doesn't repeat its package:** `shop.order` has `Status` and `Line`, not `OrderStatus` and `OrderLine`.
- **A module of functions is imported whole and called through,** so the call reads as a phrase: `from shop import display`, then `display.money(total)`. A class or type is imported by name: `from shop.order.cancel import Canceller`.
- **Absolute imports only.** `from . import cancel` hides which package a name comes from; ruff's `TID252` rejects it under the drop-in.

## `__init__.py`

**In an application,** every package's `__init__.py` holds its docstring and nothing else: one sentence on the package's job, which `D104` asks for. Callers import from the module that defines a name, so there is one path to it, and importing `shop.order` loads nothing it doesn't need.

**In a library,** the top package's `__init__.py` is the public surface. It imports what users may use and names each in `__all__`; everything else may move between releases ([python-project](../../python-project/SKILL.md) has the rest of a library):

```python
# src/shop/__init__.py, were shop a library
"""Cancel and refund a shop's orders.

Import what you need from here: the modules below are free to move.
"""

from shop.money import Money
from shop.order.cancel import Canceller, OrderStore, Refunder
from shop.order.errors import OrderError
from shop.order.order import Order, OrderId

__all__ = [
    "Canceller",
    "Money",
    "Order",
    "OrderError",
    "OrderId",
    "OrderStore",
    "Refunder",
]
```

ruff's `F401` (default) reports an import here that `__all__` doesn't name, so the list and the imports stay one surface.

## Modules and packages

- **Split a module when it takes a second job,** whatever its length. A 500-line module doing one job is fine; a 150-line one doing three is not.
- **Split a package when its callers use one part without the other.** Merge two when every caller imports both.
- **A helper is a private function** (`_parse_line`) in the module whose job it serves. Several packages needing it make it a module named for that one job.

## Import cycles

Python reports a cycle late, as `ImportError: cannot import name ... from partially initialized module`, and only for the import order that happens to run. import-linter's layers report it early, as an import pointing the wrong way. Either way, it means a module reaches the wrong way:

- **A lower module calls up.** `Money.__str__` calling `display.money` makes `shop.money` import `shop.display`, which imports `shop.money`. The wording is `display`'s job: delete `__str__`, and callers word an amount with `display.money(amount)`.
- **Two domain packages call each other.** The one that needs a single method declares a Protocol for it beside its use, and `main` passes the other's implementation ([python-protocols](../../python-protocols/SKILL.md)). Or the shared type moves to the package whose job it is.
- **An adapter's type leaked into the domain.** Move it back to the adapter, and map it at the edge ([python-types](../../python-types/SKILL.md)).
- **Never a new `types`, `models` or `common` module** to hold what both need. That is the grab bag rule 4 bans, made to order.

## `TYPE_CHECKING`

No `if TYPE_CHECKING:` imports in an application.

- **On 3.14 an annotation is evaluated only when something reads it** ([PEP 649](https://peps.python.org/pep-0649/)), so a forward reference needs neither quotes nor a guarded import.
- **A guarded import hides a cycle instead of fixing it.** import-linter still counts it, as it reads `TYPE_CHECKING` blocks unless told not to.
- **pydantic models and typer commands read their annotations at runtime.** A name imported only for the checker passes pyrefly, then fails: a `BaseSettings` raises `PydanticUserError` when it's first constructed, and a typer command raises `NameError` when it runs. Under the drop-in, ruff's `TC004` catches the first; nothing static catches the second, so a test that runs each command is what does. `TC001` to `TC003`, which would move imports under `TYPE_CHECKING`, are off.

## Module state

- **A module body declares:** imports, constants, functions, classes, and `logger = logging.getLogger(__name__)`. python-quality's guard on import-time calls holds it.
- **No mutable module state:** no registry filled by decorators, no cache dict, no client or connection built once and shared. Each is a dependency no signature shows, and it carries from one test into the next.
- **The logger is the one module-level object,** as a logger is a name, not configuration; `main` configures logging once ([python-errors](../../python-errors/SKILL.md)).
