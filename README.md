<h1 align="center">Agentic Skills</h1>

<p align="center">
  <strong>Opinionated development skills for AI coding agents</strong>
</p>

<p align="center">
  <a href="#skills">Skills</a> •
  <a href="#installation">Installation</a> •
  <a href="#contributing">Contributing</a>
</p>

---

A collection of opinionated development patterns and practices refined over **20 years in the industry**. Each skill set provides focused guidance on a specific framework or domain.

These skills work with any AI coding agent that supports the SKILL.md format — including Claude Code, Codex, and Cursor.

## Skills

| Plugin | Description | Skills |
|--------|-------------|--------|
| [**laravel**](laravel/) | Laravel development — action-oriented architecture, strict typing, DTOs, and maintainability | 20 skills |
| [**nuxt**](nuxt/) | Nuxt 4 + Vue 3 — domain-driven, type-safe, composable-first architecture | 15 skills |

## Installation

### agntc (recommended)

Install individual plugins from the collection:

```bash
npx agntc@latest add leeovery/agentic-skills
```

You'll be prompted to select which plugins to install. Or install a specific one directly:

```bash
npx agntc@latest add leeovery/agentic-skills/laravel
npx agntc@latest add leeovery/agentic-skills/nuxt
```

Update anytime:

```bash
npx agntc@latest update
```

See [agntc](https://github.com/leeovery/agntc) for more details.

### Vercel Skills CLI

Also compatible with the [Vercel skills](https://github.com/vercel-labs/skills) CLI:

```bash
npx skills add leeovery/agentic-skills/laravel
npx skills add leeovery/agentic-skills/nuxt
```

## Contributing

Contributions are welcome! Whether it's:

- **Bug fixes** in the documentation
- **Improvements** to existing patterns
- **Discussion** about approaches and trade-offs
- **New skills** for patterns not yet covered

Please open an issue first to discuss significant changes. These are opinionated patterns, so let's talk through the approach before diving into code.

## Related

- [**agntc**](https://github.com/leeovery/agntc) — Agent skills installer for AI coding agents
- [**agentic-workflows**](https://github.com/leeovery/agentic-workflows) — Engineering workflow skills for Claude Code

## License

MIT License. See [LICENSE](LICENSE) for details.

---

<p align="center">
  <sub>Built with care by <a href="https://github.com/leeovery">Lee Overy</a></sub>
</p>
