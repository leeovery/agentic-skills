# Operations

**An operation does one job: typed input in, a result or an error out, holding nothing but its collaborators.**

Contents: function or struct · per-call state · guards · composition · writes · edges · checklist.

## Function or struct

A function when the job needs only its input:

```go
// Total is what the lines cost together.
func Total(lines []Line) Cents {
	var sum Cents
	for _, l := range lines {
		sum += l.Price * Cents(l.Quantity)
	}
	return sum
}
```

A struct when it needs a store, a client, a clock or another operation. Its fields are those collaborators and nothing else. It has one exported method, named for the verb, with `ctx` first. Give it a value receiver, as a call changes nothing on it.

- `run` builds it with a keyed literal: `order.Canceller{Orders: store, Payments: payments, Now: time.Now}`. Tests build it the same way, with fakes in the fields.
- Add a constructor (`NewImporter(cfg Config)`) only when a field needs a default or a check. [go-types](../../go-types/SKILL.md) covers `Config`.
- Two jobs are two structs, even when they hold the same store. A struct with `Create`, `Cancel` and `Refund` is a service under another name: it gathers every job's collaborators, and grows with each job added.

## Per-call state

The struct is built once and shared by every caller, so a call never writes to it:

```go
// ❌ Two imports at once share seen and report.
type Importer struct {
	Orders Store
	seen   map[ID]bool
	report Report
}
```

Pass what a helper needs. When a call carries enough state that passing it gets noisy, build a private value per call:

```go
// Importer loads orders from another system's export.
type Importer struct {
	Orders Store
}

// Import saves every row's order once, in one transaction, and reports what it did.
func (i Importer) Import(ctx context.Context, rows []Row) (Report, error) {
	run := importRun{seen: make(map[ID]bool)}
	err := i.Orders.InTx(ctx, func(tx Store) error {
		for _, row := range rows {
			if err := run.add(ctx, tx, row); err != nil {
				return fmt.Errorf("row %d: %w", row.Line, err)
			}
		}
		return nil
	})
	if err != nil {
		return Report{}, fmt.Errorf("import: %w", err)
	}
	return run.report, nil
}

// importRun is one Import's working state.
type importRun struct {
	seen   map[ID]bool
	report Report
}

func (r *importRun) add(ctx context.Context, tx Store, row Row) error {
	if r.seen[row.Order.ID] {
		r.report.Duplicates++
		return nil
	}
	r.seen[row.Order.ID] = true
	if err := tx.Save(ctx, row.Order); err != nil {
		return err
	}
	r.report.Imported++
	return nil
}
```

## Guards

Every precondition is checked at the top, before any write, each as a named check:

```go
if err := ensureCancellable(o); err != nil {
	return Order{}, err
}
if err := ensureOwnedBy(o, in.Customer); err != nil {
	return Order{}, err
}
```

- **A predicate method** when the question is about one value alone: `Status.Cancellable()`, `Order.Paid()`. It lives on the type, so every caller that asks gets the same answer. [enums.md](../../go-types/references/enums.md) covers an enum's.
- **`ensureX(…) error`** when the check builds an error, or needs more than one value. It returns a sentinel or a typed error that names what it checked ([go-errors](../../go-errors/SKILL.md)).
- **No comment over a guard.** If the check needs one, its name is wrong.
- **No `else` after a guard returns.** The happy path stays at the left margin, and the last line returns the result.

## Composition

An operation that needs another holds it as a field. It never repeats the other's steps:

```go
// Expirer cancels orders left unpaid for two days.
type Expirer struct {
	Orders    unpaidOrders
	Canceller canceller
	Now       func() time.Time
}

type unpaidOrders interface {
	UnpaidBefore(ctx context.Context, t time.Time) ([]Order, error)
}

type canceller interface {
	Cancel(ctx context.Context, id ID, reason Reason) (Order, error)
}

const unpaidFor = 48 * time.Hour

// Expire cancels every order left unpaid too long.
func (e Expirer) Expire(ctx context.Context) error {
	due, err := e.Orders.UnpaidBefore(ctx, e.Now().Add(-unpaidFor))
	if err != nil {
		return fmt.Errorf("expire: %w", err)
	}
	var errs []error
	for _, o := range due {
		if _, err := e.Canceller.Cancel(ctx, o.ID, ReasonUnpaid); err != nil {
			errs = append(errs, err)
		}
	}
	return errors.Join(errs...)
}
```

The trap this avoids: an `Expire` that saves each order as cancelled itself, and forgets the refund and the stock, because those steps live in `Cancel`.

- **Hold the concrete operation** (`Canceller order.Canceller`) when its own fakes are cheap to set up and the outer test may as well run it.
- **Hold a one-method interface** when the outer test should see only what was asked: "cancel `ord-1`, reason unpaid". The interface is unexported and sits beside the operation that holds it ([go-interfaces](../../go-interfaces/SKILL.md)). `unpaidOrders` is the same move for the store: the one query `Expire` makes.
- `run` wires the chain: `order.Expirer{Orders: store, Canceller: canceller, Now: time.Now}`.

