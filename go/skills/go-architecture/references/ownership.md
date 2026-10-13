# Ownership

**Every meaning the program has is computed in one place, and everything else calls it.**

Contents: what counts as a rule · who owns it · before computing anything a screen shows · lifting a copy · shared rules first · guarding it.

## What counts as a rule

- **How a value reads to people:** a duration, a date, a sum of money, a ratio, a count past a thousand.
- **A decision two places make:** whether an order can be refunded, whether an account has room, which account a payment goes to, whether an invoice is overdue.
- **A derived figure:** a total, a rate, a status worked out from fields.
- **An order of things,** when two screens list the same things.

If two outputs show it, or two code paths decide on it, it has one owner.

## Who owns it

- **A figure or decision about a domain type** belongs to that type's package, often as a method: `inv.Overdue(now)`, `order.CancelRatio(orders)`.
- **How a value reads to people** belongs to `format`.
- **How a value is stored or sent** belongs beside its type, as its own function with a test that pins its bytes. It never calls the display wording, or a display tweak rewrites stored data.

## Before computing anything a screen shows

1. **Search for it:** the words the figure carries on screen ("cancelled"), the fields it reads (`Status == order.StatusCancelled`), the verbs and layouts (`%.1f`, `.Format(`).
2. **An owner exists:** call it.
3. **It sits inline in another caller:** lift it (below), then call it from both.
4. **Nothing exists:** write it in its owner, with tests, then call it.

## Lifting a copy

`report` needs the cancellation rate that `summary` already shows, and `summary` works it out inline:

```go
// internal/summary/summary.go, before
func Write(w io.Writer, orders []order.Order) error {
	cancelled := 0
	for _, o := range orders {
		if o.Status == order.StatusCancelled {
			cancelled++
		}
	}
	_, err := fmt.Fprintf(w, "cancelled %.1f%%\n", 100*float64(cancelled)/float64(len(orders)))
	return err
}
```

Copying this into `report` makes two owners, and the first fix lands in one of them. This one already has a bug: with no orders it prints `cancelled NaN%`.

Lifted, the rule moves to `order`, the wording to `format`, and each caller shrinks to a call:

```go
// internal/order/ratios.go

package order

// CancelRatio is the share of orders cancelled, from 0 to 1: 0 when there are
// none.
func CancelRatio(orders []Order) float64 {
	if len(orders) == 0 {
		return 0
	}
	cancelled := 0
	for _, o := range orders {
		if o.Status == StatusCancelled {
			cancelled++
		}
	}
	return float64(cancelled) / float64(len(orders))
}
```

```go
// internal/format/format.go

// Package format words values for people: every duration, date, count, sum
// of money and ratio a screen or a command shows goes through it.
package format

import "strconv"

// Percent words a ratio from 0 to 1 as a percentage to one decimal place,
// as "12.5%".
func Percent(r float64) string {
	return strconv.FormatFloat(r*100, 'f', 1, 64) + "%"
}
```

```go
// internal/summary/summary.go and internal/report/report.go alike
_, err := fmt.Fprintf(w, "cancelled %s\n", format.Percent(order.CancelRatio(orders)))
```

The steps, in one change:
1. Write the owner's function and its tests first, the case the copy got wrong among them (no orders).
2. Point the existing caller at it. Its output stays the same except where it was wrong, and the PR says where.
3. Write the new caller.
4. Where two copies already disagree, decide once which is right, test that case in the owner, and point both at it.
5. Search again for a third copy.

## Shared rules first

Before splitting work across agents, PRs or people:

- [ ] List each meaning more than one part will show or decide.
- [ ] Name each one's owner package and signature.
- [ ] Test each at its hard moments, not one frozen instant: zero and empty, the boundary, just after a reset, data missing or stale, the largest value.
- [ ] Build them first, in one change, merged before the parts start.
- [ ] Brief each part with the owners it calls, and that computing one of these meanings itself is a bug.
- [ ] Guard each rule a part could break quietly (below).
- [ ] Where two outputs show one fact, add a test that they agree.

## Guarding it

A rule that matters is held by a gate, not by the next reviewer:

| The risk | The gate |
|---|---|
| a raw field read outside its owner (`inv.DueAt` outside `billing`) | a typed source guard |
| a time layout or float verb outside `format` | a syntax source guard over the display packages |
| an import only one package may make | depguard |

Each guard keeps an allowance list for the copies not yet lifted, and the list only shrinks. Both guards are written out in go-quality's [source-guards.md](../../go-quality/references/source-guards.md), and the depguard rule in its [golangci.yml](../../go-quality/references/golangci.yml).
