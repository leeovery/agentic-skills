<div align="center">

# 🐹 Go Skills

**Opinionated Go patterns for AI coding agents**

The standard library first, one owner for every rule,
<br>and named types in place of loose strings and ints.

[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](../LICENSE)
[![Go 1.27](https://img.shields.io/badge/Go-1.27-00ADD8.svg)](https://go.dev)
[![Lint: golangci-lint v2](https://img.shields.io/badge/lint-golangci--lint%20v2-00ADD8.svg)](https://golangci-lint.run)

[Installation](#installation) · [Skills](#skills) · [Credits](#credits) · [All plugins](../README.md)

</div>

---

**These are opinionated.** They favour small packages split by concern, operations as plain functions or small structs of their collaborators, named types and typed enums, errors wrapped and handled once, a logger that's always injected, interfaces declared where they're used, goroutines that each have an owner and an end, and tests written with the `testing` package alone. No testify, go-cmp, mocking library, or helper library like `lo`. They won't be for everyone, and that's okay.

**This is a work in progress.** I refine them as I use them in real projects.

## Installation

### agntc (recommended)

```bash
npx agntc@latest add leeovery/agentic-skills/go
```

Remove it with `npx agntc@latest remove leeovery/agentic-skills/go`.

### Vercel Skills CLI

```bash
npx skills add leeovery/agentic-skills/go
```

## Skills

### Foundation

| Skill | Description |
|-------|-------------|
| [**go-architecture**](skills/go-architecture/) | Packages by concern, `cmd` and `internal`, `main` as the one place that wires things, and a single owner for every rule |

### Core Patterns

| Skill | Description |
|-------|-------------|
| [**go-actions**](skills/go-actions/) | Domain operations as functions or small structs, called from thin cobra commands, HTTP handlers and workers; exit codes |
| [**go-types**](skills/go-types/) | Named types, input structs and typed enums, and wire and stored formats mapped at the boundary |
| [**go-interfaces**](skills/go-interfaces/) | Small interfaces declared by their consumers, accepting interfaces and returning structs, and hand-written fakes |
| [**go-errors**](skills/go-errors/) | Wrapping with `%w`, sentinels and error types, handling each error once, and the injected `*slog.Logger` |

### Runtime

| Skill | Description |
|-------|-------------|
| [**go-concurrency**](skills/go-concurrency/) | Goroutines with an owner and an end, context through every call, errgroup, channels and locks |

### Quality

| Skill | Description |
|-------|-------------|
| [**go-testing**](skills/go-testing/) | Table tests with the standard library, injected seams and fakes, golden files, fuzzing and `testing/synctest` |
| [**go-quality**](skills/go-quality/) | The gates: gofmt, vet, the race detector, a golangci-lint v2 config with its reasons, source guards, and modern Go idioms |

## Credits

The rules here are paraphrased in our own words, and no text is copied from these sources, which shaped them:

- [samber/cc-skills-golang](https://github.com/samber/cc-skills-golang) (MIT)
- [JetBrains/go-modern-guidelines](https://github.com/JetBrains/go-modern-guidelines) (Apache-2.0), which shaped the version-to-idiom table in go-quality's `modern-go.md`
- [cxuu/golang-skills](https://github.com/cxuu/golang-skills) (Apache-2.0)
- the [Uber Go Style Guide](https://github.com/uber-go/guide) (Apache-2.0)
- the [Google Go Style Guide](https://google.github.io/styleguide/go/) (CC BY 3.0)
- [Effective Go](https://go.dev/doc/effective_go) and [Go Code Review Comments](https://go.dev/wiki/CodeReviewComments)
- the [Go release notes](https://go.dev/doc/devel/release)
