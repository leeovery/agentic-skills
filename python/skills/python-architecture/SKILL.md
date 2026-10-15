---
name: python-architecture
description: Python package layout and ownership — src layout, a package per domain, one owner per rule, nothing at import, and main the one place that wires, reads settings and exits. Use when starting a project, deciding where code lives, adding a package, module or setting, wording a value for display, or finding one rule computed in two places.
---

# Python Architecture

Every package has one job and every rule one owner. Nothing happens at import, and `main` is the one place that wires the program, reads its settings and exits.

## Core rules

1. **One owner per rule.** A meaning two callers need (how a value reads, whether an order can be cancelled, a cancellation rate) is one function or property, and the second caller calls it. A copy you find is lifted into the owner, never patched where it sits. *Two copies drift: the CLI rounds a ratio one way, a report the other, and each looks right alone.* Where a raw read outside the owner is the risk, a source guard holds it ([python-quality](../python-quality/SKILL.md)).
2. **Each kind of value people read is worded by one function in `shop.display`.** Money, instants and ratios go through `display.money`, `display.instant` and `display.percent`; no other module holds a format spec, `format()` or `strftime()`. A stored or sent value has its own encoder, never a display function. *Wording is the rule copied most, and its copies disagree in the last digit.* Held by a source guard, in python-quality.
3. **Shared rules come first.** When work is split across agents or PRs, list the meanings more than one part needs and build each once, with tests, before the parts start. *Otherwise each part rebuilds the meaning locally, and review is where the rule gets found.*
4. **src layout, a package per domain, a module per job.** `src/shop/order/` holds orders, and `cancel.py` cancelling them; each package's docstring states its job. Never `utils`, `helpers`, `common`, `misc`, `shared`, `models`, `services` or `types`, and never a module per class. *A grab-bag name gives code no reason to live anywhere else, a module per class scatters one job across files named for nouns, and under `src/` the tests import the installed package, not the tree beside them.* Held by a source guard on names, in python-quality, and ruff's `INP001` and `D104`; a module per class is review's to catch.
5. **`main(argv=None) -> int` in `shop/cli.py`, named in `[project.scripts]`, is the composition root.** It hands the CLI a wiring that, once a command runs, reads the settings, configures logging, and gives that command what opens its own dependencies; it returns the exit code that `run` sets ([python-actions](../python-actions/SKILL.md)). Nothing else reads the environment or exits. *A test calls `main` with argv and a scoped environment and checks the code it returns; the wrapper the installer writes exits with it.* Held by ruff's `banned-api` (`TID251`): `sys.exit`, `os.environ`, `os.getenv` and `datetime.now` everywhere but `shop/cli.py` and `shop/processes.py`, whose lift is for `subprocess`.
6. **Nothing runs at import.** A module body holds imports, constants, functions, classes and its logger: no settings object, client, connection, `basicConfig`, `load_dotenv()`, registration or I/O. *Whatever runs at import runs in every test that imports the module, before the test can choose its inputs, in an order nobody chose.* Held by a source guard, in python-quality.
7. **Config is one frozen `BaseSettings`, built in `main`'s wiring and nowhere else.** Every missing or bad value is reported at once, and a secret, or anything that says where data goes, has no default. `main` passes each component the values it takes, never the settings object. *A setting read where it's used is a dependency no signature shows, fails mid-run instead of at start, and can't be chosen by a test.* Held by import-linter's `layers`: only `shop.cli` sits above `shop.settings`.
8. **Imports point inward, and are absolute.** `shop.order` and `shop.money` never import the CLI, the settings, an adapter, pydantic, typer or httpx2; the edges import them. A cycle is fixed by moving a type to its owner, or by a Protocol at the consumer, never by a `types` module or an `if TYPE_CHECKING:` import. *The domain is tested without a transport, and a cycle under `TYPE_CHECKING` is hidden, not gone.* Held by import-linter's `layers` and `forbidden` contracts, and ruff's `TID252`.
9. **An application's `__init__.py` holds only its docstring; a library's declares its surface with `__all__`.** *An application has no importers, so a re-export is a second path to one name; a library's users import from the package, so whatever `__all__` leaves out is free to move.* ruff's `F401` (default) reports an import in a library's `__init__.py` that `__all__` doesn't name.

## Pattern

