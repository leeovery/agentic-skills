# Commands with cobra

**A command is an edge: it parses the command line into typed values, calls one operation, and writes the result. The tree is built fresh on every call, so a test runs it exactly as `run` does.**

The rules are the [SKILL.md](../SKILL.md)'s, whatever parses the command line; this is how they land in cobra.

Contents: the root · a command · output · exit codes · testing a command · edge cases.

cobra (`github.com/spf13/cobra`) is the one library here; everything else is the standard library.

## The root

```go
// internal/cli/root.go

// Deps is what the commands need, built once by run.
type Deps struct {
	Canceller order.Canceller
	Out       io.Writer // data: what a pipe or a script reads
}

// NewRootCommand builds the command tree.
func NewRootCommand(deps Deps) *cobra.Command {
	root := &cobra.Command{
		Use:   "shop",
		Short: "Manage orders",
		// Execute's one renderer prints every error, so cobra prints none, and
		// no usage, which would bury the error under it.
		SilenceErrors: true,
		SilenceUsage:  true,
	}
	root.AddCommand(newCancelCommand(deps))
	return root
}
```

- **`Deps` holds the operations,** built in `run` with their stores and clocks, and the writer data goes to. A command picks the one it calls. Nothing in `cli` opens a file, reads the environment or builds a client.
- **No package-level `var rootCmd`, no `init()` calling `AddCommand`.** A package-level tree keeps its flag values from one test to the next, and can't take fakes.
- **Each subcommand comes from its own constructor,** `newCancelCommand(deps) *cobra.Command`, in its own file.

## A command

```go
// internal/cli/cancel.go

func newCancelCommand(deps Deps) *cobra.Command {
	var reason string
	cmd := &cobra.Command{
		Use:   "cancel <order-id>",
		Short: "Cancel an order that hasn't shipped",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			id, err := order.ParseID(args[0])
			if err != nil {
				return usageError{err}
			}
			r, err := order.ParseReason(reason)
			if err != nil {
				return usageError{err}
			}
			o, err := deps.Canceller.Cancel(cmd.Context(), id, r)
			if err != nil {
				return err
			}
			_, err = fmt.Fprintf(deps.Out, "%s cancelled\n", o.ID)
			return err
		},
	}
	cmd.Flags().StringVar(&reason, "reason", "customer", "why: customer, stock or unpaid")
	return cmd
}
```

- **`RunE`, never `Run`.** The error goes back to `Execute`, which shows it through the one renderer and picks the exit code; the command doesn't print it.
- **Parse first.** Arguments and flag values become typed values (`order.ID`, `order.Reason`) before anything else runs. A value that doesn't parse is a usage error. A word whose meaning takes reads (a name that may be a branch, a tag or a build) is passed on as typed, for the operation to resolve.
- **Flags bind to variables local to the constructor,** so every fresh tree has fresh flags.
- **The context is `cmd.Context()`.** It carries `run`'s interrupt, so Ctrl-C cancels the work.
- **Return the write's error.** Output redirected to a full disk is a failure like any other.

## Output

- **Data goes to `deps.Out`:** what a pipe or a script consumes. `shop list | wc -l` counts orders, not warnings. `run` passes its `stdout`; a test passes a buffer.
- **Not `cmd.OutOrStdout()`.** `run` points it at its `stdout` for cobra's help and version, output a person asked for; the commands' data goes through `deps.Out`, so what a command writes is its own and a test hands it a writer of its own.
- **Diagnostics go to `cmd.ErrOrStderr()`:** progress, warnings, prompts. `run` points it at its `stderr`.
- **Input comes from `cmd.InOrStdin()`.**
- **Never `cmd.Print`, `cmd.Println` or `cmd.Printf` for data.** cobra sends them to stderr unless an output writer is set.
- **A value is printed through its one formatter** ([go-architecture](../../go-architecture/SKILL.md)), never a `%.2f` of the command's own.

## Exit codes

| Code | When |
|---|---|
| 0 | it worked; `--help` and `--version` too |
| 1 | the command failed |
| 2 | a usage error: an unknown command or flag, the wrong number of arguments, a value that doesn't parse |
| 3 to 125 | the tool's own, for an outcome a script branches on without reading the message, such as refused by a rule or waiting on a person; each one listed here |
| 130 | the user interrupted it: 128 plus SIGINT's number, as a shell reports it; a shell reports 126 and up itself, so the tool's own stop at 125 |

