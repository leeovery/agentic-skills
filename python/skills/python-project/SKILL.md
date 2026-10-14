---
name: python-project
description: Python projects run by uv — uv run for everything, dependencies through uv add, a committed lockfile synced with --locked, a cooldown and an audit, PEP 723 scripts and libraries. Use when creating a project, adding, removing or upgrading a dependency, setting up CI, changing the Python version, writing a script, or publishing a library.
---

# Python Project

uv runs everything, dependencies change only through uv, and the lockfile is the truth.

## Core rules

1. **`uv run` runs everything:** the program, the tests, every gate. Never `pip install`, `python -m venv`, `source .venv/bin/activate` or `uv pip` in a project. *`uv run` brings the lock and the environment up to date before each command, so what runs is what the lock says; a package `uv pip` installed drifts from it unseen, and the next `uv sync` removes it.*
2. **Dependencies change through `uv add` and `uv remove`:** `--group lint` or `--group test` for a tool, `--optional cli` for an extra, neither for a runtime dependency. Never edit a dependency list by hand; the one exception is an `include-group` entry, which `uv add` can't write, followed by `uv lock`. *`uv add` resolves, writes the lower bound and updates the lock in one step; a hand edit leaves the lock behind, and CI's `uv sync --locked` fails on it.*
3. **A new runtime dependency comes with its reason, the standard library first.** Tools go in `[dependency-groups]` (`lint` and `test`, composed into `dev`), never in `[project.dependencies]`; an extra is only for an optional runtime feature users choose. *Each package is code nobody here reviewed, and one more thing to audit and upgrade; a tool in the runtime list installs on every user's machine.*
4. **`uv.lock` is committed, and CI installs with `uv sync --locked`,** which fails when the lock no longer matches `pyproject.toml`; `--frozen` installs a stale lock and leaves out whatever it lacks. An upgrade is targeted, `uv lock --upgrade-package httpx2`, and committed alone. *The lock is what CI tested and what production runs; a sweep of upgrades inside a feature hides which one broke what.* Held by `uv sync --locked`, CI's first step.
5. **Supply chain: a week's cooldown and an audit.** `[tool.uv] exclude-newer = "1 week"` keeps anything uploaded in the last seven days out of the lock, and `pip-audit`, fed from `uv export`, runs in CI. `uv audit` takes over once it leaves preview. *A malicious release is usually found and pulled within days, so a week's wait keeps it out; the audit finds what's already in.* Held by the resolver and by pip-audit in CI.
6. **`requires-python` is the floor, and `.python-version` pins the floor's interpreter:** `>=3.14` and `3.14` for an application, `>=3.11` and `3.11` for a library. Both are committed and raised together. *Without the pin, uv runs the first Python it finds or downloads the newest (3.15, from uv 0.13), so each machine runs a different one; on the floor, code that needs a newer Python fails on your machine before it fails on a user's.*
7. **A project starts from `uv init --package --python 3.14`:** the src layout, `uv_build` with the upper bound uv writes, and the version in `pyproject.toml` alone. Code that needs the version calls `importlib.metadata.version("shop")` inside a function. *uv's minor releases may break, so the backend stays on the one you tested; a `__version__` literal is a second copy that drifts at the first release.*
8. **One-off tools run through `uvx`, and a standalone script carries [PEP 723](https://peps.python.org/pep-0723/) metadata** and a `#!/usr/bin/env -S uv run --script` shebang. A project's own tools run through `uv run`, never `uvx`. *`uvx ruff` runs the newest ruff, not the locked one, so its findings differ from CI's; a script's inline block is its whole project, installed when it runs.*
9. **ruff, pyrefly, pytest and import-linter are pinned in the lock and upgraded on purpose, one at a time:** `uv lock --upgrade-package ruff`, the gates run, the new findings fixed, in a commit of its own. *Their minor releases change results: ruff 0.16 grew its default rule set from 59 rules to 413.* Held by the lock.

## Pattern

```toml
[project]
name = "shop"
version = "0.1.0"
description = "A shop's orders: the example domain the Python skills share."
readme = "README.md"
requires-python = ">=3.14"
dependencies = [
    "httpx2>=2.13.1",
    "pydantic>=2.14.1",
    "pydantic-settings>=2.16.0",
    "typer>=0.27.3",
]

[project.scripts]
shop = "shop.cli:main"

[build-system]
requires = ["uv_build>=0.13.1,<0.14.0"]
build-backend = "uv_build"

[dependency-groups]
lint = [
    "import-linter>=2.15",
    "pyrefly>=1.3.2",
    "ruff>=0.17.0",
]
test = [
    "coverage>=7.16.2",
    "hypothesis>=6.169.1",
    "looptime>=0.9",
    "pytest>=9.1.1",
    "pytest-asyncio>=1.4.0",
    "pytest-benchmark>=5.3.0",
    "pytest-cov>=7.1.0",
    "pytest-socket>=0.8.1",
    "syrupy>=6.1.1",
    "time-machine>=3.5.1",
]
# uv syncs dev by default, so `uv sync` installs every tool the gates run.
dev = [
    { include-group = "lint" },
    { include-group = "test" },
]
# An audit run now and then, not a gate: `uv run --group mutation mutmut run`.
mutation = [
    "mutmut>=3.8.0",
]

[tool.uv]
exclude-newer = "1 week"
```

`uv init` wrote the first three tables, the script since pointed at `shop.cli:main`, and `uv add` every dependency; the `include-group` entries and `[tool.uv]` are written once, by hand, then `uv lock`. python-quality's drop-in `[tool.*]` tables follow ([python-quality](../python-quality/SKILL.md)).

## Reach for

| Instead of | Run |
|---|---|
| `pip install httpx2` | `uv add httpx2` |
| `pip install pytest`, a `requirements-dev.txt`, `uv add --dev pytest` | `uv add --group test pytest` |
| `pip uninstall`, deleting the line | `uv remove httpx2` |
| `source .venv/bin/activate && pytest` | `uv run pytest` |
| `pip install -e .`, `uv pip install -e .` | `uv sync` |
| `pip install -U httpx2` | `uv lock --upgrade-package httpx2`, then `uv sync` |
| `pip list --outdated` | `uv tree --outdated --depth 1` |
| `pip freeze > requirements.txt` | `uv export -o requirements.txt`, for a tool that needs one |
| `pyenv install 3.14`, `pyenv local` | `uv python pin 3.14`; uv installs it on first use |
| `pipx run cowsay` | `uvx cowsay` |
| `python -m build`, `twine upload` | `uv build`, `uv publish` |

## Anti-patterns

- ❌ `[tool.uv] dev-dependencies = [...]` → `[dependency-groups]`, written by `uv add --group test`; the old key is on its way out.
- ❌ `uv sync --frozen` in CI → `uv sync --locked`.
- ❌ `uv lock --no-install` → there's no such flag; `uv lock` writes the lock and installs nothing.
- ❌ `uv lock --upgrade` inside a feature → `uv lock --upgrade-package x`, in a commit of its own.
- ❌ `ruff` in `[project.dependencies]`, or in an extra called `dev` → `uv add --group lint ruff`.
- ❌ `__version__ = "0.1.0"` in `__init__.py` → `importlib.metadata.version("shop")` where the version is needed.
- ❌ `uvx ruff check .` inside a project → `uv run ruff check .`, the locked ruff.
- ❌ `httpx2==2.13.1` in a library → `httpx2>=2.13.1`; the lock holds the exact version.
- ❌ `uv.lock` in a library's `.gitignore` → committed: it's what the library's own CI ran, and users' installs ignore it.
- ❌ A one-off script added to the project's dependencies → PEP 723 metadata in the script, `uv add --script`.

## References

- **[uv.md](references/uv.md)** — read when adding, removing or upgrading a dependency, setting up CI, or changing the Python version: init, groups, `include-group`, `uv run`, `--locked` against `--frozen`, upgrades, the cooldown, the audit, the CI steps.
- **[scripts.md](references/scripts.md)** — read when writing a standalone script or running a one-off tool: `uvx`, PEP 723, the shebang, a script's lock and cooldown.
- **[libraries.md](references/libraries.md)** — read when the project is a library others install: `py.typed`, its floor and testing each Python, lower bounds, the SPDX licence, building and publishing, the version.

## Related skills

- **[python-quality](../python-quality/SKILL.md)** — the five gates `uv run` runs, and the tool config in `pyproject.toml`
- **[python-architecture](../python-architecture/SKILL.md)** — the layout `uv init` starts, `main` behind `[project.scripts]`, and a library's `__all__`
- **[python-testing](../python-testing/SKILL.md)** — what runs under `uv run pytest`
