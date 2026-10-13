---
name: go-types
description: Go named types, structs and typed enums in place of loose strings, ints and bools, mapped explicitly at the boundary. Use when defining ids, money, dates or status values, adding a struct field, writing an input or config struct, or encoding JSON or other wire and stored formats.
---

# Go Types

A value's type says what it is: an id, an amount, a status. Wire shapes stop at the edge, where one function maps each into the domain.

## Core rules

1. **Ids and measures are named types; instants and spans are `time.Time` and `time.Duration`.** `order.ID`, `type Cents int64`, `type Date string`, never a bare `string` or `int` that a value with another meaning could fill. An int that must carry a unit names it (`timeoutMillis`). *Swapped `customerID, orderID string` arguments compile; swapped `order.CustomerID, order.ID` don't.* This is for new code and the signatures a change touches, not a sweep.
2. **Related values travel in a struct.** More than two related parameters, or two of one type side by side, become an input struct. A constructor takes a `Config` whose zero fields mean documented defaults; functional options are only for a widely imported library. *Arguments can't be swapped, and the next one has a place to go.* revive's `argument-limit` (5) catches the worst.
3. **No bare `bool` parameters.** Use a named type, a constant or a field. *`Ship(o, true)` says nothing at the call.* revive's `flag-parameter` catches a bool that steers an `if`.
4. **A value with rules is a struct with unexported fields,** made by a validating `NewX` or `ParseX`, with value receivers that return new values. *An invalid value can't be built, and none changes under its holder.*
5. **Slices and maps are copied as they cross an API** (`slices.Clone`, `maps.Clone`), in and out. A package table is a function that returns a fresh copy, never an exported variable. *Otherwise a caller's `append` or write changes your state.*
6. **An enum is a defined type with constants prefixed by its name** (`StatusPaid`). Its zero value is the real default or an explicit unknown, never an arbitrary member. It has a `ParseX`, a `String` when it's an int, and its behaviour as methods with a `switch`. *An unset field reads as invalid, and two sets in one package can't collide.* exhaustive checks every switch lists every member.
7. **What's stored or sent is a typed string** (`type EventKind string`), never an untyped constant or an `iota` int. *A typo won't compile, exhaustive can check it, the bytes are the names, and reordering the constants can't renumber stored data.*
8. **Wire shapes are tagged structs at the edge, mapped by one named function per source** that does nothing else. *The domain type never bends to a payload, and a format change touches one function.* musttag checks every marshalled struct is tagged.
9. **A stored or wire format has its own encoder, and a test that pins its bytes.** It never goes through a display formatter. *Otherwise a tweak to how something reads on screen rewrites stored data.*
10. **A module with no JSON yet uses `encoding/json/v2`**, stable from [Go 1.27](https://go.dev/doc/go1.27) and what the standard library now recommends. A module already on v1 stays on v1 until one deliberate switch of the whole module, never piecemeal. Struct and `time.Time` fields take `omitzero` ([Go 1.24](https://go.dev/doc/go1.24)), as v1's `omitempty` never omits them. *v2's defaults change behaviour: duplicate names and invalid UTF-8 are errors, names match by case, and a `time.Duration` has no default form, so a module half on each reads one payload two ways.* modernize's `omitzero` check flags the old tag.
11. **A secret is a type that redacts itself** in `String`, `GoString` and `LogValue`. *Printed or logged on its own, it can't leak.*

## Pattern

```go
// package order

type (
	// ID names an order.
	ID string
	// CustomerID names a customer.
	CustomerID string
	// Cents is an amount in a currency's minor unit.
	Cents int64
)

// Order is a customer's order.
type Order struct {
	ID       ID
	Customer CustomerID
	Total    Cents
	Status   Status
	PlacedAt time.Time
}

// Status is where an order is in its life. It's stored, so each value is its stored
// form, and the empty string is unknown: no constant names it, and ParseStatus refuses it.
type Status string

// The statuses an order moves through.
const (
	StatusPending   Status = "pending"
	StatusPaid      Status = "paid"
	StatusShipped   Status = "shipped"
	StatusCancelled Status = "cancelled"
)

// ErrUnknownStatus is returned, wrapped, for a string that names no status.
var ErrUnknownStatus = errors.New("unknown order status")

// ParseStatus reads a status from its stored form, and refuses any other string.
func ParseStatus(s string) (Status, error) {
	switch st := Status(s); st {
	case StatusPending, StatusPaid, StatusShipped, StatusCancelled:
		return st, nil
	}
	return "", fmt.Errorf("%q: %w", s, ErrUnknownStatus)
}

// Cancellable reports whether an order in this status can still be cancelled.
func (s Status) Cancellable() bool {
	switch s {
	case StatusPending, StatusPaid:
		return true
	case StatusShipped, StatusCancelled:
		return false
	}
	return false
}
```

## Decision table

| Value | Type |
|---|---|
| An identity | `type ID string`, read as `order.ID` |
| Money | `type Cents int64`, or [money.go](references/money.go) when currencies mix |
| An instant, a span, a calendar day | `time.Time`, `time.Duration`, `type Date string` |
| A fixed set that's stored or sent | `type Status string` with constants |
| A fixed set kept in memory | `type Priority int` with `iota`, zero unknown |
| A value with rules | a struct with unexported fields, `NewX` or `ParseX` |
| Three or more parameters, or two of one type | an input struct |
| A payload | a tagged wire struct, and one mapper |

## Anti-patterns

- ❌ `Transfer(ctx, orderID, fromID, toID string)` → `Transfer(ctx, TransferInput{Order: id, From: from, To: to})`.
- ❌ `Ship(o, true)` → `Ship(o, DeliveryExpress)`.
- ❌ `TimeoutSeconds int` in a domain struct → `Timeout time.Duration`, converted at the edge.
- ❌ `const StatusPaid = "paid"`, or `StatusPending Status = iota` as the zero → `StatusPaid Status = "paid"`; an int enum starts at its unknown.
- ❌ Trusting a `Status` that came off the wire → `ParseStatus` in the mapper, or an `UnmarshalText` that calls it.
- ❌ `var Statuses = []Status{…}`, or `return c.lines` → `func Statuses() []Status`, `slices.Clone(c.lines)`.
- ❌ `json` tags on the domain `Order`, decoded straight from a request → a wire struct and a mapper.
- ❌ A stored line built with the display formatter → its own encoder, bytes pinned in a test.
- ❌ `omitempty` on a `time.Time` or a struct → `omitzero`.
- ❌ One package of a v1 module moved to `encoding/json/v2` → the whole module, in one change of its own.

## References

- **[named-types.md](references/named-types.md)** — read when adding an id, a measure, an input or config struct, a value with rules, or a secret.
- **[enums.md](references/enums.md)** — read when defining a fixed set, or a status with transitions.
- **[boundaries.md](references/boundaries.md)** — read when encoding or decoding JSON, a file format or a protocol.
- **[money.go](references/money.go)** — the drop-in money type; read when modelling money.

## Related skills

- **[go-actions](../go-actions/SKILL.md)** — the operations that take these types.
- **[go-errors](../go-errors/SKILL.md)** — error types, which live there.
- **[go-architecture](../go-architecture/SKILL.md)** — how a value is worded for a person to read.
- **[go-testing](../go-testing/SKILL.md)** — golden files, and fuzzing a parser.
