<div align="center">

# 🧩 Agentic Skills

**Opinionated development skills for AI coding agents**

How I build Laravel, Nuxt, Go and Python applications, refined over 20 years in the industry,
<br>packaged as skills for any agent that reads the SKILL.md format.

[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![Laravel](https://img.shields.io/badge/plugin-Laravel-FF2D20.svg)](laravel/)
[![Nuxt](https://img.shields.io/badge/plugin-Nuxt-00DC82.svg)](nuxt/)
[![Go](https://img.shields.io/badge/plugin-Go-00ADD8.svg)](go/)
[![Python](https://img.shields.io/badge/plugin-Python-3776AB.svg)](python/)
[![Agents: Claude Code | Codex | Cursor](https://img.shields.io/badge/agents-Claude%20Code%20%7C%20Codex%20%7C%20Cursor-lightgrey.svg)](#installation)

[Plugins](#plugins) · [Installation](#installation) · [How a Skill Loads](#how-a-skill-loads) · [Contributing](#contributing) · [Related](#related)

</div>

---

Each plugin is a set of skills for one framework or language: how the code is structured, which tools to reach for, and what to avoid, each with the reason behind it. An agent working in your project loads the skill that matches the task in front of it and writes the code the way the skill says.

## Why Agentic Skills?

- **Opinionated on purpose.** Each skill takes a position (one shape for an action, a DTO, a package, a test) and gives the reason for it. Code an agent writes from them reads as one codebase, not an average of every style it has seen.
- **Drawn from real projects.** The patterns come from production applications and are checked against them. Anything that only makes sense in one project stays out.
- **Loaded when relevant.** A skill's description says when to use it, so the agent reads the jobs skill when it writes a job, not on every turn.
- **Install only what you use.** Each plugin installs on its own: take the Go skills without the Laravel ones.

## Plugins

| Plugin | Description | Skills |
|--------|-------------|--------|
| [**laravel**](laravel/) | Laravel development: action-oriented architecture, strict typing, DTOs, and maintainability | 22 skills |
| [**nuxt**](nuxt/) | Nuxt 4 + Vue 3: domain-driven, type-safe, composable-first architecture | 18 skills |
| [**go**](go/) | Go: standard library first, small packages by concern, named types, errors handled once | 8 skills |
| [**python**](python/) | Python 3.14: uv for everything, strict types as a gate, frozen values, Protocols as seams, nothing at import | 9 skills |

Each plugin's README lists its skills.

## Installation

### agntc (recommended)

```bash
npx agntc@latest add leeovery/agentic-skills
```

You're asked which plugins to install. Or install one directly:

```bash
npx agntc@latest add leeovery/agentic-skills/laravel
npx agntc@latest add leeovery/agentic-skills/nuxt
npx agntc@latest add leeovery/agentic-skills/go
npx agntc@latest add leeovery/agentic-skills/python
```

Update or remove them later:

```bash
npx agntc@latest update
npx agntc@latest remove leeovery/agentic-skills/laravel
```

See [agntc](https://github.com/leeovery/agntc) for more.

### Vercel Skills CLI

The plugins also install with the [Vercel skills](https://github.com/vercel-labs/skills) CLI:

```bash
npx skills add leeovery/agentic-skills/laravel
npx skills add leeovery/agentic-skills/nuxt
npx skills add leeovery/agentic-skills/go
npx skills add leeovery/agentic-skills/python
```

## How a Skill Loads

Every skill is a folder with a `SKILL.md`: YAML frontmatter holding its `name` and a `description` of when to use it, then the guidance itself. The agent sees only the descriptions until a task matches one, then reads that skill in full. Longer material, such as a complete reference file or a setup walkthrough, sits in the skill's `references/` folder, and the skill says when to read it, so the detail costs nothing until it's needed.

## Contributing

Issues and pull requests are welcome: a fix, a better pattern, a question about an approach or its trade-offs, or a skill for something not yet covered. These are opinionated patterns, so open an issue to talk a significant change through before writing it.

## Related

- [**agntc**](https://github.com/leeovery/agntc): the installer for agent skills
- [**agentic-workflows**](https://github.com/leeovery/agentic-workflows): engineering workflow skills for Claude Code

## License

MIT. See [LICENSE](LICENSE).

---

<p align="center">
  <sub>Built with care by <a href="https://github.com/leeovery">Lee Overy</a></sub>
</p>
