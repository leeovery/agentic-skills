# uv

**A project is its `pyproject.toml` and its `uv.lock`; uv keeps the environment matching both, and every change goes through it.**

Contents: starting a project · the Python version · dependencies and groups · running · the lockfile · upgrading · the cooldown · the audit · in CI.

## Starting a project

```sh
uv init --package --python 3.14 shop
```

- **What it writes:** `pyproject.toml`, `.python-version`, `README.md`, a `.gitignore`, and `src/shop/__init__.py` holding a `main` that prints a greeting, named in `[project.scripts]` as `shop = "shop:main"`. The tree it grows into is [python-architecture](../../python-architecture/SKILL.md)'s: `main` moves to `shop/cli.py` and the script follows it.
- **`--package`** is the default since uv 0.12, and says so on any version. `--lib` starts a library ([libraries.md](libraries.md)).
- **`--python 3.14`** sets both `requires-python = ">=3.14"` and `.python-version`. Left out, uv uses the first interpreter it finds, which may be older than the floor you meant.
- **The build backend** is `uv_build`, bounded to the uv minor that ran `init`: `uv_build>=0.12.23,<0.13.0` from uv 0.12.23, `uv_build>=0.13.1,<0.14.0` from 0.13.1. uv's minor releases may break, so keep the bound, and raise both ends in a commit of its own when the project moves to a new uv minor.
- **The version** lives in `[project] version` and nowhere else. `uv version --bump minor` raises it; code reads it with `importlib.metadata.version("shop")`, called where it's used, never at module level.
- **Add `.env` to the `.gitignore`;** `uv init` doesn't ([python-architecture](../../python-architecture/SKILL.md) holds what a `.env` is for).

## The Python version

- **`requires-python`** is the oldest Python the project supports. ruff reads it as its target, so `UP` suggests only what the floor allows.
- **`.python-version`** is the interpreter `uv run` uses. Pin it to the floor (`uv python pin 3.14`), so the tests run on the oldest Python the project promises. uv downloads that interpreter on first use if the machine lacks it.
- **Both are committed, and raised together.** Then `uv lock`, the gates, and python-quality's `UP` pass, in a commit of their own ([python-quality](../../python-quality/SKILL.md)).
- **Without a `.python-version`,** uv picks for each machine: the first suitable interpreter it finds, or the newest it can download, 3.15 from uv 0.13.

## Dependencies and groups

```sh
uv add httpx2                       # a runtime dependency: [project.dependencies]
uv add --group test hypothesis      # a test tool
uv add --group lint ruff            # a lint tool
uv add --optional cli rich          # an optional runtime feature: [project.optional-dependencies]
uv remove --group test hypothesis
```

