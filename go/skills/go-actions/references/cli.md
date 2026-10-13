# Commands

**A command is an edge: it parses the command line into typed values, calls one operation, and writes the result. The tree is built fresh on every call, so a test runs it exactly as `run` does.**

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
		// Runs once the command line has parsed. A failure after this is the
		// command's, not the caller's typing, so usage would only bury it.
		PersistentPreRunE: func(cmd *cobra.Command, _ []string) error {
			cmd.SilenceUsage = true
			return nil
		},
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

- **`RunE`, never `Run`.** The error goes back to `Execute`, which picks the exit code. cobra prints it once, as `Error: …`; the command doesn't print it too.
- **Parse first.** Arguments and flag values become typed values (`order.ID`, `order.Reason`) before anything else runs. A value that doesn't parse is a usage error.
- **Flags bind to variables local to the constructor,** so every fresh tree has fresh flags.
- **The context is `cmd.Context()`.** It carries `run`'s interrupt, so Ctrl-C cancels the work.
- **Return the write's error.** Output redirected to a full disk is a failure like any other.

## Output

- **Data goes to `deps.Out`:** what a pipe or a script consumes. `shop list | wc -l` counts orders, not warnings. `run` passes its `stdout`; a test passes a buffer.
- **Not `cmd.OutOrStdout()`.** To point it at `run`'s `stdout`, `run` would call `root.SetOut`, and cobra prints usage to that writer too: a bad command line would put its usage into the pipe.
- **Diagnostics go to `cmd.ErrOrStderr()`:** progress, warnings, prompts. `run` points it at its `stderr`, where cobra prints a command's error.
- **Input comes from `cmd.InOrStdin()`.**
- **Never `cmd.Print`, `cmd.Println` or `cmd.Printf` for data.** cobra sends them to stderr unless an output writer is set.
- **A value is printed through its one formatter** ([go-architecture](../../go-architecture/SKILL.md)), never a `%.2f` of the command's own.

## Exit codes

| Code | When |
|---|---|
| 0 | it worked; `--help` and `--version` too |
| 1 | the command failed |
| 2 | a usage error: an unknown command or flag, the wrong number of arguments, a value that doesn't parse |
| 130 | the user interrupted it: 128 plus SIGINT's number, as a shell reports it |

One function sets them. `run` returns its code, and `main` exits with that:

```go
// usageError is a command line the program can't act on, found after it parsed.
type usageError struct{ err error }

func (e usageError) Error() string { return e.err.Error() }
func (e usageError) Unwrap() error { return e.err }

// Execute runs root on args and returns the process's exit code. cobra has
// already printed any error.
func Execute(ctx context.Context, root *cobra.Command, args []string) int {
	if args == nil {
		args = []string{} // given nil, cobra parses the process's own arguments
	}
	root.SetArgs(args)
	cmd, err := root.ExecuteContextC(ctx)
	switch {
	case err == nil:
		return 0
	case ctx.Err() != nil:
		return 130
	case !cmd.SilenceUsage:
		// The root's hook never ran: an unknown command, a bad flag, or the
		// wrong number of arguments.
		return 2
	}
	if _, ok := errors.AsType[usageError](err); ok {
		return 2
	}
	return 1
}
```

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

- **The hook doubles as the parse marker.** cobra checks the command, flags and argument count before the persistent hook runs, so an error with `SilenceUsage` still unset failed there. The same hook keeps usage off a failure that isn't the caller's typing.
- **`usageError` covers what the command checks itself** once the line has parsed: an id or a flag value that doesn't parse.
- **`errors.AsType`** is the generic form of `errors.As` ([Go 1.26](https://go.dev/doc/go1.26)).
- **`main` exits with what `run` returns, and nothing else exits:** `os.Exit` and `log.Fatal` appear in `main` alone. `os.Exit` skips deferred calls, so `main` holds none, and `run` defers `stop`.
- **Each error is printed once.** A setup failure `run` prints itself, to `stderr`, and returns 1. A command's error cobra prints, so `Execute` only picks its code.
- **contextcheck misfires on the `NewRootCommand` line:** `run` has a context and the constructor takes none. The command gets the context from `ExecuteContextC`, so the nolint says so ([go-quality's gates.md](../../go-quality/references/gates.md)).

## Testing a command

Build a fresh tree with fakes, capture both streams, and run it through `Execute`, as `run` does:

```go
func execute(ctx context.Context, t *testing.T, args ...string) (code int, stdout, stderr string) {
	t.Helper()
	var out, errOut bytes.Buffer
	root := cli.NewRootCommand(newDeps(&out)) //nolint:contextcheck // NewRootCommand takes no context; the command gets ctx from ExecuteContext
	// cobra's help and usage too, which run leaves on the process's own streams.
	root.SetOut(&errOut)
	root.SetErr(&errOut)
	code = cli.Execute(ctx, root, args)
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
- Check stdout and stderr apart: data in one, the error and any usage in the other.
- Test what the command translates: parsing, output, exit codes. What `Cancel` decides is tested on `Cancel`.

## Edge cases

- **Only the root has a persistent hook.** cobra runs the nearest `PersistentPreRun(E)` alone, so a subcommand's own hook skips the root's, and the parse marker with it. Don't reach for cobra's package-level `EnableTraverseRunHooks` instead.
- **Required flags and flag groups are checked after the hooks,** so a missing `MarkFlagRequired` flag exits 1 with no usage. Check required input in the parse step and return a `usageError`.
- **`SetArgs(nil)` reads `os.Args`,** a test binary's flags included. `Execute` passes an empty slice instead.
- **Usage after an error goes to the output writer when one is set,** else to the process's stderr. That's why data has its own writer in `Deps`: `run` never calls `root.SetOut`, so a bad command line can't print usage into the pipe.
- **A group command without `RunE` prints its help and exits 0,** run alone or given a subcommand it doesn't have (`shop invoice bogus`). Only the root reports an unknown command.
- **A secret never arrives as a flag value.** It shows in `ps` and the shell's history, and cobra quotes a bad flag value in its error. Read it from `cmd.InOrStdin()`, or from the environment in `run`.
