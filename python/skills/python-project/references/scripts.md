# Scripts and one-off tools

**A tool you run once comes from `uvx`; a script you keep declares its own dependencies inline and runs through uv, with no project around it.**

Contents: a script or a command · one-off tools · a standalone script · its lock and cooldown · where it lives.

## A script or a command

- **A task the project runs again,** against its own data or code, is a command in its CLI ([python-actions](../../python-actions/SKILL.md)), tested like the rest. A script that imports `shop` is a command waiting to be moved.
- **A standalone script** is for what stands apart: a check after a deploy, a one-off migration of a file, glue between two outside systems. It imports nothing from a project.

## One-off tools

```sh
uvx ruff@0.17.0 check .              # a tool at an exact version, in a throwaway environment
uvx --from httpie http --version     # a package whose command has another name
uv run --with ipython ipython        # a tool beside the project's own packages, for one command
uv tool install httpie               # a tool used every day, across projects, on PATH
```

- **`uvx` is `uv tool run`:** it installs into a cached environment and runs, leaving no trace in any project.
- **Pin the version where the result matters** (`ruff@0.17.0`); a bare `uvx ruff` runs the newest.
- **Never for a project's own gates.** Inside a project, ruff, pyrefly and pytest come from the lock through `uv run`, so the version that ran locally is the one CI runs.

## A standalone script

```sh
uv init --script gateway_status.py --python 3.14
uv add --script gateway_status.py httpx2
chmod +x gateway_status.py
```

`uv init --script` writes the [PEP 723](https://peps.python.org/pep-0723/) block, and `uv add --script` keeps its `dependencies` the way `uv add` keeps a project's. Add the shebang, the first line, and the `[tool.uv]` lines by hand:

```python
#!/usr/bin/env -S uv run --script
# /// script
# requires-python = ">=3.14"
# dependencies = [
#     "httpx2>=2.13.1",
# ]
#
# [tool.uv]
# exclude-newer = "1 week"
# ///
"""Report whether the payment gateway answers, for a check after a deploy."""

import sys

import httpx2


def main(argv: list[str]) -> int:
    """Print the gateway's health status: 0 if it answers 2xx, 1 if not, 2 for usage."""
    if len(argv) != 1:
        sys.stderr.write("usage: gateway_status.py URL\n")
        return 2
    url = argv[0].rstrip("/")
    try:
        response = httpx2.get(f"{url}/health", timeout=5.0)
    except httpx2.TransportError as err:
        sys.stderr.write(f"{url}: unreachable: {err}\n")
        return 1
    sys.stdout.write(f"{url}: {response.status_code}\n")
    return 0 if response.is_success else 1


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
```

- **The shebang** makes `./gateway_status.py https://pay.example` work on any machine with uv: `env -S` splits `uv run --script` into its words, and uv builds the environment the block declares.
- **The block is the whole project.** Inside a project's tree, `uv run` gives the script only what its block lists, never the project's dependencies.
- **`main` returns the code and the last line exits with it,** the same shape as a project's `main` ([python-architecture](../../python-architecture/SKILL.md)): 0, 1 for a failure, 2 for usage.
- **`requires-python`** in the block picks the interpreter, as it does for a project.

## Its lock and cooldown

```sh
uv lock --script gateway_status.py      # writes gateway_status.py.lock beside it
uv run --locked --script gateway_status.py https://pay.example
```

- **A script that others run gets a lock,** committed beside it. `uv add --script` then updates both, and `uv run --locked --script` fails when the block and the lock disagree, as `uv sync --locked` does for a project.
- **`exclude-newer` in the block's `[tool.uv]`** gives the script the project's week-long cooldown ([uv.md](uv.md)).
- **A throwaway script needs neither.** Its lower bounds and `requires-python` are enough to run it again next week.

## Where it lives

Anywhere: a script carries everything it needs. In a project's `scripts/`, the project's ruff config lints it along with the rest, so it writes through `sys.stdout` rather than `print` (`T201`) and exits with `raise SystemExit` rather than `sys.exit` (`TID251`). pyrefly's `project-includes` in the drop-in covers `src` and `tests`, not `scripts`.