## Writes

**Writes that must land together go through one store call that takes a closure.** The store begins a transaction, runs the closure against a store bound to it, and commits if the closure returns nil, else rolls back:

```go
// Store is the orders' store, as the operations that write use it.
type Store interface {
	// …
	InTx(ctx context.Context, fn func(tx Store) error) error
}
```

- Every write in the closure goes through `tx`, never the outer store, which would write outside the transaction.
- **An outside call can't roll back.** A refund or an email sent inside the closure has happened even when the commit fails. Make it before the writes, keyed so a retry is safe (an idempotency key from the payment, as `Cancel`'s refund is), or record it in the transaction and send it after the commit.
- An in-memory store's `InTx` can apply the closure's writes to a copy and swap it in on success. Its fakes in tests need nothing more than `return fn(f)`.

**A file is replaced whole.** Write a temporary file in the same directory, sync it, close it, then rename it over the target: a rename within one filesystem is atomic, so a reader, or the next run after a crash, sees the old file or the new one. This is a job for one small package the whole module uses:

```go
// Package atomicfile replaces files whole.
package atomicfile

import (
	"errors"
	"fmt"
	"os"
	"path/filepath"
)

// Write replaces path with data. A reader sees the old file or the new one, never part of
// either.
func Write(path string, data []byte, perm os.FileMode) error {
	f, err := os.CreateTemp(filepath.Dir(path), "."+filepath.Base(path)+".*")
	if err != nil {
		return fmt.Errorf("replace file: %w", err)
	}
	if err := fill(f, data, perm); err != nil {
		return fmt.Errorf("replace file: %w", errors.Join(err, os.Remove(f.Name())))
	}
	if err := os.Rename(f.Name(), path); err != nil {
		return fmt.Errorf("replace file: %w", errors.Join(err, os.Remove(f.Name())))
	}
	return nil
}

func fill(f *os.File, data []byte, perm os.FileMode) error {
	_, err := f.Write(data)
	return errors.Join(err, f.Chmod(perm), f.Sync(), f.Close())
}
```

- **A written file's `Close` is joined into the returned error,** as `fill` does: a failed close can mean the data never landed.
- **A file or body only read closes with `defer func() { _ = f.Close() }()`,** with no `//nolint`: its close error says nothing about the data. A bare `defer f.Close()` is reported by errcheck.
- `os.WriteFile` truncates the target first, so a crash mid-write leaves it empty or cut short.

## Edges

An edge parses, calls one operation, and writes the result or maps the error. That's all it does.

### HTTP

```go
func cancelOrder(c order.Canceller, log *slog.Logger) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		id, err := order.ParseID(r.PathValue("id"))
		if err != nil {
			http.Error(w, err.Error(), http.StatusBadRequest)
			return
		}
		reason, err := order.ParseReason(r.FormValue("reason"))
		if err != nil {
			http.Error(w, err.Error(), http.StatusBadRequest)
			return
		}
		o, err := c.Cancel(r.Context(), id, reason)
		if err != nil {
			writeError(w, r, log, err)
			return
		}
		writeJSON(w, r, log, http.StatusOK, orderToJSON(o))
	}
}
```

- `writeError` is the edge's one mapping from error to status, and the one place a server error is logged ([go-errors](../../go-errors/SKILL.md)).
- `orderToJSON` is the wire mapper ([go-types](../../go-types/references/boundaries.md)). The domain `Order` carries no `json` tags for this response.
- The context is `r.Context()`, so a client that hangs up cancels the work.
- Route with the standard `ServeMux` patterns: `mux.Handle("POST /orders/{id}/cancel", cancelOrder(canceller, log))`.

### Queue consumers and scheduled jobs

The same shape: decode the message into its wire struct, map it to typed values, call one operation, and return its error for the queue to retry or dead-letter. A scheduled job's tick calls an operation (`expirer.Expire(ctx)`) and logs a failure; the loop around it, and its shutdown, are [go-concurrency](../../go-concurrency/SKILL.md)'s.

### What an edge never does

- Loops over a store, checks a status or sums money.
- Passes `*http.Request`, `*cobra.Command` or a queue message into the domain.
- Calls two operations in a row to make a third. That sequence is an operation of its own, holding the two.
- Formats a value its own way. It calls the value's one formatter ([go-architecture](../../go-architecture/SKILL.md)).

## Checklist

- [ ] One job, named by its verb, and the package-qualified name reads well (`order.Canceller.Cancel`).
- [ ] Fields hold collaborators only; a call's state is parameters or a per-call value.
- [ ] `ctx` first; the input is typed.
- [ ] Errors wrapped with the operation alone (`cancel: %w`), as the store's errors already name the record ([go-errors](../../go-errors/SKILL.md)).
- [ ] Guards first, each a named check, with no `else` after them.
- [ ] Another operation's steps are reached by holding it, never repeated.
- [ ] Writes that land together go through one `InTx`; no outside call sits inside it.
- [ ] Files are replaced whole.
- [ ] Each edge parses, calls one operation, and writes or maps the error.
- [ ] Tested by filling the fields with fakes.
