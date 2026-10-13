# Boundaries

**The outside world's shapes stop at the edge. One function per source maps each into the domain, and one maps it back out.**

Contents: wire structs · the mapper · pinned formats · JSON · other formats · checklist.

## Wire structs

A payload has its own struct, beside the handler or client that speaks it:

```go
// orderJSON is an order as the API sends and receives it.
type orderJSON struct {
	ID         string    `json:"id"`
	Customer   string    `json:"customer_id"`
	TotalCents int64     `json:"total_cents"`
	Status     string    `json:"status"`
	PlacedAt   time.Time `json:"placed_at,omitzero"`
}
```

- **Unexported, in the edge's package.** The domain never imports it.
- **Every field tagged.** The tag is the contract, so renaming a Go field can't rename the wire. musttag checks every struct handed to an encoder is tagged.
- **Plain types, as they arrive.** Strings and numbers; the mapper turns them into ids, enums and money. A wire field may instead be a domain type that validates itself in `UnmarshalText` ([enums.md](enums.md)).
- **The domain struct carries no `json` tags.** Once it has them, every rename is a wire change, and the payload's quirks (`total_cents`, a nullable field) bend the domain's shape.

## The mapper

```go
// orderFromJSON maps an order from the API into the domain. It parses and does nothing
// else.
func orderFromJSON(w orderJSON) (order.Order, error) {
	if w.ID == "" || w.Customer == "" {
		return order.Order{}, fmt.Errorf("order %q: id and customer_id are required", w.ID)
	}
	status, err := order.ParseStatus(w.Status)
	if err != nil {
		return order.Order{}, fmt.Errorf("order %s: %w", w.ID, err)
	}
	return order.Order{
		ID:       order.ID(w.ID),
		Customer: order.CustomerID(w.Customer),
		Total:    order.Cents(w.TotalCents),
		Status:   status,
		PlacedAt: w.PlacedAt,
	}, nil
}

func orderToJSON(o order.Order) orderJSON {
	return orderJSON{
		ID:         string(o.ID),
		Customer:   string(o.Customer),
		TotalCents: int64(o.Total),
		Status:     string(o.Status),
		PlacedAt:   o.PlacedAt,
	}
}
```

- **One mapper per source:** `orderFromJSON`, `orderFromRow`, `orderFromEvent`. A mapper shared by two sources carries one source's quirks into the other.
- **It maps and nothing else:** no I/O, no logging, no defaults read from config, no business rule. A rule found while mapping belongs to an operation ([go-actions](../../go-actions/SKILL.md)).
- **Its errors name the record and the field,** so a bad payload is found without a debugger.
- **A round-trip test with every field set** catches a field added to `Order` that a mapper forgets. Keyed literals compile without it, and the field arrives as a silent zero.
- **Keep the raw payload** beside the mapped value when an audit needs to see what arrived.

## Pinned formats

A format something reads later, such as a file, a stored row, a log line other tools parse or a protocol message, has its own encoder, and a test pins its bytes:

```go
// EventKind is what happened, as the order log records it.
type EventKind string

// The events the order log records.
const (
	EventPlaced    EventKind = "order.placed"
	EventCancelled EventKind = "order.cancelled"
)

// event is one line of the order log, a file other tools read.
type event struct {
	At    time.Time `json:"at"`
	Kind  EventKind `json:"kind"`
	Order string    `json:"order"`
}

// encodeEvent is the one writer of the order log's line format.
func encodeEvent(e event) ([]byte, error) {
	e.At = e.At.UTC()
	b, err := json.Marshal(e)
	if err != nil {
		return nil, fmt.Errorf("encode %s event for %s: %w", e.Kind, e.Order, err)
	}
	return append(b, '\n'), nil
}
```

