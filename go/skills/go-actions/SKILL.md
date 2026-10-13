---
name: go-actions
description: Go domain operations as plain functions or small structs of their collaborators, called from thin cobra commands, HTTP handlers and workers. Use when adding or changing business logic, a CLI command or flag, an HTTP handler, a queue consumer, or a program's exit codes.
---

# Go Actions

Business logic lives in operations: a function, or a small struct of its collaborators with one verb method. Commands, handlers and workers only translate to and from them.

## Core rules

1. **An operation is a function until it needs collaborators; then it's a struct of them with one verb method.** `order.Total(lines)` needs nothing. `order.Canceller` holds its store and clock as fields, and `Cancel` takes `ctx` first. *One job per unit, and a test swaps a field for a fake.*
2. **Nothing per call lives on the struct.** A call's values are parameters, or a private struct built inside the method. *`main` builds the operation once and shares it; two calls at once would overwrite each other's fields.*
3. **Name it by package and verb: `order.Cancel`, or `order.Canceller` with `Cancel`.** No `Action`, `Service`, `Manager`, `Processor` or `Handler` suffix on a domain type, and no `order.CancelOrder`. *The package already says "order"; a suffix adds a word and no meaning.*
4. **Guards come first, as named checks.** Each precondition is an `ensureX(…) error` or a predicate method (`o.Status.Cancellable()`), and the happy path runs down the left margin. *The check reads as the rule it enforces, and the predicate answers the same question for every other caller.*
5. **Edges translate and do nothing more.** A `RunE`, an HTTP handler or a queue consumer parses its input into typed values, calls one operation, then writes the result or maps the error. *Logic in an edge can't be reused by the other edges, or tested without its transport.* depguard keeps `net/http`, cobra and terminal UI packages out of domain packages.
6. **Compose by holding.** An operation that needs another holds it, or a one-method interface of it, as a field. *It never redoes the other's steps against the store, so that rule keeps one owner, and a test fakes it in a line.*
7. **A write leaves no half state.** Writes that must land together go through one store call that takes a closure (`Orders.InTx(ctx, func(tx Store) error {…})`). A file is replaced whole: written beside the target, then renamed over it. *A failure partway leaves the old state or the new, never a mix the next run trips over.*
8. **cobra commands come from `NewRootCommand(deps)`, use `RunE`, and write where they're told.** No package-level command variables or `init`. `SilenceUsage` is set once the arguments parse. Data goes to the writer in `Deps`, diagnostics to `cmd.ErrOrStderr()`, and the context is `cmd.Context()`. *Each test builds a fresh tree with fakes and reads what it printed, and usage never lands in the data.* forbidigo bans `fmt.Print*`, `os.Stdout` and `os.Exit` in command packages.
9. **`main` exits with what `run` returns, and nothing else exits.** `run` returns what `cli.Execute` returns, the one function that sets exit codes: 0 for success, 1 for a failure, 2 for a usage error, 130 for an interrupt. cobra prints a command's error, once. *A script can tell a bad call from a failed one, and Ctrl-C reads as the shell's own.*

## Pattern

```go
// Canceller cancels orders that haven't shipped, refunds those paid, and puts
// their stock back.
type Canceller struct {
	Orders   Store
	Payments Refunder
	Now      func() time.Time
}

// Cancel cancels the order for reason, refunding it first if it was paid.
func (c Canceller) Cancel(ctx context.Context, id ID, reason Reason) (Order, error) {
	o, err := c.Orders.Order(ctx, id)
	if err != nil {
		return Order{}, fmt.Errorf("cancel: %w", err)
	}
	if err := ensureCancellable(o); err != nil {
		return Order{}, err
	}
	if o.Paid() {
		// Before the writes, as a refund can't roll back. It's keyed by the
		// payment, so a retry after a failed write refunds once.
		if err := c.Payments.Refund(ctx, o.Payment, o.Total); err != nil {
			return Order{}, fmt.Errorf("cancel: %w", err)
		}
	}
	o = o.Cancelled(reason, c.Now())
	err = c.Orders.InTx(ctx, func(tx Store) error {
		if err := tx.Save(ctx, o); err != nil {
			return err
		}
		return tx.ReleaseStock(ctx, o.ID)
	})
	if err != nil {
		return Order{}, fmt.Errorf("cancel: %w", err)
	}
	return o, nil
}

func ensureCancellable(o Order) error {
	if !o.Status.Cancellable() {
		return &NotCancellableError{ID: o.ID, Status: o.Status}
	}
	return nil
}
```

`run` builds it once, `order.Canceller{Orders: store, Payments: payments, Now: time.Now}`, and hands it to every edge that cancels.

## Decision table

| Need | Reach for |
|---|---|
| A calculation or rule with no collaborators | a function: `order.Total(lines)` |
| Logic that needs a store, a client or a clock | a struct of them, with one verb method |
| More than two inputs, or two of one type | an input struct ([go-types](../go-types/SKILL.md)) |
| A precondition | `ensureX(…) error`, or a predicate method on the type |
| One operation needs another | hold it, or a one-method interface of it |
| Writes that land together | one store call taking a closure |
| A command, handler or consumer | an edge: parse, call one operation, write or map the error |
| How the process exits | one function from error to exit code, whose code `run` returns and `main` exits with |

## Anti-patterns

- ❌ A loop over the store, a status check or a sum in `RunE` or a handler → an operation; the edge calls it.
- ❌ `OrderService` with a dozen methods → one operation per job, named by its verb.
- ❌ `c.current = o`, and helpers that read `c.current` → pass `o`, or build a private per-call struct.
- ❌ `CancelOrderAction`, `OrderManager`, `order.OrderCanceller` → `order.Canceller`.
- ❌ An operation that calls another's store methods to redo its steps → hold the other operation.
- ❌ Two `Save` calls that must both land, outside a transaction → one `InTx`.
- ❌ `*http.Request` or `*cobra.Command` passed into the domain → parse into typed values at the edge.
- ❌ `var rootCmd = &cobra.Command{…}` with `init()` adding children → `NewRootCommand(deps)`.
- ❌ `fmt.Println`, `os.Exit` or `log.Fatal` in a command → write to the writer in `Deps`, return the error.
- ❌ `context.Background()` in `RunE` → `cmd.Context()`.

## References

- **[operations.md](references/operations.md)** — read when writing or splitting an operation: function or struct, per-call state, guards, composition, writes, and the HTTP and worker edges.
- **[cli.md](references/cli.md)** — read when adding or changing a cobra command: the root and its deps, `RunE`, output, usage errors, exit codes, and testing a command.

## Related skills

- **[go-architecture](../go-architecture/SKILL.md)** — which package an operation lives in, and `main`.
- **[go-types](../go-types/SKILL.md)** — the ids, input structs and enums an operation takes.
- **[go-errors](../go-errors/SKILL.md)** — the errors a guard returns, and mapping them at the edge.
- **[go-interfaces](../go-interfaces/SKILL.md)** — the stores and one-method interfaces an operation holds, and their fakes.
- **[go-concurrency](../go-concurrency/SKILL.md)** — workers that run until shutdown, and `signal.NotifyContext`.
- **[go-testing](../go-testing/SKILL.md)** — testing an operation through its fakes.
