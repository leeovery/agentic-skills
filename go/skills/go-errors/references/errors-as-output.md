# Errors as Output

**Where a program shows its errors to people as they are, as a CLI does, the message is the program's output: written as prose, pinned by its tests, and reported through the one renderer.**

Contents: when this applies · messages are prose · tests pin the text · a command that ends in one result · warnings in the result · checklist.

## When this applies

A server's errors reach a log, read by whoever is on call, and a caller that matches them. A CLI's reach the person who ran it, as `shop: <message>` on stderr or a JSON document's `message`, and that line is all they get. The rules for matching ([matching.md](matching.md)) still hold; what changes is how the message is written and checked, and where a failure that doesn't stop the run goes.

## Messages are prose

The person reads the whole chain at once, so it reads as a sentence, its first clause what couldn't be done, or, where part of it was, what was:

```
can't cancel ord-2: it has shipped, so it can't be called back
published to north and south; west: may have landed: the storefront didn't answer; east not tried
```

- **Lower case, no closing punctuation,** as rule 2's; each layer still adds what its cause lacks.
- **`%w` may sit mid-message,** where where things stand comes around the cause: what landed, what failed and why, then what wasn't tried. A write with no answer may have landed, and says so, never a plain failure ([go-actions' writes.md](../../go-actions/references/writes.md#saying-what-landed)):

  ```go
  return fmt.Errorf("published to %s; %s: %w; %s not tried", landed, store, err, untried)
  ```

- **The verb phrase is the person's,** "can't refund", where a log's would be the operation's, "refund". Pick one form for the whole program.
- **A kind and a fix ride on the error, never in its text,** as `AuthError` gives its fix through `Fix()` ([matching.md](matching.md#several-identities)), which the renderer finds whatever wraps it. The renderer shows the fix on a line of its own and JSON as a member, so a command the person copies is never cut from the middle of a sentence:

  ```
  shop: can't refund ord-1: the gateway refused the shop's credentials
  fix: shop login
  ```

- **No time in the person's zone,** nor any wording that depends on the output mode: the error goes to text and JSON alike. Where a time matters, the error carries the instant as a field, and the renderer words it ([go-architecture's ownership.md](../../go-architecture/references/ownership.md#who-owns-it)).

## Tests pin the text

The message is output, so a test pins its bytes, as a golden file pins any output you own:

```go
func TestCancelRefusesAShippedOrder(t *testing.T) {
	_, err := c.Cancel(t.Context(), "ord-2", ReasonCustomer)

	if _, ok := errors.AsType[*NotCancellableError](err); !ok {
		t.Fatalf("Cancel(ord-2) = %v, want *NotCancellableError", err)
	}
	want := "can't cancel ord-2: it has shipped, so it can't be called back"
	if err.Error() != want {
		t.Errorf("Cancel(ord-2) message = %q, want %q", err.Error(), want)
	}
}
```

- **The match decides, the text is checked.** Code still branches with `errors.Is` and `errors.AsType`, never on the words; the test pins the words because a person reads them.
- **A wording change is a visible diff** in the test, reviewed like any change to output.

## A command that ends in one result

The line is drawn per command, not per program. A command whose run ends in one result, a report or a document, has one output surface, the renderer, and nothing else writes: no `slog`, no `log`, no stray `fmt.Fprintln(os.Stderr, …)`; a degraded part is a warning in the result. A long-running command, or one whose work is a stream of events, as `shop settle` works through many invoices ([logging.md](logging.md)), may log, to stderr and never into a JSON run's stdout. The logging rules map onto a command of the first kind:

| A server | A CLI |
|---|---|
| log where the error stops | the renderer shows it where the run ends: the run's one failure |
| log and carry on degraded, at `Warn` | a warning in the result, and the run carries on |
| `Error` for what a person must act on | the run's failure, with its fix |
| `Info` for what happened | the result itself |
| `Debug` for diagnosis | nothing by default; a `--verbose` the renderer owns, if ever |
| the error under `err`, values as attributes | the warning's fields: what couldn't be done, why, and the fix |
| a secret type that redacts itself | the same type: it redacts itself in a message too |

Handle once still holds: a part reported as a warning isn't also returned, and an error returned isn't also warned of.

## Warnings in the result

A part that couldn't be read doesn't fail the run that can show the rest. It becomes a warning the result carries, so a script reading JSON sees it as a member, never as a line before the document:

```go
// Warning is a part of the result that couldn't be read, and the run carried on.
type Warning struct {
	Part string // what couldn't be read, for a script: "payouts"
	Err  error  // why, shown as the message
	Fix  string // what the person runs, if anything
}

// Report is the day's orders and payouts. Payouts the gateway can't give are a
// warning, and the orders still show.
func (r Reporter) Report(ctx context.Context, day Date) (Report, error) {
	orders, err := r.Orders.PlacedOn(ctx, day)
	if err != nil {
		return Report{}, fmt.Errorf("can't read the orders of %s: %w", day, err)
	}
	rep := Report{Day: day, Orders: orders}
	payouts, err := r.Payouts.On(ctx, day)
	if err != nil {
		rep.Warnings = append(rep.Warnings, Warning{Part: "payouts", Err: err})
		return rep, nil
	}
	rep.Payouts = payouts
	return rep, nil
}
```

- **What the warning stands for is said in the result,** "payouts unknown", never an empty list that reads as none.
- **A part whose loss empties the result** is the run's failure, not a warning.
- **A Ctrl-C is never a warning:** a read cut short by the context returns its error, so the run ends as interrupted.

## Checklist

- [ ] Each message reads as a sentence to the person who ran the command; a command to copy is its fix, never its words.
- [ ] A kind and a fix ride on the error, not in its words; the renderer lays them out.
- [ ] Each message a person sees is pinned exactly by a test, beside an `errors.Is` or `errors.AsType` match.
- [ ] A command that ends in one result writes only through the renderer: no logger. A long-running or streaming command may log, to stderr.
- [ ] A part that couldn't be read is a warning in the result, said where the part would show.