One function sets them, a code of the tool's own as a case of its own. `run` returns its code, and `main` exits with that:

```go
// usageError is a command line the program can't act on, found after it parsed.
type usageError struct{ err error }

func (e usageError) Error() string { return e.err.Error() }
func (e usageError) Unwrap() error { return e.err }

// Execute runs root on args, shows any error through show, the one renderer
// of errors, and returns the process's exit code.
func Execute(ctx context.Context, root *cobra.Command, args []string, show func(error)) int {
	if args == nil {
		args = []string{} // given nil, cobra parses the process's own arguments
	}
	root.SetArgs(args)
	// The parse marker: cobra checks the command, flags and argument count
	// before the root's persistent hook runs, so an error before it is the
	// caller's typing.
	parsed := false
	root.PersistentPreRunE = func(*cobra.Command, []string) error {
		parsed = true
		return nil
	}
	err := root.ExecuteContext(ctx)
	if err == nil {
		return 0
	}
	if !parsed {
		err = usageError{err}
	}
	show(err)
	if ctx.Err() != nil {
		return 130
	}
	if _, ok := errors.AsType[usageError](err); ok {
		return 2
	}
	return 1
}

// ShowFor is the one renderer of errors for the run args ask for: the JSON
// document on stdout with --json, else text on stderr. It reads args as
// written, up to "--", since a parse failure is shown before any flag is read.
func ShowFor(args []string, stdout, stderr io.Writer) func(error) {
	for _, a := range args {
		if a == "--" {
			break
		}
		if a == "--json" {
			return ShowJSON(stdout)
		}
	}
	return ShowError(stderr)
}

// ShowError is the one renderer of errors in text: "shop: <message>", then
// the fix the error carries on a line of its own.
func ShowError(w io.Writer) func(error) {
	return func(err error) {
		// A failed write to stderr has nowhere left to go.
		_, _ = fmt.Fprintf(w, "shop: %s\n", messageOf(err))
		if fix := fixOf(err); fix != "" {
			_, _ = fmt.Fprintf(w, "fix: %s\n", fix)
		}
	}
}

// messageOf is the error's words. An interrupt is said as the person did it,
// "interrupted", then what was done before it where the error says so.
func messageOf(err error) string {
	if !errors.Is(err, context.Canceled) {
		return err.Error()
	}
	if p, ok := errors.AsType[failure.Partial](err); ok {
		return "interrupted; " + p.Done()
	}
	return "interrupted"
}

// fixOf is what the error says the person does about it, if anything.
func fixOf(err error) string {
	if f, ok := errors.AsType[failure.Fixed](err); ok {
		return f.Fix()
	}
	return ""
}
```

```go
// cmd/shop/main.go

func main() { os.Exit(run(context.Background(), os.Args[1:], os.Getenv, os.Stdout, os.Stderr)) }

func run(ctx context.Context, args []string, getenv func(string) string, stdout, stderr io.Writer) int {
	ctx, stop := signal.NotifyContext(ctx, os.Interrupt)
	defer stop()

	show := cli.ShowFor(args, stdout, stderr) // first, so a setup failure shows in the mode asked for
	store, err := filestore.Open(getenv("SHOP_DATA_DIR"))
	if err != nil {
		show(err)
		return 1
	}
	payments := &gateway.Client{HTTP: &http.Client{}, BaseURL: getenv("SHOP_GATEWAY_URL"), Timeout: 10 * time.Second}
	deps := cli.Deps{
		Canceller: order.Canceller{Orders: store, Payments: payments, Now: time.Now},
		Out:       stdout,
	}
	root := cli.NewRootCommand(deps) //nolint:contextcheck // NewRootCommand takes no context; the command gets ctx from ExecuteContext
	root.SetOut(stdout)
	root.SetErr(stderr)
	return cli.Execute(ctx, root, args, show)
}
```

