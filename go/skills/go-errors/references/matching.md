# Matching Errors

**A caller that branches on an error matches what it means, never how it's spelled.**

Contents: sentinels · typed errors · matching · the typed-nil trap · behaviour interfaces · several identities · the edge mapper · in tests.

## Sentinels

A condition callers act on, with nothing more to tell them: not found, already exists, too late.

```go
// ErrNotFound is returned, wrapped, when no order has the id.
var ErrNotFound = errors.New("not found")
```

- Exported, with a comment saying when it's returned. It's API from the moment a caller matches it. A sentinel that is one of the package's documented failures is exported for its callers and its external tests alike: a `package order_test` test is a caller too.
- Short text: the wrap adds the input. `fmt.Errorf("order %s: %w", id, ErrNotFound)` prints `order o-1: not found`.
- Returned wrapped, never bare, so every caller has to use `errors.Is`, and adding context later breaks nobody.
- A failure nobody branches on stays a plain `fmt.Errorf`. A sentinel per failure is a list nobody reads.

## Typed errors

When the caller needs data from the error, the error is a struct, and `Error()` builds its message from the fields:

```go
// DeclinedError is a charge the card's issuer refused.
type DeclinedError struct {
	Invoice InvoiceID
	Reason  string // the issuer's code, such as "insufficient_funds"
	Err     error  // the gateway's response, kept for the log
}

func (e *DeclinedError) Error() string {
	return fmt.Sprintf("invoice %s declined: %s", e.Invoice, e.Reason)
}

func (e *DeclinedError) Unwrap() error { return e.Err }
```

- **One form, pointer or value, everywhere.** A pointer receiver means it's built as `&DeclinedError{…}` and matched as `*DeclinedError`. Build a value and match a pointer, or the reverse, and `errors.AsType` never matches. Go 1.27's vet reports `fmt.Errorf("…: %w", &e)` when `E`'s `Error` has a value receiver ([Go 1.27](https://go.dev/doc/go1.27)). A struct with data takes a pointer; a one-field wrapper may be a value, built and matched as one.
- **`Unwrap` only for a cause worth exposing.** It lets `errors.Is` and `AsType` see through to it, which makes that cause API too.
- **A custom `Is` method is rare.** Write one only when two errors should match without being the same value.

## Matching

```go
if errors.Is(err, order.ErrNotFound) {
	// …
}
if d, ok := errors.AsType[*billing.DeclinedError](err); ok {
	// … d.Reason …
}
```

