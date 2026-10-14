<div align="center">

# 🐍 Python Skills

**Opinionated Python patterns for AI coding agents**

uv for everything, strict types as a gate, one owner for every rule,
<br>and nothing that runs at import.

[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](../LICENSE)
[![Python 3.14](https://img.shields.io/badge/Python-3.14-3776AB.svg)](https://www.python.org)
[![Lint: ruff](https://img.shields.io/badge/lint-ruff-D7FF64.svg)](https://docs.astral.sh/ruff/)
[![Types: pyrefly strict](https://img.shields.io/badge/types-pyrefly%20strict-3776AB.svg)](https://pyrefly.org)

[Installation](#installation) · [Skills](#skills) · [Credits](#credits) · [All plugins](../README.md)

</div>

---

**These are opinionated.** They favour uv running everything, small packages split by domain, operations as functions or frozen dataclasses of their collaborators, `NewType` ids and `StrEnum`s, pydantic only at the edges, Protocols declared where they're used, exceptions handled once, tasks that each have an owner, and tests with hand-written fakes. No `unittest.mock`, no settings or clients built at import, no `utils` module. Every rule a tool can hold is a gate: ruff, pyrefly strict, import-linter contracts and `ast` source guards. They won't be for everyone, and that's okay.

**This is a work in progress.** I refine them as I use them in real projects.

## Installation

### agntc (recommended)

```bash
npx agntc@latest add leeovery/agentic-skills/python
```

Remove it with `npx agntc@latest remove leeovery/agentic-skills/python`.

### Vercel Skills CLI

```bash
npx skills add leeovery/agentic-skills/python
```

## Skills

### Foundation

| Skill | Description |
|-------|-------------|
| [**python-architecture**](skills/python-architecture/) | Packages by domain, `main` as the one place that wires, reads the environment and exits, nothing at import, and a single owner for every rule |
| [**python-project**](skills/python-project/) | uv runs everything, dependencies change through uv, the lockfile is the truth; scripts, Python versions and libraries |

### Core Patterns

| Skill | Description |
|-------|-------------|
| [**python-actions**](skills/python-actions/) | Domain operations as functions or frozen dataclasses, called from thin typer commands, HTTP handlers and consumers; exit codes |
| [**python-types**](skills/python-types/) | `NewType` ids, money in minor units, frozen values, `StrEnum`s, and pydantic wire shapes mapped at the boundary |
| [**python-protocols**](skills/python-protocols/) | Small Protocols declared by their consumers, seams for the clock, files and HTTP, adapters that own timeouts and retries, and hand-written fakes |
| [**python-errors**](skills/python-errors/) | Exceptions handled once, translated at the boundary, carrying what callers branch on, and stdlib logging configured only in `main` |

### Runtime

| Skill | Description |
|-------|-------------|
| [**python-concurrency**](skills/python-concurrency/) | Sync by default, asyncio tasks with an owner and a deadline, cancellation that propagates, and resources owned by `with` |

### Quality

| Skill | Description |
|-------|-------------|
| [**python-testing**](skills/python-testing/) | pytest with plain asserts, builders and hand-written fakes, injected time, properties, snapshots, and coverage read, never targeted |
| [**python-quality**](skills/python-quality/) | The gates: ruff, pyrefly strict, import-linter, pytest, a drop-in config with its reasons, source guards, and modern Python by version |

## Credits

The rules here are paraphrased in our own words, and no text is copied from these sources, which shaped them:

- [wshobson/agents](https://github.com/wshobson/agents), its `python-development` plugin (MIT)
- [trailofbits/skills](https://github.com/trailofbits/skills), its `modern-python` plugin (CC BY-SA 4.0), whose ideas on the uv toolchain and strict pytest are paraphrased here and none of whose text or config is used
- [Jeffallan/claude-skills](https://github.com/Jeffallan/claude-skills), its `python-pro` skill (MIT)
- [github/awesome-copilot](https://github.com/github/awesome-copilot), its `pytest-coverage` and `poka-yoke` skills (MIT)
- the [Python documentation](https://docs.python.org/3/) and each version's What's New, and the [PEPs](https://peps.python.org/)
- the documentation of [uv](https://docs.astral.sh/uv/), [ruff](https://docs.astral.sh/ruff/), [pyrefly](https://pyrefly.org), [pytest](https://docs.pytest.org/) and [import-linter](https://import-linter.readthedocs.io/)
