# Modern Go

**Write what the module's `go` line allows, and when unsure an API exists, ask the installed toolchain rather than memory.**

Contents: checking before writing · upgrading · by version · json in 1.27 · old patterns that still look right.

## Checking before writing

- **The version:** the `go` line in `go.mod` decides what the code may use, not the toolchain installed.
- **Whether an API exists:** `go doc strings.CutLast` answers from the installed toolchain.
- **Which release added it:** `grep -l 'func CutLast' "$(go env GOROOT)"/api/go1.*.txt` names the release notes' file.
- **Using an API newer than the `go` line** fails `go vet`'s `stdversion` check, which `go test` also runs from [Go 1.27](https://go.dev/doc/go1.27). A language feature newer than it fails to compile.

## Upgrading

1. Raise the `go` line (and `toolchain`, if the module pins one), then `go mod tidy`.
2. `go fix ./...`, then again, until it changes nothing. `go fix -diff ./...` previews, and `go tool fix help` lists the analysers.
3. Run the gates.
4. Commit that alone: it changes how the code is written, not what it does.
5. Make the changes that alter behaviour by hand, each in a change of its own, with tests: moving to `math/rand/v2`, `omitzero` on a field whose JSON readers expect it, `encoding/json/v2`, `os.Root` for paths from outside.

`go fix` only rewrites what is safe at the file's version. The rows below without an analyser are by hand.

## By version