- **`uv add` writes a lower bound** (`httpx2>=2.13.1`), resolves, updates `uv.lock` and syncs. The lock holds the exact version.
- **Tools live in named groups,** `lint` and `test` ([PEP 735](https://peps.python.org/pep-0735/) `[dependency-groups]`), never in `[project.dependencies]` or an extra: groups aren't published, so no user installs them.
- **`dev` composes them:** `dev = [{ include-group = "lint" }, { include-group = "test" }]`. uv syncs `dev` by default, so `uv sync` and `uv run` have every tool. `uv add` has no flag that writes an `include-group` entry, so that line is written by hand, once, then `uv lock`. `uv add --dev x` would put `x` in `dev` beside them; add it to its group instead.
- **An extra is for users,** a feature they choose to install (`shop[cli]`), never for tools.
- **`[tool.uv] dev-dependencies`** is the old form of the dev group, which uv's docs say will be deprecated and removed. Move it to `[dependency-groups]`.
- **A new runtime dependency comes with its reason in the PR:** what the standard library lacks, and why this package. `uv tree --invert --package idna` shows what pulled a transitive one in.

## Running

- **`uv run <command>`** locks if `pyproject.toml` changed, syncs the environment, then runs: `uv run pytest`, `uv run shop cancel o-1`, `uv run python -c "..."`. It adds what's missing and leaves extra packages; `uv sync` removes them.
- **`uv run --with ipython ipython`** layers a package over the project's environment for one command, without adding it.
- **`uv run --env-file .env shop ...`** loads a `.env` for that command only.
- **Never activate `.venv`, and never `uv pip` in a project.** Both work on the environment behind the lock's back, and the next `uv sync` undoes them.
- **A `.venv` doesn't move.** Its scripts (`pytest`, `lint-imports`) start with the path of the interpreter they were installed with, so a project copied with its `.venv` runs the original's packages. Delete `.venv` in the copy and `uv sync`.

## The lockfile

- **`uv.lock` is committed,** for an application and for a library alike: it's what CI tested. It's uv's own format; `uv export` writes `requirements.txt` or [PEP 751](https://peps.python.org/pep-0751/)'s `pylock.toml` for a tool that needs one.
- **Three ways to treat it:**

  | Flag | When the lock is stale | Use it |
  |---|---|---|
  | `uv sync --locked` | fails | CI's install step |
  | `uv lock --check` | fails, installing nothing | a check that needs no environment |
  | `uv sync --frozen` | installs the stale lock, without what it lacks, and exits 0 | never: `--locked` installs the same and checks |

- **A hand edit to a dependency list** is caught by `--locked`: the lock "needs to be updated", and CI stops there.
- **A merge conflict in `uv.lock`** is never resolved by hand: take either side, then `uv lock`, which re-resolves from `pyproject.toml` and keeps the other pins it can.

## Upgrading

1. `uv tree --outdated --depth 1` lists the direct dependencies with a newer release.
2. `uv lock --upgrade-package httpx2` moves that one package, and what it needs, to the newest release the bounds and the cooldown allow.
3. Run the gates, read the package's changelog for what the tests don't cover, and commit the lock alone: `chore(deps): upgrade httpx2 to 2.14.0`.
4. A tool (ruff, pyrefly, pytest, import-linter) goes the same way, one per commit, with the fixes its new findings need in that commit ([python-quality](../../python-quality/SKILL.md)).

`uv lock --upgrade` moves everything at once. On a branch of its own, it's a sweep that a failing gate can't point into; inside a feature, it hides which upgrade broke what.

## The cooldown

```toml
[tool.uv]
exclude-newer = "1 week"
```

- **uv ignores every file uploaded less than a week ago** when it resolves. A release that turns out malicious is usually yanked within days; a lock taken a week behind never sees it.
- **It holds back `uv add` too,** so it goes in before the first one. Each bound `uv add` writes is then the newest release a week old: `pydantic>=2.13.5` on 2026-10-15, with 2.14.1 four days old. A bound on a release inside the week, written before the cooldown or by hand, fails `uv lock`, which says the package "was filtered by `exclude-newer`" and suggests `exclude-newer-package`.
- **The syntax:** a friendly duration (`"24 hours"`, `"1 week"`, `"30 days"`), an ISO 8601 one (`"P7D"`), or a fixed timestamp. Months and years aren't allowed. The lock records the span (`exclude-newer-span = "P1W"`), not a date, so each upgrade counts the week back from the day it runs.
- **A release you need sooner,** a fix that can't wait, is exempted by name until the week passes, with why. `false` takes the package's newest release; a duration of its own, `{ urllib3 = "1 day" }`, a shorter wait:

  ```toml
  [tool.uv]
  exclude-newer = "1 week"
  # urllib3's fix for the advisory pip-audit reported is days old. Delete once it's a week old.
  exclude-newer-package = { urllib3 = false }
  ```

## The audit

pip-audit checks every pinned package against the advisory databases. Feed it the lock, exported with hashes, so it audits exactly what's installed and resolves nothing itself:

```sh
audit=$(mktemp -d)
uv export --locked --all-groups --no-emit-project -o "$audit/requirements.txt"
uvx pip-audit@2.10.1 --disable-pip -r "$audit/requirements.txt"
```

- **`--all-groups`** includes the tools: they run in CI with its credentials.
- **A finding** is fixed by `uv lock --upgrade-package` to the fixed release, in a commit of its own; past the cooldown, with the exemption above.
- **`uv audit`** does the same from the lock, and is still a preview in uv 0.13.1. Switch to it once it's stable.

## In CI

The install, python-quality's five gates, then the audit, where the network is allowed:

```sh
#!/bin/sh
set -eu

uv sync --locked
uv run ruff format --check .
uv run ruff check .
uv run pyrefly check
uv run lint-imports
uv run pytest

audit=$(mktemp -d)
uv export --locked --all-groups --no-emit-project -o "$audit/requirements.txt"
uvx pip-audit@2.10.1 --disable-pip -r "$audit/requirements.txt"
```

What each gate catches, and the loop around them, is [python-quality](../../python-quality/SKILL.md)'s. A library adds a run on each supported Python ([libraries.md](libraries.md)).