```python
# src/shop/cli.py: build_app and run, the CLI's shape and exit codes, are python-actions'
...


def from_environment() -> Deps:
    """Wire the commands from the SHOP_ environment variables.

    Reads the settings and configures logging. Each command opens what it uses
    when it runs: cancel the database and a client, settle an async client.
    """
    settings = read_settings()
    stderr = logging.StreamHandler()
    stderr.addFilter(CorrelationFilter())
    logging.basicConfig(
        level=settings.log_level,
        format="%(levelname)s %(name)s [%(correlation_id)s]: %(message)s",
        handlers=[stderr],
    )
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
        settle=partial(
            settle_payments,
            partial(httpx2.AsyncClient, base_url=url, headers=headers, timeout=timeout),
        ),
    )


...


def main(argv: Sequence[str] | None = None) -> int:
    """Run the command line on argv, or on the process's own arguments.

    The run is one piece of work, so it has one correlation id.
    """
    with CORRELATION_ID.set(str(uuid.uuid7())):
        return run(build_app(from_environment), argv)
```

`pyproject.toml` names it, `shop = "shop.cli:main"`, and `src/shop/__main__.py` runs `raise SystemExit(main())`. A command calls the wiring only when it runs, so `--help` and a usage error need no settings, and each command opens only its own dependencies: `_canceller` opens the database and the client when `cancel` enters it, and closes both after.

## Where it goes

| You're adding | It goes |
|---|---|
| a figure or decision another module already makes | a call to its owner; lift it there first if it sits inline |
| how a value reads to people | `shop.display`, beside its siblings |
| how a value is stored or sent | its own encoder beside the type, pinned by a test; never the display one |
| a domain type, its rules and its operations | the package named for the domain (`shop.order`), a module per job |
| a CLI command, an HTTP handler, a consumer | the edge (`shop.cli`), calling the domain |
| an adapter for an outside service or a store | a package named for it (`shop.payments`, `shop.storage`), implementing the domain's Protocol |
| a small helper | a private function in the module whose job it serves |
| a setting, the environment, the logger's config | `Settings`, read in `main`'s wiring, values passed down |
| a new module or package | a layer in the import-linter contract, in the same commit, with its reason |

## Anti-patterns

- ❌ Copying the sum or the `:.1f` from the screen that already shows a figure → call its owner; lift it first if it's inline.
- ❌ Patching the copy that's wrong → delete it, call the owner, and test the case where the copies differed.
- ❌ A `types.py` or `models/` to break an import cycle → move the type to the module whose job it is, or declare a Protocol beside the code that calls it.
- ❌ `if TYPE_CHECKING:` around an import that makes a cycle → the cycle is still there; fix the direction.
- ❌ A package per layer (`services/`, `repositories/`, `schemas/`) → a package per domain, its jobs as modules.
- ❌ `order_service.py` holding `class OrderService` → `cancel.py` holding `Canceller`.
- ❌ `settings = Settings()`, `client = httpx2.Client()` or `logging.basicConfig()` at module level → built in `main`'s wiring and passed in.
- ❌ `os.environ["SHOP_DATABASE"]` where the value is used → a field on `Settings`, its value passed as a parameter.
- ❌ `sys.exit(1)` in a command or a helper → raise; `run` maps it to the code and `main` returns it.
- ❌ An adapter taking the whole `Settings` → the two values it uses, as parameters.
- ❌ `from . import cancel` → `from shop.order import cancel`.

## References

- **[layout.md](references/layout.md)** — read when starting a project or adding a package or module: the tree `uv init --package` gives, grown into the shop's; `main`, `__main__.py` and their test; naming; `__init__.py`; import cycles and `TYPE_CHECKING`; module state.
- **[ownership.md](references/ownership.md)** — read when two places compute one thing, before adding a figure another screen shows, or before splitting work: finding the owner, lifting a copy, the shared-rules-first checklist, the guards.
- **[config.md](references/config.md)** — read when adding a setting or reading the environment: the one `Settings`, its field types, defaults and secrets, every bad value at once, `.env` files, and building settings in tests.
- **[decisions.md](references/decisions.md)** — read when unsure what to reach for: a function, an operation dataclass, a value type, a Protocol or a package.

## Related skills

- **[python-actions](../python-actions/SKILL.md)** — the operations that live in the domain packages, and the CLI's `build_app`, `run` and exit codes
- **[python-protocols](../python-protocols/SKILL.md)** — the seams `main` fills: stores, the clock, outside services
- **[python-types](../python-types/SKILL.md)** — values and ids, and stored formats kept apart from display
- **[python-project](../python-project/SKILL.md)** — `uv init`, `[project.scripts]`, and the version read from metadata
- **[python-quality](../python-quality/SKILL.md)** — the ban list, import-linter contracts and source guards that hold these rules
