# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Agentic Skills — a collection of opinionated development skills for AI coding agents. Installable via [agntc](https://github.com/leeovery/agntc). Skills work with any agent supporting the SKILL.md format (Claude Code, Codex, Cursor).

## Project Structure

```
laravel/                   # Laravel skills plugin
  agntc.json              # Plugin config — declares supported agents
  README.md               # Plugin-specific documentation
  skills/                 # 20 Laravel skills (each has SKILL.md + references/)
nuxt/                      # Nuxt skills plugin
  agntc.json
  README.md
  skills/                 # 15 Nuxt skills (each has SKILL.md + references/)
release                   # Bash script for semver releases with AI-generated notes
```

This is an agntc **collection** — no root `agntc.json`. Each subdirectory with its own `agntc.json` is an independently installable plugin.

## Commands

### Release

```bash
./release              # Patch release (default)
./release -m           # Minor release
./release -M           # Major release
./release -d           # Dry run (preview)
./release --no-ai      # Skip AI commit message generation
```

## Development Notes

- No source code — this is a documentation-only collection
- Skills are markdown files with YAML frontmatter (`name`, `description`)
- Each skill directory has a `SKILL.md` entry point and `references/` subdirectory
- Version is tag-only (no version file) — release script reads from git tags
- All plugins in the collection share a single version (monorepo model)
