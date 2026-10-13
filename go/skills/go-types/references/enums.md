# Enums

**A fixed set is a defined type and its constants. What the set means lives in methods on that type, so every caller asks it the same way.**

Contents: string or iota · the zero value · the string shape · the int shape · behaviour · transitions · decoding · checklist.

## String or iota

| The set is… | Base type | Why |
|---|---|---|
| stored, sent, logged or in a config file | `string` | the bytes are the names, so old data reads after the constants are reordered or added to |
| only ever in memory | `int` with `iota` | cheap to compare, and nothing outside depends on its numbers |

When in doubt, use a string. An `iota` int written to disk is renumbered by the next constant someone inserts above it.

## The zero value

Zero is whatever an unset field holds, so choose what it means:

- **The real default,** when there is one and an unset field should take it: `DeliveryStandard Delivery = iota`.
- **An explicit unknown** otherwise, so an unset field reads as invalid instead of as the first member: `PriorityUnknown Priority = iota`.
- **For a string enum, the empty string is the unknown.** No constant names it, and `ParseX` refuses it.

Never let zero be an arbitrary member, such as `StatusPending = iota`: every order nobody set reads as pending.

## The string shape

```go
// Status is where an order is in its life. It's stored, so each value is its stored form.
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

// Statuses are the members, in the order an order moves through them.
func Statuses() []Status {
	return []Status{StatusPending, StatusPaid, StatusShipped, StatusCancelled}
}
```

It needs no `String`: fmt prints the string already. `ParseStatus` matches exactly, so `"PAID"` and `"paid "` are refused, and a caller tells a bad status from any other failure with `errors.Is(err, ErrUnknownStatus)`.

## The int shape

```go
// Priority is how soon an order is picked. It's worked out on each run and never stored.
type Priority int

// The priorities, from no priority worked out yet to the highest.
const (
	PriorityUnknown Priority = iota
	PriorityLow
	PriorityNormal
	PriorityHigh
)

func (p Priority) String() string {
	switch p {
	case PriorityLow:
		return "low"
	case PriorityNormal:
		return "normal"
	case PriorityHigh:
		return "high"
	case PriorityUnknown:
		return "unknown"
	}
	return fmt.Sprintf("Priority(%d)", int(p))
}

// ParsePriority reads a priority by the name String gives it.
func ParsePriority(s string) (Priority, error) {
	for _, p := range Priorities() {
		if p.String() == s {
			return p, nil
		}
	}
	return PriorityUnknown, fmt.Errorf("unknown priority %q", s)
}

// Priorities are the members, lowest first.
func Priorities() []Priority {
	return []Priority{PriorityLow, PriorityNormal, PriorityHigh}
}
```

- **`String` lists every member,** and falls back to `Priority(7)` for a value outside the set, as `stringer`'s output does. `stringer` could generate it; the switch is as short, and exhaustive checks it.
- **`ParsePriority` round-trips every member:** it reads the name `String` writes for each, so a flag or a log line reads back. It refuses `"unknown"`, which no one sets on purpose.
- **The members come from a function,** never an exported slice, which any importer could change.

## Behaviour

What a member means is a method with a `switch` that names every member:

```go
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

- **Every member is named,** even the ones that answer like the default. When a member is added, exhaustive flags each switch that hasn't decided about it, instead of the new member quietly taking the default.
- **The line after the switch** answers for the unknown and for values outside the set.
- **A label for people** (`Label()`) is a method too, so a status is worded in one place.
- **Callers ask the method,** never `o.Status == StatusPending || o.Status == StatusPaid` inline: that copy of the rule drifts the day a member is added.

## Transitions

When a status moves by rules, the rules are a method on the type:

```go
// CanMoveTo reports whether an order may go from s to next.
func (s Status) CanMoveTo(next Status) bool {
	switch s {
	case StatusPending:
		return next == StatusPaid || next == StatusCancelled
	case StatusPaid:
		return next == StatusShipped || next == StatusCancelled
	case StatusShipped, StatusCancelled:
		return false
	}
	return false
}
```

- **The operation that moves the order checks it as a guard** ([go-actions](../../go-actions/SKILL.md)), so no caller sets `o.Status` directly.
- **Each transition with side effects is its own operation** (`Pay`, `Ship`, `Cancel`). There's no state-machine framework: the method says what's allowed, and the operations say what happens.
- **Many transitions** read better as a table, an unexported `map[Status][]Status` that's never written after it's declared, behind the same `CanMoveTo`.

## Decoding

Unmarshalling into a string enum accepts any string: `{"status": "lost"}` gives `Status("lost")` with no error. Validate on the way in, one of two ways:

- **In the mapper:** the wire struct holds a plain `string`, and the mapper calls `ParseStatus` ([boundaries.md](boundaries.md)).
- **On the type:** an `UnmarshalText` that calls `ParseStatus`, so JSON, config files and flags all refuse an unknown value:

```go
// UnmarshalText reads a status from JSON, a flag or a config file, refusing one it
// doesn't know.
func (s *Status) UnmarshalText(b []byte) error {
	st, err := ParseStatus(string(b))
	if err != nil {
		return err
	}
	*s = st
	return nil
}
```

A missing key never reaches `UnmarshalText`, so the field stays `""`. Whatever needs the status still checks for the unknown.

An `iota` enum that must be written out gets `MarshalText` and `UnmarshalText` writing its name, never its number. Usually that's the sign it should have been a string.

## Checklist

- [ ] String for anything stored or sent; `iota` only for memory.
- [ ] Zero is the real default or an explicit unknown.
- [ ] Constants prefixed with the type's name.
- [ ] `ParseX`, a function listing the members, and `String` for an int.
- [ ] Behaviour as methods whose `switch` names every member.
- [ ] Values from outside pass through `ParseX`, in the mapper or `UnmarshalText`.