| Since | Write | In place of | `go fix` |
|---|---|---|---|
| [1.19](https://go.dev/doc/go1.19) | `atomic.Int64`, `atomic.Pointer[T]` | `atomic.AddInt64(&n, 1)` on a bare `int64` | `atomictypes` |
| [1.21](https://go.dev/doc/go1.21) | `min`, `max`, `clear` | if/else clamps; delete loops | `minmax` |
| 1.21 | `slices`, `maps`, `cmp` | `sort.Slice` on plain values; search and copy loops; `golang.org/x/exp` | `slicessort`, `slicescontains`, `mapsloop` |
| 1.21 | `log/slog`, with a `*slog.Logger` passed in | `log`; third-party loggers | — |
| 1.21 | `sync.OnceValue`, `sync.OnceValues` | `sync.Once` beside a variable, its error dropped | — |
| 1.21 | `context.WithoutCancel`, `context.AfterFunc` | a hand-made detached context; a goroutine waiting on `Done` | — |
| [1.22](https://go.dev/doc/go1.22) | a fresh loop variable each iteration | `x := x` | `forvar` |
| 1.22 | `for i := range n` | `for i := 0; i < n; i++` | `rangeint` |
| 1.22 | `cmp.Or`, `slices.Concat`, `reflect.TypeFor[T]()` | defaulting `if`s; chained `append`s; `reflect.TypeOf((*T)(nil)).Elem()` | `reflecttypefor` |
| 1.22 | `math/rand/v2` | `math/rand` | — |
| 1.22 | `http.ServeMux` patterns: `"GET /orders/{id}"`, `r.PathValue("id")` | a router for plain routing | — |
| [1.23](https://go.dev/doc/go1.23) | iterators: `iter.Seq`, `range` over a function, `slices.Collect`, `slices.Sorted(maps.Keys(m))`, `slices.Backward` | callbacks; channel generators; collect then sort | `slicesbackward`, `mapsloop` |
| 1.23 | `time.After` and unstopped timers outside hot loops; they're collected | `Stop` and draining by habit | — |
| [1.24](https://go.dev/doc/go1.24) | `t.Context()`, `t.Chdir()` | `context.WithCancel` and a cleanup; `os.Chdir` and back | `testingcontext` |
| [1.24](https://go.dev/doc/go1.24) | `for b.Loop() { … }` | `for i := 0; i < b.N; i++` with `b.ResetTimer` | by hand |
| [1.24](https://go.dev/doc/go1.24) | `json:",omitzero"` | `omitempty` on structs and `time.Time`, which never omitted them | `omitzero` deletes the dead `omitempty`; adding `omitzero` changes the output, so it's by hand |
| [1.24](https://go.dev/doc/go1.24) | `strings.SplitSeq`, `strings.FieldsSeq`, `strings.Lines` | ranging over `strings.Split` | `stringsseq` |
| [1.24](https://go.dev/doc/go1.24) | `os.OpenRoot` and `os.Root` | checking paths from outside for `..` by hand | — |
| [1.24](https://go.dev/doc/go1.24) | `crypto/rand.Text`; `runtime.AddCleanup` | hand-encoded random tokens; `runtime.SetFinalizer` | — |
| [1.24](https://go.dev/doc/go1.24) | a `tool` directive: `go get -tool`, then `go tool x` | a `tools.go` of blank imports | — |
| [1.25](https://go.dev/doc/go1.25) | `wg.Go(f)` | `wg.Add(1)`, `go`, `defer wg.Done()` | `waitgroupgo` |
| [1.25](https://go.dev/doc/go1.25) | `testing/synctest`: `synctest.Test(t, f)` | real sleeps; a fake clock injected only to wait | — |
| [1.25](https://go.dev/doc/go1.25) | `reflect.TypeAssert`, `http.CrossOriginProtection`, `t.Output()`, `t.Attr` | `v.Interface().(T)`; hand-rolled CSRF tokens | — |
| [1.26](https://go.dev/doc/go1.26) | `new(expr)`: `new(42)`, `new(time.Now())` | `ptr(x)` helpers and temporaries | `newexpr` |
| [1.26](https://go.dev/doc/go1.26) | `errors.AsType[*NotFoundError](err)` | `var e *NotFoundError; errors.As(err, &e)` | `errorsastype` |
| [1.26](https://go.dev/doc/go1.26) | `//go:fix inline` on a deprecated wrapper | migrating its callers by hand | `inline` |
| [1.26](https://go.dev/doc/go1.26) | `slog.NewMultiHandler`, `t.ArtifactDir()` | a fan-out handler; ad hoc output directories | — |
| [1.27](https://go.dev/doc/go1.27) | generic methods: `func (l List[E]) Map[R any](f func(E) R) List[R]` | a method per type, or a free function | — |
| [1.27](https://go.dev/doc/go1.27) | promoted fields named in a literal: `Order{ID: "o1", Total: 2}` | `Order{Base: Base{ID: "o1"}, Total: 2}` | `embedlit` |
| [1.27](https://go.dev/doc/go1.27) | `strings.CutLast`, `bytes.CutLast` | `LastIndex` and slicing | — |
| [1.27](https://go.dev/doc/go1.27) | `synctest.Sleep(d)`, `httptest.NewTestServer(t, h)` | `time.Sleep` then `synctest.Wait()`; a loopback server inside a bubble | — |
| [1.27](https://go.dev/doc/go1.27) | the `uuid` package: `uuid.New()`, `uuid.Parse` | a third-party UUID module | — |
| [1.27](https://go.dev/doc/go1.27) | `encoding/json/v2`, now stable | v1's quirks, where new code can choose | — |

## json in 1.27

- `encoding/json` now runs on the v2 implementation ([Go 1.27](https://go.dev/doc/go1.27)) and keeps v1's behaviour; its error text can differ, so tests that match it break. They shouldn't match it.
- `encoding/json/v2` is stricter by default: duplicate names and invalid UTF-8 are errors, names match exactly, nil slices and maps encode as `[]` and `{}`. Moving a format to v2 changes its bytes, so it's a change of its own, pinned by tests.

## Old patterns that still look right

- `tt := tt` in a table loop: gone since 1.22.
- `for i := 0; i < b.N; i++` with `b.ResetTimer()`: `b.Loop()` since [Go 1.24](https://go.dev/doc/go1.24) times only the loop.
- `context.Background()` in a test: `t.Context()` since [Go 1.24](https://go.dev/doc/go1.24), cancelled before the test's cleanups run.
- `synctest.Run`: the 1.24 experiment's name; since [Go 1.25](https://go.dev/doc/go1.25) it's `synctest.Test`.
- `time.After` called a leak: since 1.23 an unstopped timer is collected.
- `sync.Once` and a variable holding an error: `sync.OnceValues` since 1.21.
- `interface{}`: `any`, since 1.18 (`go fix`'s `any`).

The version-to-idiom table's shape comes from [JetBrains/go-modern-guidelines](https://github.com/JetBrains/go-modern-guidelines) (Apache-2.0); its rows are in our own words.
