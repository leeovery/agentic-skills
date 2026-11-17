# Errors as Output

**Where a program shows its errors to people as they are, as a CLI does, the message is the program's output: written as prose, pinned by its tests, and reported through the one renderer, never a log.**

Contents: when this applies · messages are prose · tests pin the text · no logger · warnings in the result · checklist.

## When this applies

A server's errors reach a log, read by whoever is on call, and a caller that matches them. A CLI's reach the person who ran it, as `shop: <message>` on stderr or a JSON document's `message`, and that line is all they get. The rules for matching ([matching.md](matching.md)) still hold; what changes is how the message is written and checked, and where a failure that doesn't stop the run goes.

## Messages are prose

The person reads the whole chain at once, so it reads as a sentence, its first clause what couldn't be done:

```
can't cancel ord-2: it shipped Thu 14:20, so it can't be called back
can't refund ord-1: the gateway refused the shop's credentials; log in again · shop login
```

- **Lower case, no closing punctuation,** as rule 2's; each layer still adds what its cause lacks.
- **`%w` may sit mid-message,** where something must come after the cause: an outcome, or a command the person copies, which ends the line so nothing follows it.

  ```go
  return fmt.Errorf("can't refund %s: %w; log in again · shop login", id, err)
  ```

- **The verb phrase is the person's,** "can't refund", where a log's would be the operation's, "refund". Pick one form for the whole program.
- **A kind and a fix ride on the error,** not in its text, where the renderer shows them as lines of their own and JSON as members ([matching.md](matching.md#several-identities)).

## Tests pin the text

The message is output, so a test pins its bytes, as a golden file pins any output you own:

```go
func TestCancelRefusesAShippedOrder(t *testing.T) {
    _, err := c.Cancel(t.Context(), "ord-2", ReasonCustomer)

    if _, ok := errors.AsType[*NotCancellableError](err); !ok {
        t.Fatalf("Cancel(ord-2) = %v, want *NotCancellableError", err)
    }
    want := "can't cancel ord-2: it shipped Thu 14:20, so it can't be called back"
    if err.Error() != want {
        t.Errorf("Cancel(ord-2) message = %q, want %q", err.Error(), want)
    }
}
```

- **The match decides, the text is checked.** Code still branches with `errors.Is` and `errors.AsType`, never on the words; the test pins the words because a person reads them.
- **A wording change is a visible diff** in the test, reviewed like any change to output.

## No logger

A CLI's run has one output surface, the renderer, and nothing else writes: no `slog`, no `log`, no stray `fmt.Fprintln(os.Stderr, …)`. The logging rules ([logging.md](logging.md)) map onto it:

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

- [ ] Each message reads as a sentence to the person who ran the command; a copyable command ends its line.
- [ ] A kind and a fix ride on the error, not in its words.
- [ ] Each message a person sees is pinned exactly by a test, beside an `errors.Is` or `errors.AsType` match.
- [ ] Nothing writes but the renderer: no logger.
- [ ] A part that couldn't be read is a warning in the result, said where the part would show.
