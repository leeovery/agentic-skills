---
name: go-errors
description: Go errors as values, wrapped with what the cause lacks, handled once and mapped to a status or exit code at the edge, with logs through an injected slog logger. Use when creating, wrapping, matching or reporting errors, or adding a logger or a log line.
---

# Go Errors

An error is handled once: returned with what its cause lacks, matched by a caller that acts on it, or logged where it stops.

## Core rules

1. **Handle an error once.** Return it wrapped, log it and carry on degraded, or match it and act on it. Never log it and return it. *Logged and returned, it shows up once per layer, and the caller loses the choice of what it means.* No linter sees this one; review does.
2. **Wrap with the operation and the input the cause lacks:** `fmt.Errorf("decode order %s: %w", id, err)`. Lower case, `%w` last, no "failed to" or "error", no closing punctuation. *Each layer adds a phrase, so the chain reads as a path: `cancel: refund pay-9: gateway answered 503`.* staticcheck's ST1005 checks the case and punctuation.
3. **The call that leaves your code names what it worked on.** A decode, a parse or a request adds the file, the id or the URL; `os` errors already carry the path, `encoding/json`'s don't. Inside a package, a helper returns bare and its caller wraps once. *`invalid character '}'` with no file is useless, and a wrap at every frame reads `load: load: read: …`.*
4. **Make an error matchable only when a caller branches on it,** by the table below: a plain message, an exported `var ErrX`, or a `type XError struct`. *Every sentinel and error type is API that callers come to depend on.* errname checks the `ErrX` and `XError` names.
5. **Match with `errors.Is` and `errors.AsType[*XError]`** ([Go 1.26](https://go.dev/doc/go1.26)), never with `==`, a type assertion or the message's text. *Wrapping breaks `==` and assertions, and the text isn't API.* errorlint and modernize's `errorsastype` catch the old forms.
6. **`%w` inside the module; translate at a boundary you own.** A store, a gateway client or a parser turns `sql.ErrNoRows`, `fs.ErrNotExist` or a 404 into your `ErrNotFound`. *Otherwise every caller imports the dependency to check, and replacing it breaks them all.*
7. **Exported functions return `error`, and a literal `nil` on success,** never a `*XError`. *A nil `*XError` returned as `error` isn't nil, so `err != nil` holds for a call that succeeded.*
8. **A question about an error is a behaviour interface,** declared by the code that asks: `interface{ error; Retryable() bool }`, asked with `errors.AsType`. *The asker gets its answer from any error that can give it, without knowing the types.*
9. **Errors become a status or an exit code in one function per edge.** Domain errors carry no HTTP status: the HTTP edge's `statusFor` reads them, and so does the command's exit-code function. *The domain stays free of transports, and each edge's mapping is one table to read.*
10. **`errors.Join` reports independent failures together.** An error is dropped only as `_ =`, and only where losing it is safe, as a read-only `Close`'s is; a comment says why where that isn't plain. *Validation reports every bad field at once, and each still matches; an explicit drop reads as a decision, not a slip.* errcheck reports one dropped without `_ =`.
11. **Take a `*slog.Logger` as a field or `Config` field; never reach for a global one.** `main` builds it once, and tests pass `slog.New(slog.DiscardHandler)` ([Go 1.24](https://go.dev/doc/go1.24)). No package-level logger, and no `slog.Info` or `slog.Default()` below `main`. *A test can see a component's lines, or silence them, without touching anything shared.* sloglint's `no-global: "all"` reports a package-level logger and the `slog.Info`-style calls.
12. **A log line is a constant message with attributes.** Values go in key-value pairs, the error under `err`. `Error` is for what a person must act on; an expected outcome, such as a declined card or a 404, is `Info`. *Messages stay searchable, attributes stay queryable, and an `Error` stays worth reading.* sloglint's `static-msg` catches a formatted message.

## Pattern

```go
// ErrNotFound is returned, wrapped, when no order has the id.
var ErrNotFound = errors.New("not found")

// NotCancellableError reports an order whose status forbids cancelling.
type NotCancellableError struct {
	ID     ID
	Status Status
}

func (e *NotCancellableError) Error() string {
	return fmt.Sprintf("order %s is %s", e.ID, e.Status)
}

// Cancel cancels the order for reason. Its error matches ErrNotFound, or is a
// *NotCancellableError.
func (c Canceller) Cancel(ctx context.Context, id ID, reason Reason) (Order, error) {
	o, err := c.Orders.Order(ctx, id) // the store's error names the id
	if err != nil {
		return Order{}, fmt.Errorf("cancel: %w", err)
	}
	if !o.Status.Cancellable() {
		return Order{}, &NotCancellableError{ID: id, Status: o.Status}
	}
	o = o.Cancelled(reason, c.Now())
	if err := c.Orders.Save(ctx, o); err != nil {
		return Order{}, fmt.Errorf("cancel: %w", err)
	}
	return o, nil
}
```

`Cancel` is trimmed here to its errors; [go-actions](../go-actions/SKILL.md) has it whole, with its refund and its transaction. The HTTP edge maps them in one place; [matching.md](references/matching.md) has `writeError`, which logs what maps to 500.

```go
func statusFor(err error) int {
	if errors.Is(err, order.ErrNotFound) {
		return http.StatusNotFound
	}
	if _, ok := errors.AsType[*order.NotCancellableError](err); ok {
		return http.StatusConflict
	}
	return http.StatusInternalServerError
}
```

## Decision table

| The caller | Return |
|---|---|
| only reports it | `fmt.Errorf("op %s: %w", in, err)`, or `errors.New` for a new failure |
| branches on which failure it was | an exported `var ErrX = errors.New("…")`, returned wrapped |
| needs data from it | a `type XError struct` with a pointer receiver, matched as `*XError` |
| asks one question of many error kinds | a behaviour interface it declares: `interface{ error; Retryable() bool }` |
| gets several independent failures | `errors.Join(errs...)` |
| sits past a dependency you wrap | your own sentinel, translated from the dependency's |

## Anti-patterns

- ❌ `logger.Error("charge failed", "err", err); return err` → return it wrapped; whoever stops it logs it.
- ❌ `fmt.Errorf("failed to load config: %v", err)` → `fmt.Errorf("load config: %w", err)`, as `os` already names the path.
- ❌ `strings.Contains(err.Error(), "not found")` or `err == ErrNotFound` → `errors.Is(err, ErrNotFound)`.
- ❌ `var e *XError; errors.As(err, &e)` → `e, ok := errors.AsType[*XError](err)`.
- ❌ `return XError{…}` matched as `*XError`, or the reverse → build and match one form; Go 1.27's vet flags `%w` given a `*E` whose `E` implements `error` ([Go 1.27](https://go.dev/doc/go1.27)).
- ❌ `func Validate(o Order) *ValidationError` → return `error`.
- ❌ `sql.ErrNoRows` reaching a handler → the store translates it to `ErrNotFound`.
- ❌ `func (e *DeclinedError) HTTPStatus() int` → `statusFor` at the edge.
- ❌ `var log = slog.With("pkg", "billing")`, or `slog.Info(…)` in a package → a `*slog.Logger` field.
- ❌ `logger.Info(fmt.Sprintf("refunded %s", id))` → `logger.Info("refunded", "order", id)`.

## References

- **[wrapping.md](references/wrapping.md)** — read when writing an error message, or deciding whether to wrap, translate or return bare: what each standard error already says, joined errors, and a `Close` that loses data.
- **[matching.md](references/matching.md)** — read when a caller branches on an error: sentinels, typed errors, the typed-nil trap, behaviour interfaces, the edge mapper, and checking errors in tests.
- **[logging.md](references/logging.md)** — read when adding a logger or a log line: injection and wiring, where to log, levels, attributes, redaction, and testing what was logged.

## Related skills

- **[go-actions](../go-actions/SKILL.md)** — the edges that map errors, and the exit-code function.
- **[go-interfaces](../go-interfaces/SKILL.md)** — the adapters and stores that translate a dependency's errors.
- **[go-types](../go-types/SKILL.md)** — secret types that redact themselves in a log line.
- **[go-testing](../go-testing/SKILL.md)** — checking errors and log lines in tests.
- **[go-quality](../go-quality/SKILL.md)** — the linters behind each rule.
