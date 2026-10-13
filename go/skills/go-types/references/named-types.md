# Named Types and Structs

**A type says what a value means, so the compiler keeps meanings apart and a call site reads without its declaration.**

Contents: ids and measures · input structs · config structs · bool parameters · values with rules · aliasing · secrets · generics · checklist.

## Ids and measures

```go
// package order

type (
	// ID names an order.
	ID string
	// CustomerID names a customer.
	CustomerID string
	// Cents is an amount in a currency's minor unit.
	Cents int64
	// Date is a calendar day, as 2026-03-01.
	Date string
)
```

- **One conversion, at the edge.** A raw string becomes an `order.ID` where it arrives: a path value, a flag, a row, a payload's mapper. Inside, it stays an `order.ID`, and `string(id)` appears only where it's written out again.
- **A named type costs nothing at run time** and takes methods when one earns its place: `order.ParseID` checking the shape, `Date.Time()`.
- **Named for the package it's in:** `order.ID`, not `order.OrderID`. revive's `exported` rule reports the stutter. `CustomerID` keeps its prefix: in package `order`, a bare `ID` is the order's.
- **New code and touched signatures, not a sweep.** When a change touches `func Find(ctx context.Context, id string)`, it becomes `id order.ID`, and its callers in the change follow. Untouched neighbours wait for a change of their own.
- **A loop index, a byte count or a local scratch string** stays a plain type. The rule is for values that cross a function boundary carrying a meaning another value of the same type could be mistaken for.

**Units.** An instant is a `time.Time` and a span a `time.Duration`, in the domain and in every signature. Where a format forces an int, the field names its unit (`TimeoutSeconds` in the wire struct), and the mapper converts it. A `Cents` or `Bytes` type names its unit by being one.

**Dates without a time.** `type Date string` in ISO form (`2026-03-01`) compares and sorts as a string. When arithmetic is needed, `time.Parse(time.DateOnly, string(d))` gives a `time.Time` at midnight UTC.

## Input structs

More than two related values, or two of one type side by side, travel as one struct:

```go
// TransferInput moves an order from one customer to another.
type TransferInput struct {
	Order ID
	From  CustomerID
	To    CustomerID
	Note  string
}
```

```go
err := transferrer.Transfer(ctx, order.TransferInput{Order: id, From: from, To: to})
```

