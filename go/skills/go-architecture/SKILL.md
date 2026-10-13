---
name: go-architecture
description: Package layout and ownership for Go — one job per package, one owner per rule, and main the only place that wires and exits. Use when starting a module, deciding where code lives, adding a package or helper, wording a value for display, or finding one rule computed in two places.
---

# Go Architecture

Every package has one job and every rule one owner. `main` wires the program and is the only place that exits.

## Core rules

1. **One owner per rule.** A meaning two callers need (how a value reads, whether an order can be refunded, which account a payment goes to) is one function, and the second caller calls it. A copy you find is lifted into the owner, never patched where it sits. *Two copies drift: the CLI rounds a ratio one way, the dashboard the other, and each looks right alone.* Held by a source guard where a raw read outside the owner is the risk.
2. **Each kind of value people read is worded by one function.** Durations, dates, counts, money and ratios go through `format.Duration`, `format.Money` and their siblings; display and command code never hold a time layout or a `%.1f`. *Wording is the rule copied most, and its copies disagree in the last digit.* Held by a source guard on layouts and float verbs outside `format`.
3. **Shared rules come first.** When work is split across agents or PRs, list the meanings more than one part needs and build each once, with tests, before the parts start. *Otherwise each part rebuilds the meaning locally, and review is where the rule gets found.*
4. **One package per job,** under `internal/`, named for what it provides, its job stated in its package doc. Never `util`, `common`, `helpers`, `misc`, `types` or `models`. *A grab-bag name gives code no reason to live anywhere else.* Held by staticcheck's ST1000 and a source guard on names.
5. **A helper is a small named function in the package that owns its concern,** or a package with one job (`redact`, `atomicfile`). It holds no business logic. *The job gets one home without a grab bag.*
6. **`main` exits with what `run` returns, and nothing else exits.** `run(ctx, args, getenv, stdout, stderr) int` builds the dependencies and returns the exit code: 1 for a setup failure, which it prints once to `stderr`, else what the edge's one exit-code function returns ([go-actions](../go-actions/SKILL.md)). *Defers run, and a test calling `run` covers the wiring and checks the code.* Held by forbidigo: `os.Exit` and `log.Fatal` outside `cmd/`.
7. **No mutable package state:** no package loggers, registries, singletons or exported mutable tables. The environment, arguments, home directory and standard streams are read in `main` and passed in. *Package state is a dependency no signature shows, and it stops tests running in parallel.* Held by gochecknoinits, sloglint (no global logger) and forbidigo (`os.Getenv`, `os.Args`, `os.UserHomeDir`, the standard streams) outside `cmd/`.
8. **Imports point inward.** Domain packages never import `net/http`, cobra or a terminal UI; the edges import the domain. *The domain is tested without a transport.* Held by depguard, by path.
9. **A file does one job.** Split by job, not by line count, and split a package when its callers use one part without the other. *A reader finds the job by the file's name.*

## Pattern

```go
// cmd/shop/main.go
func main() { os.Exit(run(context.Background(), os.Args[1:], os.Getenv, os.Stdout, os.Stderr)) }

func run(ctx context.Context, args []string, getenv func(string) string, stdout, stderr io.Writer) int {
	ctx, stop := signal.NotifyContext(ctx, os.Interrupt)
	defer stop()

	log := slog.New(slog.NewTextHandler(stderr, nil))
	store, err := filestore.Open(getenv("SHOP_DATA_DIR"))
	if err != nil {
		log.Error("open store", "err", err)
		return 1
	}
	payments := &gateway.Client{HTTP: &http.Client{}, BaseURL: getenv("SHOP_GATEWAY_URL"), Timeout: 10 * time.Second}
	deps := cli.Deps{
		Canceller: order.Canceller{Orders: store, Payments: payments, Now: time.Now},
		Out:       stdout,
	}
	root := cli.NewRootCommand(deps) //nolint:contextcheck // NewRootCommand takes no context; the command gets ctx from ExecuteContext
	root.SetErr(stderr)
	return cli.Execute(ctx, root, args)
}
```

## Where it goes

| You're adding | It goes |
|---|---|
| a figure or decision another package already makes | a call to its owner; lift it there first if it sits inline |
| how a value reads to people | `format`, beside its siblings |
| how a value is stored or sent | its own function beside the type, pinned by a test; never the display one |
| a domain type, its rules and its operations | the package named for the domain (`order`) |
| a command, handler or worker | the edge (`cli`, `server`), calling the domain |
| a small helper | the package whose concern it serves, unexported |
| a helper several packages need | a package named for its one job (`redact`) |
| config, flags, the environment, the logger | `run`, passed down |

## Anti-patterns

- ❌ Copying the calculation or the `%.1f` from the screen that already shows a figure → call its owner; lift it first if it's inline.
- ❌ Patching the copy that's wrong → delete it, call the owner, and test the case where the copies differed.
- ❌ A `types` or `models` package to break an import cycle → the cycle says a package reaches the wrong way: move the type to the package whose job it is, or declare a consumer-side interface.
- ❌ A package per layer (`handlers`, `services`, `repositories`) → a package per domain or job, its layers as files.
- ❌ `log.Fatal` or `os.Exit` inside a package → return the error to `run`.
- ❌ `var log = slog.Default()` at package level → take a `*slog.Logger` as a field or parameter.
- ❌ `os.Getenv` where the value is used → a config field or a `getenv func(string) string`, filled in `run`.
- ❌ A domain package importing `net/http` for a status code → return a domain error; the edge maps it.
- ❌ Splitting a long file into `order2.go` → split by job: `cancel.go`, `refund.go`.

## References

- **[layout.md](references/layout.md)** — read when starting a module or adding a package or file: the tree, `main` and `run` with their test, naming, package docs, import cycles.
- **[ownership.md](references/ownership.md)** — read when two places compute one thing, before adding a figure another screen shows, or before splitting work: finding the owner, lifting a copy, the shared-rules-first checklist, the guards.
- **[decisions.md](references/decisions.md)** — read when unsure what to reach for: a function, an operation struct, a named type, an interface or a package.

## Related skills

- **[go-actions](../go-actions/SKILL.md)** — the shape of an operation, and the thin edges that call it
- **[go-interfaces](../go-interfaces/SKILL.md)** — the seams `run` fills: stores, clocks, outside services
- **[go-types](../go-types/SKILL.md)** — named types, and stored formats kept apart from display
- **[go-quality](../go-quality/SKILL.md)** — the lint config and source guards that hold these rules
