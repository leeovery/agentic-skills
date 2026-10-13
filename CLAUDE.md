# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Agentic Skills — a collection of opinionated development skills for AI coding agents. Installable via [agntc](https://github.com/leeovery/agntc). Skills work with any agent supporting the SKILL.md format (Claude Code, Codex, Cursor).

## Project Structure

```
laravel/                   # Laravel skills plugin
  agntc.json              # Plugin config — declares supported agents
  README.md               # Plugin-specific documentation
  skills/                 # 20 Laravel skills (SKILL.md, plus references/ where needed)
nuxt/                      # Nuxt skills plugin
  agntc.json
  README.md
  skills/                 # 18 Nuxt skills (SKILL.md, plus references/ where needed)
go/                        # Go skills plugin
  agntc.json
  README.md
  skills/                 # 8 Go skills (SKILL.md, plus references/ where needed)
.mint.toml                # mint release config (only keys that differ from mint's defaults)
release                   # Shim that runs `mint release`
CHANGELOG.md              # Written by mint at each release
```

This is an agntc **collection** — no root `agntc.json`. Each subdirectory with its own `agntc.json` is an independently installable plugin.

## Commands

### Release

Releases are cut with [mint](https://github.com/leeovery/mint): AI release notes from the diff, a `CHANGELOG.md` entry, an annotated tag, one atomic push, then the GitHub release. No CI is involved.

```bash
./release              # Patch release (default)
./release -m           # Minor release
./release -M           # Major release
./release -d           # Dry run (prints the plan, changes nothing)
./release --no-ai      # Skip the AI notes and use the commit subjects
```

## Development Notes

- No source code — this is a documentation-only collection
- Version is tag-only (no version file) — mint reads it from the `v`-prefixed git tags
- All plugins in the collection share a single version (monorepo model)
- Commits are Conventional Commits scoped to the skill, e.g. `docs(laravel-dtos): ...`
- A new plugin needs its own `agntc.json` and README, a row in the root README's plugin table, and an entry in the structure above

## Skill Conventions

- Each skill is a directory with a `SKILL.md` entry point and an optional `references/` subdirectory
- Frontmatter holds `name` (matching the directory) and `description` (what the skill covers, then when to use it). It must parse as YAML: a plain-scalar description can't contain `: `, so reword it or quote it
- `SKILL.md` stays short enough to read in full; long material goes into `references/`, and the skill says when to read each file
- Examples use neutral names, never a real project's
- The Laravel skills share one shape: a thesis paragraph, `## Rules` (each with its reason), `## Example`, `## Reach for these when the situation calls for it` (situational techniques, trigger first), `## Anti-patterns`, `## Related`. They link to another skill's `SKILL.md`, never into its `references/`