- **Keyed fields at every call,** so `From` and `To` can't swap. vet's `composites` check already demands keys for another package's struct.
- **`ctx` stays a parameter,** first, never a field.
- **An optional field's zero means "not given"** when zero isn't a real answer. When it is (a discount of 0%), the field is a pointer, built with `new(expr)` ([Go 1.26](https://go.dev/doc/go1.26)):

```go
// DiscountInput takes a discount off an order: a percentage or an amount.
type DiscountInput struct {
	Order   ID
	Percent *int // nil: not given; 0 is a real answer
	Amount  *Cents
}

in := DiscountInput{Order: "ord-1", Amount: new(Cents(500))}
```

`new(500)` would be an `*int`; the conversion inside makes it a `*Cents`. No `ptr(v)` helper is needed any more.

## Config structs

A constructor with settings takes a `Config` whose zero fields mean defaults, each noted beside its field. Here it builds [go-concurrency](../../go-concurrency/SKILL.md)'s `Poller`:

```go
// Config configures a Poller. A zero field takes the default noted beside it.
type Config struct {
	Payments Settler
	Interval time.Duration // zero: one minute
	Timeout  time.Duration // zero: ten seconds
	Log      *slog.Logger  // nil: discard
}

// NewPoller builds a Poller from cfg, with its defaults filled in.
func NewPoller(cfg Config) *Poller {
	return &Poller{
		Payments: cfg.Payments,
		Interval: cmp.Or(cfg.Interval, time.Minute),
		Timeout:  cmp.Or(cfg.Timeout, 10*time.Second),
		Log:      cmp.Or(cfg.Log, slog.New(slog.DiscardHandler)),
	}
}
```

- **`cmp.Or`** returns its first non-zero argument, so each default is one line.
- **When zero is a real setting** (no retries), it can't also mean "default": invert the field (`NoRetry bool`), or make it a pointer.
- **Functional options** (`NewPoller(WithInterval(d))`) are for a library many modules import, where most callers set nothing and new settings keep arriving. In an application, a `Config` is less code and its zero value is visible.
- **A `Config` is read once,** in the constructor. The `Poller` it builds is a copy, so changing the `Config` afterwards changes nothing.

## Bool parameters

```go
// Delivery is how an order travels.
type Delivery int

// The ways an order travels; the zero value is the standard one.
const (
	DeliveryStandard Delivery = iota
	DeliveryExpress
)

// Ship sends o the way d says.
func Ship(ctx context.Context, o Order, d Delivery) error
```

`Ship(ctx, o, DeliveryExpress)` reads at the call; `Ship(ctx, o, true)` doesn't. Zero is the real default here, so it needs no unknown. When the input is already a struct, a named field does the same job: `ShipInput{Order: o, Express: true}`.

## Values with rules

A value that has rules (a well-formed email, an amount in one currency) is a struct with unexported fields, so the only way to get one is through the function that checks it:

```go
// Email is an address that has passed a syntax check.
type Email struct {
	addr string
}

// ParseEmail reads an address, refusing one that isn't well formed.
func ParseEmail(s string) (Email, error) {
	a, err := mail.ParseAddress(s)
	if err != nil {
		return Email{}, fmt.Errorf("parse email %q: %w", s, err)
	}
	return Email{addr: a.Address}, nil
}

func (e Email) String() string { return e.addr }

// IsZero reports whether e was never set.
func (e Email) IsZero() bool { return e.addr == "" }
```

- **`NewX`** builds from parts that are already typed; **`ParseX`** reads from text. Both validate.
- **Value receivers, returning new values:** `total, err := a.Add(b)`. Nothing changes under a holder, and a copy is safe to share. [money.go](money.go) is the worked example.
- **The zero value still exists.** `Email{}` can be declared anywhere, so give the type an `IsZero` and treat zero as "not set". `omitzero` uses the same method.
- **Keep it comparable:** no slices or maps among its fields, and `==` and map keys work.
- **A text form round-trips.** `MarshalText` and `UnmarshalText` (calling `ParseX`) make it decode and validate in one step, in JSON, config files and flags.
- **Not for a value with no rules.** A struct around one unconstrained string is ceremony: a named type does the job.

## Aliasing

Slices and maps share their backing store when copied, value receivers included. Copy them as they cross an API, both ways:

```go
// Cart holds the lines of an order being built.
type Cart struct {
	lines []Line
}

// NewCart is a cart holding a copy of lines.
func NewCart(lines []Line) *Cart { return &Cart{lines: slices.Clone(lines)} }

// Lines are a copy of the cart's lines.
func (c *Cart) Lines() []Line { return slices.Clone(c.lines) }
```

- **`append` can write into the caller's array.** `b := append(a[:2], x)` overwrites `a[2]` when `a` has room. Clone first, or cut capacity with `a[:2:2]`.
- **A package table is a function,** `func Statuses() []Status { return []Status{…} }`, never `var Statuses = []Status{…}`, which any importer can change for everyone.
- **`slices.Clone(nil)` is nil,** and `maps.Clone(nil)` is nil, so a clone keeps the difference between none and empty.
- **Hand back an iterator** (`iter.Seq[Line]`) when callers only range over the values and copying them would cost.

## Secrets

```go
// Token is a credential. It prints and logs as [redacted]; string(t) is the one way to
// its value.
type Token string

func (Token) String() string { return "[redacted]" }

// GoString redacts the token from %#v.
func (Token) GoString() string { return "[redacted]" }

// LogValue redacts the token from a log line.
func (Token) LogValue() slog.Value { return slog.StringValue("[redacted]") }
```

- **`%v`, `%s`, `%q`, `%x` and `%#v`,** and `slog` given the token itself, all print `[redacted]`.
- **A struct holding one can still leak it.** fmt can't call `String` on an unexported field, so `%v` of the struct prints the raw value, and slog's JSON handler marshals an exported one raw. Log the fields you mean, never a struct that carries a secret.
- **Its JSON is the raw value,** which a config file holding the token needs. That's why the redaction stops at printing and logging.
- **It never goes into an error message,** which is printed and logged further than the code that made it can see.

## Generics

A type parameter earns its place when the same code would otherwise be written for several types: a `Set[T comparable]`, a cache. A domain type is never generic for one instantiation, and an interface stays an interface when the code only calls its methods.

## Checklist

- [ ] Each id and measure crossing a function is a named type; spans are `time.Duration`.
- [ ] Three or more related parameters, or two of one type, are a struct with keyed fields.
- [ ] No bare `bool` parameter.
- [ ] A value with rules has unexported fields and a validating `NewX` or `ParseX`.
- [ ] Slices and maps are cloned in and out; package tables are functions.
- [ ] A secret has its own redacting type, and no struct holding it is logged whole.
