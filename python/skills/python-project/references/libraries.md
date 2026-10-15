# Libraries

**A library runs inside other people's environments: it promises a range of Pythons and dependency versions, tests both ends of each, and ships its types.**

Contents: starting one · its surface · its Pythons · its dependencies · its licence · its version · building · publishing.

## Starting one

```sh
uv init --lib --python 3.11 shop-client
```

It writes what `--package` does, with no `[project.scripts]`, and adds `src/shop_client/py.typed`: the [PEP 561](https://peps.python.org/pep-0561/) marker that tells type checkers to read the package's own annotations. Without it, mypy reports each import of the library as `import-untyped`. `uv_build` ships it in the wheel with no configuration.

## Its surface

The top package's `__init__.py` imports what users may use and names it in `__all__`; everything else may move between releases ([python-architecture](../../python-architecture/SKILL.md)). A name users must not rely on starts with `_`.

## Its Pythons

- **`requires-python = ">=3.11"`:** every Python still maintained. 3.10 reached its end of life in October 2026; drop each version when it does, in a release of its own.
- **`.python-version` is `3.11`,** the floor, so the code you run day to day is the code on the oldest Python you promise.
- **Write for the floor.** No `type` statements or `class Box[T]` (3.12), no `copy.replace` (3.13). ruff reads `requires-python`, so its `UP` rules suggest only what 3.11 has. A module with a forward reference keeps `from __future__ import annotations`, and anything that reads annotations at runtime needs those names imported for real ([python-quality](../../python-quality/SKILL.md) has the idioms by version).
- **The tests run on each supported Python,** from the one lock:

```sh
for python in 3.11 3.12 3.13 3.14 3.15; do
    uv run --locked --isolated --python "$python" pytest
done
```

`--isolated` builds a fresh environment for each, leaving `.venv` alone; uv downloads an interpreter the machine lacks.

## Its dependencies

- **Lower bounds, never pins.** `uv add httpx2` writes `httpx2>=2.13.1`. A library pinned to `httpx2==2.13.1` can't be installed beside anything that needs 2.13.2, and its users can't take a security fix until you release.
- **No upper bound** (`<3`) unless a release is known to break you; then the cap names it, with why, and goes when you've adapted. A cap "just in case" blocks releases that would have worked.
- **Test the lower bounds,** or they're guesses. In CI:

```sh
uv run --resolution lowest-direct --python 3.11 pytest
```

It re-resolves every direct dependency to the lowest version its bound allows, rewriting `uv.lock` in CI's checkout. Never commit that lock. A failure means a bound is too low: raise it to the version the code needs.

- **The lock is committed,** as uv's docs advise, so the library's own CI is repeatable. A user's install never reads it: they get the newest versions your bounds allow, which is why the bounds are what you test.

## Its licence

[PEP 639](https://peps.python.org/pep-0639/)'s fields, an SPDX expression and the files that hold the text:

```toml
[project]
license = "MIT"
license-files = ["LICENSE"]
```

`uv build` writes them as `License-Expression: MIT` and `License-File: LICENSE` in the wheel's metadata. No `license = { text = "MIT" }` table, and no `License ::` classifiers: PEP 639 replaces both.

## Its version

- **`[project] version` is the only copy.** `uv version --bump minor` (or `patch`, `major`) raises it; `--dry-run` shows the result first.
- **Code that needs it asks the installed metadata,** by the distribution's name: `importlib.metadata.version("shop-client")`, called where it's used. No `__version__` literal, which drifts, and no `__version__ = version(...)` at module level, which runs at import.

## Building

```sh
uv build
```

It writes the sdist and the wheel to `dist/`. Read the wheel's file list before the first release: `py.typed`, the package, the licence, and nothing from `tests/`.

## Publishing

**Trusted Publishing, never a stored token.** On PyPI, add a trusted publisher to the project: the GitHub repository, the workflow file, and an environment such as `pypi`. The workflow's publish job runs in that environment with `id-token: write` permission, and runs:

```sh
uv build
uv publish --trusted-publishing always
```

- **`uv publish` exchanges the job's OIDC token for a short-lived upload token** on GitHub Actions and GitLab CI/CD. Nothing long-lived sits in the repository's secrets to leak.
- **`--trusted-publishing always`** fails if the exchange fails, where the default (`automatic`) would fall back to looking for a password.
- **Publish from a tag,** after the gates and the matrix pass on that commit.