- **Execute owns the root's one hook, as the parse marker.** cobra checks the command, flags and argument count before the persistent hook runs, so an error before it ran is a usage error.
- **`usageError` covers what the command checks itself** once the line has parsed: an id or a flag value that doesn't parse.
- **`errors.AsType`** is the generic form of `errors.As` ([Go 1.26](https://go.dev/doc/go1.26)).
- **`main` exits with what `run` returns, and nothing else exits:** `os.Exit` and `log.Fatal` appear in `main` alone. `os.Exit` skips deferred calls, so `main` holds none, and `run` defers `stop`.
- **Each error is printed once, by one renderer.** cobra's own error and usage printing is off; `Execute` shows a command's error through `show`, a parse failure as a `usageError`, and `run` shows a setup failure through the same `show` before it returns 1.
- **The renderer is chosen first,** from the arguments as written, so a setup failure or a parse failure under `--json` is the document too ([json-output.md](json-output.md)).
- **A fix is the error's, its layout the renderer's:** an error that carries one meets `failure.Fixed` ([go-errors' matching.md](../../go-errors/references/matching.md#several-identities)), and each renderer shows it its own way, a `fix:` line in text, a member in JSON.
- **An interrupt reads "interrupted",** never "context canceled", whatever wraps it; one that stopped partway meets `failure.Partial`, which says what it did first ([writes.md](writes.md#saying-what-landed)).
- **contextcheck misfires on the `NewRootCommand` line:** `run` has a context and the constructor takes none. The command gets the context from `ExecuteContext`, so the nolint says so ([go-quality's gates.md](../../go-quality/references/gates.md)).

## Testing a command

Build a fresh tree with fakes, capture both streams, and run it through `ShowFor` and `Execute`, as `run` does once its setup is done:

```go
func execute(ctx context.Context, t *testing.T, args ...string) (code int, stdout, stderr string) {
	t.Helper()
	var out, errOut bytes.Buffer
	root := cli.NewRootCommand(newDeps(&out)) //nolint:contextcheck // NewRootCommand takes no context; the command gets ctx from ExecuteContext
	root.SetOut(&out)
	root.SetErr(&errOut)
	code = cli.Execute(ctx, root, args, cli.ShowFor(args, &out, &errOut))
	return code, out.String(), errOut.String()
}

func TestExecuteExitCodes(t *testing.T) {
	tests := []struct {
		name string
		args []string
		want int
	}{
		{"cancels a pending order", []string{"cancel", "ord-1"}, 0},
		{"refuses an unknown command", []string{"cancle", "ord-1"}, 2},
		{"refuses an unknown flag", []string{"cancel", "ord-1", "--force"}, 2},
		{"refuses a missing order id", []string{"cancel"}, 2},
		{"refuses an unknown reason", []string{"cancel", "ord-1", "--reason", "bored"}, 2},
		{"fails for an order that has shipped", []string{"cancel", "ord-2"}, 1},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			code, _, stderr := execute(t.Context(), t, tt.args...)
			if code != tt.want {
				t.Errorf("Execute(%q) = %d, want %d; stderr:\n%s", tt.args, code, tt.want, stderr)
			}
		})
	}
}
```

- `newDeps(&out)` builds the operations over a fake store holding `ord-1` (pending) and `ord-2` (shipped), with data going to `out`.
- `t.Context()` ([Go 1.24](https://go.dev/doc/go1.24)) is the test's context; a cancelled one checks the 130.
- Check stdout and stderr apart: data in one, the error in the other.
- Test what the command translates: parsing, output, exit codes. What `Cancel` decides is tested on `Cancel`.

## Edge cases

- **Only the root has a persistent hook, and `Execute` sets it.** cobra runs the nearest `PersistentPreRun(E)` alone, so a subcommand's own hook skips the root's, and the parse marker with it. Don't reach for cobra's package-level `EnableTraverseRunHooks` instead.
- **Required flags and flag groups are checked after the hooks,** so a missing `MarkFlagRequired` flag exits 1 with no usage. Check required input in the parse step and return a `usageError`.
- **`SetArgs(nil)` reads `os.Args`,** a test binary's flags included. `Execute` passes an empty slice instead.
- **Usage stays off.** With `SilenceUsage` unset, cobra prints usage after an error to the output writer, stdout here, burying the error and landing in a pipe someone is reading; the renderer shows the error alone. Help asked for, `--help`, still goes to stdout.
- **A group command without `RunE` prints its help and exits 0,** run alone or given a subcommand it doesn't have (`shop invoice bogus`). Only the root reports an unknown command.
- **A secret never arrives as a flag value.** It shows in `ps` and the shell's history, and cobra quotes a bad flag value in its error. Read it from `cmd.InOrStdin()`, or from the environment in `run`.
