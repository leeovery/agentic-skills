---
name: python-quality
description: Gates Python code passes before it is done — ruff, pyrefly strict, import-linter, pytest, source guards, modern idioms by version. Use when configuring ruff, pyrefly, pytest or CI, enforcing a rule codebase-wide, silencing a finding, raising requires-python, writing comments, or renaming or moving code, and before calling work done.
---

# Python Quality

Work is done when every gate passes clean, and a rule that must always hold is a gate, never a sentence.

## Core rules

1. **Five gates pass clean before work is called done,** each through `uv run`, in this order: `ruff format --check .`, `ruff check .`, `pyrefly check`, `lint-imports`, `pytest`. Run them, fix, run them again from the top, then report. *Passing tests prove the tests pass; the format, the lint rules, the types and the import contracts are what review would find instead.*
2. **ruff starts from [pyproject-tools.toml](references/pyproject-tools.toml):** its default set, plus the families named in `extend-select`, each with its reason. Never `ALL`. A family joins once its findings are fixed. *`ALL` switches on whatever the next release adds, and a family switched on over a hundred open findings teaches everyone to ignore it.*
3. **A suppression names its rule and says why:** `# noqa: S608  # the table name is a constant`, `# pyrefly: ignore[bad-return]  # the stub is wrong`. The drop-in has pyrefly read only its own, so a `# type: ignore` silences nothing. *A bare one silences every rule on the line, for a reason nobody can check, and outlives the finding it was for.* Held by ruff's `PGH004` and `RUF100`, and pyrefly's `unused-ignore`.
4. **A rule that must always hold is a gate, not a sentence.** ruff's `banned-api` for an import or a call, with `per-file-ignores` naming the one file allowed; an import-linter contract for structure; otherwise a source-guard test that parses with `ast`, keeps an allowance that only shrinks, and has been seen to fail. *A comment or an instruction is read once; a gate fails the day the rule breaks, and says where and what the fix is.*
5. **pyrefly runs with `preset = "strict"`.** Code that can't pass yet comes in through a baseline that only shrinks: `pyrefly check --baseline=pyrefly-baseline.json --error-stale-baseline`. *Strict reports each implicit `Any`, a value the checker can't see into, and each override missing `@override`; a baseline that can grow is a second config nobody reviews.*
6. **After raising `requires-python`, run `uv run ruff check --select UP --fix .` until it changes nothing, and commit that alone.** On a 3.14 floor, delete `from __future__ import annotations` by hand: no rule removes it. *A commit that only modernises reviews at a glance; inside a feature it hides the feature.*
7. **New code uses the idioms `requires-python` allows, even beside older code.** Old code is modernised in a change of its own. *Matching the file's older style spreads it.* [modern-python.md](references/modern-python.md) lists what each version allows; ruff's `UP` rules catch the ones with a fix.
8. **Comments say why:** a constraint, or something that looks wrong and was proven right. They never narrate the code, carry the agent's context ("as requested", "now uses the new store") or record the change; the commit holds that. Every module opens with a docstring that states its job, and a function's docstring says what its signature can't. *A comment that restates the code goes stale, and one about the change is wrong by the next commit.* Held by ruff's `D100`, `D104` and `ERA001`.
9. **A commit never mixes a structural change with a behaviour change.** Rename and move with the language server's rename, not by hand or `sed`. *A pure move reviews at a glance, and a rename by reference changes each use by what it is, not by how it's spelled.*
10. **Never loosen a gate to get green:** no new `per-file-ignores`, allowance or baseline entry, `filterwarnings` ignore or `ignore_imports` line. Fix the code. A gate that's wrong changes in its own commit, with its reason. *A loosened gate stops checking for everyone, and the commit that loosened it reads as a fix.*

## Pattern

A source guard: a test that reads the project's source and fails where the rule breaks, then a second test that proves it can. `check`, `report`, `scoped` and the rest are in [source-guards.md](references/source-guards.md).