```go
func TestEncodeEventPinsTheLineFormat(t *testing.T) {
	at := time.Date(2026, 3, 1, 10, 0, 0, 0, time.FixedZone("CET", 3600))
	got, err := encodeEvent(event{At: at, Kind: EventCancelled, Order: "ord-1"})
	if err != nil {
		t.Fatal(err)
	}
	want := `{"at":"2026-03-01T09:00:00Z","kind":"order.cancelled","order":"ord-1"}` + "\n"
	if string(got) != want {
		t.Errorf("encodeEvent() = %q, want %q", got, want)
	}
}
```

- **Never through a display formatter.** If the stored form calls the function that words a duration for the screen, then rewording "1h30m" as "1.5h" rewrites every file written after it.
- **The format fixes its own zone, precision and order.** Here, UTC and RFC 3339.
- **Pinning bytes goes against the Go team's advice to compare parsed JSON, on purpose.** For a stored format, any byte change is one to see, a Go release's included. Compare parsed values for a response nothing stores.
- **Decode the oldest format still on disk.** Keep a sample in `testdata/` and test the decoder against it.

## JSON

**v1 or v2.** `encoding/json/v2` is stable from [Go 1.27](https://go.dev/doc/go1.27). `encoding/json` now runs on it with v1's behaviour kept, and its own doc recommends v2 for new code.

- **A module with no JSON yet uses v2:** `import "encoding/json/v2"`, whose package is still `json`. The examples here use it.
- **A module already on v1 stays on v1** until one deliberate switch of the whole module, in a change of its own. Never one package or one call at a time: v2's defaults change behaviour, and a module half on each reads one payload two ways.
- **What the switch changes,** each to check before it lands:
  - duplicate names and invalid UTF-8 are errors, where v1 accepted them;
  - names match by case, where v1 ignored case;
  - nil slices and maps encode as `[]` and `{}`, where v1 wrote `null`;
  - a `time.Duration` has no default form and fails to encode, where v1 wrote nanoseconds;
  - `omitempty` omits only what encodes as empty (`""`, `null`, `[]`, `{}`), so a `0` or `false` it used to drop now goes out: those fields take `omitzero`.
- **The pinned-format tests run before and after the switch,** so a stored format that changes is seen.

**Which omit tag:**

| Field | Tag |
|---|---|
| a string, slice or map | `omitempty` |
| a struct, a `time.Time`, or a type with `IsZero` | `omitzero` ([Go 1.24](https://go.dev/doc/go1.24)) |
| a number or bool whose zero means "not set" | `omitzero` |
| a number or bool where `0` or `false` is a real answer | neither |

v1's `omitempty` never omits a struct or a `time.Time`, so `"placed_at":"0001-01-01T00:00:00Z"` goes out. `omitzero` omits the zero value, or whatever the type's `IsZero` reports, and means the same in v1 and v2.

**Durations.** Neither version writes one well. Put a string in the wire struct and parse it in the mapper:

```go
// pollJSON is the poller's settings as the config file holds them.
type pollJSON struct {
	Interval string `json:"interval,omitempty"` // "30s", as time.ParseDuration reads it
}
```

**Optional values.** A wire field that can be absent when zero is a real answer is a pointer, built with `new(expr)` ([Go 1.26](https://go.dev/doc/go1.26)): `Percent: new(0)`.

## Other formats

- **A value with one canonical text form** (an id, an enum, an email, money) implements `MarshalText` and `UnmarshalText`. JSON, map keys, config files and flags then all use the same form, and decoding validates.
- **A line or record format** has one encoder and one decoder, side by side, tested against each other and against a pinned sample.
- **A protocol's own types** (generated code, a vendor SDK's structs) are wire structs too. Map them at the edge, and never pass them inward.

## Checklist

- [ ] Each payload has its own tagged, unexported wire struct at the edge.
- [ ] One mapper per source, which only maps, and whose errors name the record and field.
- [ ] Enums and ids parsed in the mapper, or by `UnmarshalText`.
- [ ] Stored formats have their own encoder, with bytes pinned in a test.
- [ ] `omitzero` on structs and times; durations as strings on the wire.
- [ ] New JSON on `encoding/json/v2`; a v1 module stays on v1 until the whole module switches at once.
