# Gates

**Five commands, in order, each passing clean, before any work is called done.**

Contents: the five · in CI, beside them · a CI script · before reporting done · golangci-lint v2 · what the drop-in's linters expect.

## The five

```sh
test -z "$(gofmt -l .)"   # gofmt -l lists unformatted files, yet exits 0
go vet ./...
go test -race ./...
golangci-lint run
go build ./...
```

The fast checks run first, so a failure costs least. A project with its own test runner (a sandboxed script, a `TestMain` guard) runs that in place of `go test`, with the race detector still on.

| Gate | Catches | Watch for |
|---|---|---|
| `gofmt -l .` | anything not in the one format | it prints file names but exits 0: test that its output is empty |
| `go vet ./...` | printf arguments that don't match the verbs, copied locks, a `cancel` never called, unreachable code, malformed struct tags, and from [Go 1.27](https://go.dev/doc/go1.27) `%w` given a `*E` where `E` itself is the error | `go test` runs only a handful of vet's checks: run vet itself |
| `go test -race ./...` | broken behaviour, and data races in the code the tests run | the detector sees only what runs: a goroutine no test starts is never checked |
| `golangci-lint run` | the rules in `.golangci.yml`, formatting included | it's v2: `golangci-lint config verify` checks the file against the schema |
| `go build ./...` | a package the compiler rejects, under the build's own constraints | it builds and discards: no binaries are written |

## In CI, beside them

- **`go mod tidy -diff`:** go.mod and go.sum match the imports. It prints what tidy would change, and fails when that isn't nothing.
- **`go fix -diff ./...`:** fails when a modernizer would still change something ([Go 1.26](https://go.dev/doc/go1.26)). golangci-lint's `modernize` reports the same findings, line by line.
- **`go tool govulncheck ./...`:** once `go get -tool golang.org/x/vuln/cmd/govulncheck` has pinned it in go.mod with a `tool` directive ([Go 1.24](https://go.dev/doc/go1.24)). It fetches the vulnerability database, so it runs where the network is allowed, never inside a sandbox that denies it. A finding means your code reaches the vulnerable function: upgrade that module, in a change of its own.

## A CI script

```sh
#!/bin/sh
set -eu

unformatted=$(gofmt -l .)
if [ -n "$unformatted" ]; then
	printf 'not gofmt-clean:\n%s\n' "$unformatted" >&2
	exit 1
fi
go vet ./...
go test -race ./...
golangci-lint run
go build ./...
go mod tidy -diff
go tool govulncheck ./...
```

## Before reporting done

- [ ] All five pass clean, and the report says which runner ran the tests.
- [ ] A gate failing on code you didn't touch is reported, not skipped or silenced.
- [ ] No `//nolint` without its linter and reason; no exclusion, limit or allowance entry loosened.
- [ ] Each new dependency has its reason in the PR.
- [ ] Each comment added says why, not what the code does or what changed.

## golangci-lint v2

- **The config starts `version: "2"`.** Settings live under `linters.settings`, exclusions under `linters.exclusions`, and gofmt and goimports under `formatters`. `gosimple` and `stylecheck` are now part of `staticcheck`.
- **`golangci-lint migrate`** rewrites a v1 file, and carries v1's default exclusions over as `exclusions.presets`. Read them before keeping them: `comments` hides missing doc comments, and `std-error-handling` hides unchecked `Close`, `Flush` and print errors.
- **The defaults hide findings:** past the third repeat of one finding, past fifty from one linter, and a second finding on a line already reported. The drop-in turns all three off, so a run shows everything.
- **After upgrading golangci-lint,** run `config verify`, then a run. New checks in a linter you already use land as findings; fix them in a commit of their own.
- **Locally,** `golangci-lint run --fix` applies the fixes linters offer, and `golangci-lint fmt` formats. Read the diff before committing either.

## What the drop-in's linters expect

- **errcheck** reports a call that drops an error, `defer f.Close()` included; `std-error-handling` stays off so it does. `_ = f()` is the deliberate, visible discard and passes, so no nolint is needed for one:

  ```go
  // A file only read: its Close error says nothing the read didn't.
  defer func() { _ = f.Close() }()

  // A file written: Close can lose the data, so its error is returned.
  defer func() { err = errors.Join(err, f.Close()) }()
  ```

- **contextcheck** misfires where a function that has a `ctx` calls a constructor that takes none: `run`, or a test helper, calling a cobra `NewRootCommand(deps)`. Nolint that one line with the reason: `//nolint:contextcheck // NewRootCommand takes no context; the command gets ctx from ExecuteContext`.
- **containedctx** reports every context kept in a struct. The one exception, a long-lived worker holding its own lifetime, carries a nolint saying so where the field is declared.
- **forbidigo** reports `os.Exit` in a `TestMain` too. Since Go 1.15 a `TestMain` that returns has its result passed to `os.Exit` for it, so call `m.Run()` and return.
- **revive's `exported`** wants a doc comment on every exported name in `internal/` as well: the package boundary is still an API to the rest of the module.
