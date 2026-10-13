---
name: go-quality
description: Gates Go code passes before it is done — gofmt, vet, golangci-lint v2, race tests, go fix, govulncheck, source guards. Use when configuring linters or CI, enforcing a rule codebase-wide, upgrading Go or adding a dependency, writing comments, or renaming or moving code, and before calling it done.
---

# Go Quality

Work is done when every gate passes clean, and a rule that must always hold is a gate, never a sentence.

## Core rules

1. **Five gates pass clean before work is called done:** `gofmt -l .` prints nothing, then `go vet ./...`, `go test -race ./...` (or the project's own runner), `golangci-lint run` and `go build ./...`. *A green build proves it compiles; the races, vet's findings and the lint rules are what review would find instead.*
2. **golangci-lint v2 starts from [golangci.yml](references/golangci.yml).** A linter joins with its reason beside it, once its findings are fixed or excluded with theirs. *A linter switched on over a hundred open findings teaches everyone to ignore it.*
3. **`//nolint:<linter> // reason`, never bare.** *A bare nolint silences every linter on the line, for a reason nobody can check.* Held by nolintlint.
4. **A rule that must always hold is a gate, not a comment.** A linter setting where one can say it (depguard for an import, forbidigo for a call); otherwise a source-guard test that parses the module with `go/parser`, or `go/types` when it must know what a name refers to, and keeps an allowance list that only shrinks. *A comment or a README line is read once; a gate fails the day the rule breaks.*
5. **After raising the `go` line or the toolchain, run `go fix ./...` until it changes nothing, and commit that alone.** Since [Go 1.26](https://go.dev/doc/go1.26) it runs the modernizers: `wg.Go`, `errors.AsType`, `new(expr)`, `t.Context`, `range n` and the rest, and one fix can uncover the next. *A commit that only modernises reviews at a glance; inside a feature it hides the feature.*
6. **New code uses the idioms its `go` line allows, even beside older code.** Old code is modernised in a change of its own. *Matching the file's older style spreads it.* [modern-go.md](references/modern-go.md) lists what each version allows.
7. **`govulncheck ./...` runs in CI,** outside any sandbox that blocks the network. *It reports only the vulnerabilities your code reaches, so each finding is real.*
8. **A new dependency comes with its reason.** Standard library first: no helper grab bags (`lo`), no assertion or mock libraries. *Each module is code nobody here reviewed, and one more thing to upgrade.* Held by depguard.
9. **Comments say why:** a constraint, or something that looks wrong and was proven right. They never narrate the code, carry the agent's context ("as requested", "now uses the new store") or record the change; the commit holds that. Exported names get a doc comment that begins with the name. *A comment that restates the code goes stale, and one about the change is wrong by the next commit.* Held by revive's `exported`.
10. **A commit never mixes a structural change with a behaviour change.** Rename and move with gopls (`gopls rename -w file.go:12:6 NewName`), not by hand or `sed`. *A pure move reviews at a glance, and a type-checked rename changes each reference by what it is, not how it's spelled.*

## Pattern

A source guard: a test that reads the module and fails where the rule breaks. `guard.Files` and `guard.ModuleRoot` are in [source-guards.md](references/source-guards.md).

```go
// internal/guard/names_test.go

package guard_test

import (
	"path"
	"slices"
	"strings"
	"testing"

	"example.com/shop/internal/guard"
)

// grabBags name no job, so any code fits under them.
var grabBags = []string{"util", "utils", "common", "helpers", "misc", "shared", "types", "models"}

func TestPackagesAreNamedForTheirJob(t *testing.T) {
	seen := make(map[string]bool)
	for _, f := range guard.Files(t, guard.ModuleRoot(t)) {
		dir, name := path.Dir(f.Path), strings.TrimSuffix(f.AST.Name.Name, "_test")
		if !seen[dir] && slices.Contains(grabBags, name) {
			seen[dir] = true
			t.Errorf("%s: package %s: name it for its one job, or move each function to the package it serves", dir, name)
		}
	}
}
```

## Which gate

| The rule is about | Hold it with |
|---|---|
| formatting and import grouping | gofmt and goimports, as golangci-lint formatters |
| a known bug shape: printf arguments, a copied lock, a lost cancel | `go vet` |
| a module nobody may import, or an import pointing outward | depguard, by path |
| a function or variable a package mustn't use | forbidigo, with an exclusion for the one place allowed |
| an idiom older than the `go` line | modernize, and `go fix ./...` |
| the module's shape: package names, who reads a field, which files word a value | a source-guard test |
| a dependency with a known vulnerability | govulncheck, in CI |
| what the code does | a test |

## Anti-patterns

- ❌ Calling work done on `go build` or `go test` alone → all five gates, clean.
- ❌ `gofmt -l .` as a CI step on its own → it exits 0 while listing files: `test -z "$(gofmt -l .)"`.
- ❌ A bare `//nolint`, or `//nolint:all` → `//nolint:containedctx // the worker's own lifetime, cancelled by Stop`.
- ❌ Silencing errcheck with a nolint → `_ = f()` is the visible discard: a file only read closes with `defer func() { _ = f.Close() }()`, and a written file's Close error is joined into the one returned.
- ❌ A must-hold rule written as a comment, a README line or an agent instruction → a linter setting or a guard test.
- ❌ A guard never seen to fail → run it once over a fixture module that breaks the rule.
- ❌ Loosening a gate to get green: an exclusion, a higher limit, a new allowance entry → fix the code. A gate that's wrong changes in its own commit, with its reason.
- ❌ v1 config keys (`linters-settings`, `gosimple`, `stylecheck`) → v2's `linters.settings`; `golangci-lint migrate` converts a v1 file.
- ❌ `go fix` changes inside a feature commit → a commit of their own.
- ❌ `// changed to use the new store`, `// as requested` → nothing in the code; the commit message says it.

## References

- **[gates.md](references/gates.md)** — read when setting up a project or CI, or before reporting done: the commands, their order, what each catches, a CI script.
- **[golangci.yml](references/golangci.yml)** — the drop-in config; read when creating or changing `.golangci.yml`.
- **[source-guards.md](references/source-guards.md)** — read when a rule must hold everywhere and no linter can say it: the helpers, allowance lists, four worked guards, and proving a guard fails.
- **[modern-go.md](references/modern-go.md)** — read when upgrading Go, or unsure an API exists: the idioms from 1.21 to 1.27, each with its release note and its `go fix` analyser.

## Related skills

- **[go-architecture](../go-architecture/SKILL.md)** — the ownership and layout rules these gates hold
- **[go-testing](../go-testing/SKILL.md)** — what goes inside the tests the gates run
- **[go-errors](../go-errors/SKILL.md)** — the error and logging rules errorlint, errcheck and sloglint enforce
