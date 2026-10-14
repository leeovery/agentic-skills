# Decisions

**Reach for the least structure that holds the rule: a function, then a dataclass, then a module, then a package.**

Contents: need to reach for · signs of the wrong choice.

## Need to reach for

| Need | Reach for | Not when | More |
|---|---|---|---|
| A computation with no collaborators | a function in the module that owns the data | it reads one value's fields: a property on its type | [python-actions](../../python-actions/SKILL.md) |
| An operation that needs a store, a clock or a provider | a `@dataclass(frozen=True, slots=True, kw_only=True)` whose fields are those collaborators, with one verb method | it needs none: a function | [python-actions](../../python-actions/SKILL.md) |
| A value with a meaning: an id, an amount | `NewType("OrderId", str)`, or a frozen dataclass (`Money`) | — | [python-types](../../python-types/SKILL.md) |
| A value with rules: at least one line, a positive amount | a frozen dataclass that checks in `__post_init__`, or a `parse` classmethod | — | [python-types](../../python-types/SKILL.md) |
| A fixed set of states | a `StrEnum`, its behaviour as properties | — | [python-types](../../python-types/SKILL.md) |
| Swapping an implementation, or faking one in a test | a `Protocol` the consumer declares, of one to three methods | one implementation and no fake: the concrete class | [python-protocols](../../python-protocols/SKILL.md) |
| One operation to swap: the time, an id | a callable field: `now: Callable[[], datetime]` | — | [python-protocols](../../python-protocols/SKILL.md) |
| A meaning two callers need | one function or property in its owner, called by both | — | [ownership.md](ownership.md) |
| How a value reads to people | a function in `shop.display` | it's stored or sent: its own encoder | [ownership.md](ownership.md) |
| Input parsed from outside: a request body, a file | a pydantic model at the edge, mapped into domain types | the data is already yours: a dataclass | [python-types](../../python-types/SKILL.md) |
| A job several packages need | a module named for that job | one module needs it: a private function there | [layout.md](layout.md) |
| A new domain, or an outside service to adapt | a package (`shop.order`, `shop.payments`) in a layer of the import contract | it's one job: a module in the package it serves | [layout.md](layout.md) |
| A command, handler or consumer | a thin edge: parse, call one operation, write the result or map the exception | — | [python-actions](../../python-actions/SKILL.md) |
| Config, the environment | a field on `Settings`, read in `main`'s wiring, its value passed down | — | [config.md](config.md) |
| A rule that must always hold | a gate: a ruff ban, an import-linter contract, a source guard | — | [python-quality](../../python-quality/SKILL.md) |

## Signs of the wrong choice

- **A class with methods and no fields** → functions in a module.
- **A class with one method and `__init__`** that only stores its arguments → a frozen dataclass of the collaborators, or a function if it holds none.
- **A Protocol with one implementation and no fake** → the concrete class.
- **An ABC base class for a seam** → a Protocol in the consumer; implementations don't inherit.
- **A package docstring that needs "and" between two jobs** → two packages.
- **Two packages every caller imports together** → one package.
- **A module named for a kind of code** (`models.py`, `schemas.py`, `services.py`) → modules named for the jobs inside it.
- **A `bool` parameter that switches what a function does** → two functions, or a `StrEnum` mode.
- **A constructor taking six collaborators** → the class does several jobs: split it, rather than dropping injection.
- **A function named for its mechanics** (`process_data`, `handle_order`) → named for what it gives back or does: `cancel`, `cancel_ratio`, `display.percent`.
- **The same three lines in two modules** → one owner, before a third copy appears.
- **A module-level object built at import** (a client, a registry, settings) → built in `main` and passed in.
