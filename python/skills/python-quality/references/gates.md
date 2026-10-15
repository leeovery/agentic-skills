# Gates

**Five commands, in order, each passing clean in one run, before any work is called done.**

Contents: the five · the loop · in CI, beside them · a CI script · before reporting done · after upgrading a tool · what the drop-in's rules expect.

## The five

```sh
uv run ruff format --check .
uv run ruff check .
uv run pyrefly check
uv run lint-imports
uv run pytest
```

Each runs through `uv run`, so it uses the version pinned in `uv.lock` ([python-project](../../python-project/SKILL.md)). The fast checks run first, so a failure costs least.

| Gate | Catches | Watch for |
|---|---|---|
| `ruff format --check .` | anything not in the one format | it reports and changes nothing; `uv run ruff format .` rewrites. Since ruff 0.16 it formats the Python blocks in Markdown files too |
| `ruff check .` | the rules in `[tool.ruff.lint]`: the default set and the named families | `--fix` applies only safe fixes; read an `--unsafe-fixes` diff before keeping it |
| `pyrefly check` | type errors, strict: implicit `Any`, a missing `@override`, an ignore that no longer matches; and, raised to errors by the drop-in, a deprecated call and a `match` missing an enum's member | `pyrefly dump-config` shows where it resolves imports from |
| `lint-imports` | the contracts in `[tool.importlinter]`: imports pointing outward, frameworks in the domain | an `ignore_imports` entry that matches nothing fails with `No matches for ignored import` |
| `pytest` | broken behaviour, any warning, any network call | `strict = true` fails an unregistered marker and an xfail that passes; `--cov` measures coverage, never a gate |

## The loop

1. Run all five, top to bottom.
2. Fix the first gate that fails. For lint findings, `uv run ruff check --fix .`, then `uv run ruff format .`: a fix can leave a line the formatter rewraps.
3. Run all five again from the top. A format fix can move a lint finding, and a lint fix can change a type.
4. Report done only when one run passes all five, and say so.

A finding you think is wrong is a change to the gate, made in its own commit with its reason, never a suppression slipped into a feature.

## In CI, beside them

- **`uv sync --locked`** first: it fails when `uv.lock` no longer matches `pyproject.toml`, where `--frozen` installs the stale lock and leaves out what it lacks.
- **The dependency audit** that [python-project](../../python-project/SKILL.md) holds, where the network is allowed.
- **Annotations:** `ruff check --output-format github` and `pyrefly check --output-format github` print findings as GitHub workflow commands, so they land on the diff.

## A CI script

```sh
#!/bin/sh
set -eu

uv sync --locked
uv run ruff format --check .
uv run ruff check .
uv run pyrefly check
uv run lint-imports
uv run pytest
```

`set -e` stops at the first gate that fails, so the log ends on its findings.

## Before reporting done

- [ ] All five passed in one run, and the report says so.
- [ ] A gate failing on code you didn't touch is reported, not skipped or silenced.
- [ ] No `# noqa` or `# pyrefly: ignore` without its code and its reason.
- [ ] No per-file ignore, allowance entry, baseline entry, warning filter or `ignore_imports` line added to get green.
- [ ] Each comment added says why, not what the code does or what changed.

## After upgrading a tool

ruff, pyrefly, pytest and import-linter are pinned in the lock and upgraded one at a time ([python-project](../../python-project/SKILL.md)), because their minor releases change results: ruff 0.16 grew the default set from 59 rules to 413.

1. Upgrade the one tool, then run the five.
2. Fix what the new version finds in a commit of its own, not inside a feature.
3. A rule the new release adds that the project rejects goes in `ignore`, with its reason, in that same commit.

## What the drop-in's rules expect

- **The ban list lifts per file, whole.** `per-file-ignores` can lift `TID251` but not one entry of it, so the file named there may use every banned API. Name only the module that holds `main`. An adapter that must run a process gets its own line for `TID251`, with its reason, and nothing else in that file reads the clock or the environment.
- **pyrefly reads only its own suppressions,** `# pyrefly: ignore[bad-return]  # why`, the code taken from the error's brackets.
- **A baseline only shrinks.** `pyrefly check --baseline=pyrefly-baseline.json --update-baseline` writes one when strict lands on old code. From then the gate runs with `--baseline=pyrefly-baseline.json --error-stale-baseline`, which fails once an entry no longer matches and asks for `--prune-baseline`.
- **A pydantic field's type is imported at runtime,** so `TC004` flags one imported only under `if TYPE_CHECKING:`. A project's own base model, subclassed in another module, joins `runtime-evaluated-base-classes`. Nothing static catches the same in a typer command's parameters: it raises `NameError` when the command runs, so a test runs each command.
- **Every warning fails.** A `DeprecationWarning` from your code fails its test: fix the call. One from a dependency you can't fix yet gets `"ignore:<message regex>:DeprecationWarning:<module>"` after `"error"`, with why, and goes when the dependency is upgraded.
- **The network is off.** `--disable-socket` raises on any network socket a test opens; `--allow-unix-socket` lets asyncio's event loop wake itself. A test of an HTTP client uses `httpx2.MockTransport` ([python-testing](../../python-testing/SKILL.md)).
- **Async tests need no marker.** `asyncio_mode = "auto"` runs every `async def` test on asyncio, with a fresh loop per test.
- **Tests are packages.** `INP001` wants an `__init__.py` in every test directory, `D104` a docstring in it, and `D100` one in each test module. With `--import-mode=prepend`, a test imports a shared builder or fake as `tests.order.fakes`, and `shop` from the install, never through `pythonpath = ["src"]`.