`errors.AsType` ([Go 1.26](https://go.dev/doc/go1.26)) replaces `var d *DeclinedError; errors.As(err, &d)`: no variable declared ahead, and the type is checked when it compiles. `go fix ./...` rewrites the old form (its `errorsastype` analyzer), and golangci-lint's modernize reports it.

Each of these breaks the first time someone adds a wrap:

| ❌ | Why it breaks |
|---|---|
| `err == ErrNotFound` | A wrapped sentinel isn't equal to it. |
| `err.(*DeclinedError)`, or a type switch on `err` | The outer error is the wrap, not the type. |
| `strings.Contains(err.Error(), "declined")` | The text changes with each wrap and each dependency's release: from Go 1.27, `encoding/json`'s messages may differ from before ([Go 1.27](https://go.dev/doc/go1.27)). |

## The typed-nil trap

An interface holding a typed nil pointer is not nil:

```go
// Wrong: a nil *ValidationError returned as error is a non-nil error.
func check(o Order) *ValidationError {
	if len(o.Lines) == 0 {
		return &ValidationError{Field: "lines"}
	}
	return nil
}

// PlaceWrong never returns nil, even for a valid order.
func PlaceWrong(o Order) error {
	return check(o) // never == nil, even for a valid order
}
```

The fix is in the signature: functions return `error`, and success is a literal `nil`. Nothing flags this reliably, so the rule is the guard.

```go
func validate(o Order) error {
	if len(o.Lines) == 0 {
		return &ValidationError{Field: "lines"}
	}
	return nil
}
```

## Behaviour interfaces

When the caller's question spans error kinds (is this worth retrying? did the caller do it wrong?), it declares an interface for the answer and asks with `errors.AsType`:

```go
// retryable is declared by the code asking the question; any error type can answer it.
type retryable interface {
	error
	Retryable() bool
}

func shouldRetry(err error) bool {
	r, ok := errors.AsType[retryable](err)
	return ok && r.Retryable()
}
```

Any error in the chain with that method answers, and its package never needs to know the interface exists:

```go
// StatusError is a response the gateway answered with something other than success.
type StatusError struct {
	Code int
}

func (e *StatusError) Error() string {
	return fmt.Sprintf("gateway answered %d %s", e.Code, http.StatusText(e.Code))
}

// Retryable reports whether the same request may succeed later.
func (e *StatusError) Retryable() bool {
	return e.Code == http.StatusTooManyRequests || e.Code >= http.StatusInternalServerError
}
```

When one type alone answers the question, match that type instead.

## Several identities

An error can answer several questions at once: which failure it was, what kind of failure, and what caused it. `Unwrap() []error` gives each as an identity of its own, matched with `errors.Is` or `errors.AsType` like any other:

```go
// ErrNotLoggedIn is returned, wrapped, when the gateway refuses the shop's credentials.
var ErrNotLoggedIn = errors.New("the gateway refused the shop's credentials")

// AuthError is a call the gateway refused for its credentials, with what the
// person does about it.
type AuthError struct {
    Fix string // the command that logs in again
    Err error  // the gateway's answer
}

func (e *AuthError) Error() string {
    return fmt.Sprintf("%v: %v", ErrNotLoggedIn, e.Err)
}

// Unwrap gives the error's identities: its class, its kind, and its cause.
func (e *AuthError) Unwrap() []error {
    return []error{ErrNotLoggedIn, failure.ErrBlocked, e.Err}
}
```

- `failure.ErrBlocked` is one of the kinds a small `failure` package names for every domain package to share (blocked by a rule, waiting on a person); the edge maps each kind to its status or exit code.
- `errors.Is(err, gateway.ErrNotLoggedIn)` holds for the caller that branches on the class, `errors.Is(err, failure.ErrBlocked)` for the exit-code function that maps the kind, and `errors.AsType[*url.Error](err)` still reaches the cause.
- It replaces a custom `Is` method: each identity is a plain error in the list, and the match walks into each.
- **Every element is API.** A caller comes to depend on each, so the list holds only what a caller branches on.
- A type has one `Unwrap` or the other. `errors.Unwrap` returns nil for an error with the list form, so walk it with `errors.Is` and `errors.AsType` alone. `fmt.Errorf` with several `%w` builds the same list without a type.

## The edge mapper

Each edge has one function from error to answer. A domain error may name its kind (refused by a rule, waiting on a person) and carry a fix, but no status code or exit number: the domain doesn't know it's behind HTTP, and the CLI maps the same errors to exit codes in its own function ([go-actions' cli.md](../../go-actions/references/cli.md)).

```go
// statusFor is the one place an error becomes a status.
func statusFor(err error) int {
	if errors.Is(err, order.ErrNotFound) {
		return http.StatusNotFound
	}
	if _, ok := errors.AsType[*order.NotCancellableError](err); ok {
		return http.StatusConflict
	}
	return http.StatusInternalServerError
}

// writeError is where a request's error stops: it's answered here, and logged
// here when the fault is the server's.
func writeError(w http.ResponseWriter, r *http.Request, log *slog.Logger, err error) {
	if errors.Is(err, context.Canceled) {
		return // the client went away: nobody to answer, nothing to fix
	}
	code := statusFor(err)
	if code >= http.StatusInternalServerError {
		log.ErrorContext(r.Context(), "request failed", "method", r.Method, "path", r.URL.Path, "err", err)
		http.Error(w, http.StatusText(code), code)
		return
	}
	http.Error(w, err.Error(), code)
}
```

Every handler ends the same way when its operation fails, `writeError(w, r, log, err)`, as [go-actions' operations.md](../../go-actions/references/operations.md) shows.

- A 4xx answers with the domain's message: it's about the caller's request, and it's not logged as a failure.
- A 5xx answers with the status text alone, and the whole chain goes to the log, once.
- A request the client abandoned is neither: logging every `context.Canceled` as an error buries the real ones.

## In tests

Match in a test the way callers match:

```go
e, ok := errors.AsType[*NotCancellableError](err)
if !ok {
	t.Fatalf("Cancel(o-1) = %v, want *NotCancellableError", err)
}
if e.Status != StatusShipped {
	t.Errorf("Cancel(o-1) status = %v, want %v", e.Status, StatusShipped)
}
```

Check a message only for a property it must have, such as naming the bad field, never its whole text, unless the message is the program's output: then its test pins it exactly, beside the match ([errors-as-output.md](errors-as-output.md)). [go-testing](../../go-testing/SKILL.md) covers the rest.