```python
# tests/guards/test_logging.py
"""Logging is configured once, by main; every other module only names its logger."""

...

ENTRY = "src/shop/cli.py"
CONFIGURES = frozenset({"basicConfig", "dictConfig", "fileConfig", "addHandler"})
FIX = "leave it to main; a module only calls logging.getLogger(__name__)"


def configures_logging(root: Path) -> Iterator[Breach]:
    """The calls in root's src/ modules, but main's, that configure logging."""
    for module in modules(root, "src"):
        if module.path == ENTRY:
            continue
        for scope, node in scoped(module.tree):
            if not isinstance(node, ast.Call):
                continue
            name = dotted(node.func)
            called = node.func.attr if isinstance(node.func, ast.Attribute) else name
            if called in CONFIGURES:
                what = f"configures logging with {name or called}"
                yield Breach(
                    path=module.path, line=node.lineno, scope=scope, what=what, fix=FIX
                )


def test_only_main_configures_logging() -> None:
    check(configures_logging(project_root()))


def test_the_logging_guard_finds_a_module_configuring_logging(tmp_path: Path) -> None:
    write(
        tmp_path,
        "src/shop/payments/gateway.py",
        '''
        """Refunds, logging to a file of their own."""
        import logging

        logger = logging.getLogger(__name__)


        def setup() -> None:
            logging.basicConfig(level=logging.DEBUG)
            logger.addHandler(logging.FileHandler("refunds.log"))
        ''',
    )

    assert report(configures_logging(tmp_path)) == [
        f"src/shop/payments/gateway.py:8: configures logging with logging.basicConfig: {FIX}",
        f"src/shop/payments/gateway.py:9: configures logging with logger.addHandler: {FIX}",
    ]
```

A failing guard prints one line per breach, `src/shop/payments/gateway.py:8: configures logging with logging.basicConfig: leave it to main; a module only calls logging.getLogger(__name__)`, all of them at once.

## Which gate

| The rule is about | Hold it with |
|---|---|
| formatting and import order | `ruff format`, and ruff's `I001` (default) |
| a known bug shape: a mutable default, a bare `except`, a naive `datetime` | ruff's default set, `B` and `DTZ` |
| an import or call nobody may use, or only `main` may | ruff `banned-api` (`TID251`), with `per-file-ignores` naming the one file allowed |
| an import pointing outward, or a framework in the domain | an import-linter `layers` or `forbidden` contract |
| what a value's type allows | pyrefly, strict |
| an idiom older than `requires-python` | ruff's `UP`, and `ruff check --select UP --fix` |
| a deprecated API | pyrefly, for one the stubs mark deprecated; pytest's `filterwarnings = ["error"]`, for one that warns as it runs |
| the project's shape: names, what runs at import, which module words a value | a source-guard test |
| what the code does | a test |

## Anti-patterns

- ❌ Calling work done on `pytest` alone → all five gates, clean, in one run.
- ❌ `select = ["ALL"]` and a list of ignores → the default set plus named families, each with its reason.
- ❌ A bare `# noqa`, a `# type: ignore[assignment]` carried over from mypy, or a bare `# pyrefly: ignore` → `# noqa: S608  # why`, `# pyrefly: ignore[bad-assignment]  # why`, the code taken from the error's brackets. Nothing catches a bare `# pyrefly: ignore`; review does.
- ❌ A must-hold rule written as a comment, a README line or an agent instruction → a ban, a contract or a guard.
- ❌ A guard never seen to fail → a second test that writes a breaking module to `tmp_path` and checks the guard reports exactly it.
- ❌ Loosening a gate to get green: a per-file ignore, an allowance or baseline entry, an `ignore:` warning filter → fix the code.
- ❌ `[tool.pytest.ini_options]` → `[tool.pytest]`, pytest 9's native TOML; both at once is an error.
- ❌ `from __future__ import annotations` and quoted names on a 3.14 floor → delete the import, then `ruff check --select UP --fix .` drops the quotes.
- ❌ `# changed to use the new store`, `# as requested` → nothing in the code; the commit message says it.

## References

- **[gates.md](references/gates.md)** — read when setting up a project or CI, or before reporting done: the five commands, what each catches, the run-fix-rerun loop, a CI script, and what the drop-in's rules expect.
- **[pyproject-tools.toml](references/pyproject-tools.toml)** — the drop-in tool tables; read when creating or changing `[tool.ruff]`, `[tool.pyrefly]`, `[tool.pytest]`, `[tool.coverage]`, `[tool.importlinter]` or `[tool.mutmut]`.
- **[source-guards.md](references/source-guards.md)** — read when a rule must hold everywhere and no linter can say it: the helpers, allowances, six worked guards, and proving a guard fails.
- **[modern-python.md](references/modern-python.md)** — read when raising `requires-python`, or unsure an API exists: the idioms from 3.10 to 3.15 with their What's New and ruff rule, and what's deprecated or gone.

## Related skills

- **[python-architecture](../python-architecture/SKILL.md)** — the layout, import-direction and nothing-at-import rules these gates hold
- **[python-testing](../python-testing/SKILL.md)** — what goes inside the tests the gates run, and reading coverage
- **[python-errors](../python-errors/SKILL.md)** — the exception and logging rules behind `TRY`, `G`, `LOG` and the logging guard
- **[python-project](../python-project/SKILL.md)** — uv, the lockfile, pinning and upgrading the tools, and the dependency audit in CI
