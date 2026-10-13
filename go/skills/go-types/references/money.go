// Package money holds amounts of money: a count of a currency's minor unit, such as
// pence, so no amount is ever a float.
//
// It's a drop-in: copy it into a module as internal/money and trim what the module
// doesn't use. It holds arithmetic and one text form ("12.50 GBP") for storage and
// the wire. How an amount reads on screen ("£12.50") is the module's formatter's job.
package money

import (
	"errors"
	"fmt"
	"math"
	"strconv"
	"strings"
)

var (
	// ErrCurrencyMismatch is arithmetic or a comparison across two currencies.
	ErrCurrencyMismatch = errors.New("currencies differ")
	// ErrOverflow is a result too large to count in an int64 of minor units.
	ErrOverflow = errors.New("amount out of range")
)

// Currency is an ISO 4217 code this package knows.
type Currency string

// The currencies this package knows.
const (
	CurrencyEUR Currency = "EUR"
	CurrencyGBP Currency = "GBP"
	CurrencyJPY Currency = "JPY"
	CurrencyUSD Currency = "USD"
)

// ParseCurrency reads an ISO 4217 code, refusing one this package doesn't know.
func ParseCurrency(s string) (Currency, error) {
	c := Currency(s)
	if _, ok := c.digits(); !ok {
		return "", fmt.Errorf("unknown currency %q", s)
	}
	return c, nil
}

// digits is how many digits the minor unit takes: 2 for pence and cents, 0 for yen.
func (c Currency) digits() (int, bool) {
	switch c {
	case CurrencyEUR, CurrencyGBP, CurrencyUSD:
		return 2, true
	case CurrencyJPY:
		return 0, true
	}
	return 0, false
}

// Money is an amount in one currency. The zero value is no money in no currency: it
// adds to an amount of any currency, and IsZero reports it.
type Money struct {
	minor    int64
	currency Currency
}

// New is minor units of c: New(1250, CurrencyGBP) is £12.50.
func New(minor int64, c Currency) (Money, error) {
	if _, ok := c.digits(); !ok {
		return Money{}, fmt.Errorf("unknown currency %q", c)
	}
	return Money{minor: minor, currency: c}, nil
}

// Minor is the amount in the currency's minor unit.
func (m Money) Minor() int64 { return m.minor }

// Currency is the amount's currency, empty for the zero value.
func (m Money) Currency() Currency { return m.currency }

// IsZero reports whether m is the zero value. An explicit nought, such as 0.00 GBP, is
// not zero here, so omitzero keeps it.
func (m Money) IsZero() bool { return m == Money{} }

// Sign is -1, 0 or +1 as the amount is below, at or above nought.
func (m Money) Sign() int {
	switch {
	case m.minor < 0:
		return -1
	case m.minor > 0:
		return 1
	}
	return 0
}

// Add is m plus o.
func (m Money) Add(o Money) (Money, error) {
	c, err := m.match(o)
	if err != nil {
		return Money{}, err
	}
	sum, ok := add(m.minor, o.minor)
	if !ok {
		return Money{}, fmt.Errorf("add %s and %s: %w", m, o, ErrOverflow)
	}
	return Money{minor: sum, currency: c}, nil
}

// Sub is m minus o.
func (m Money) Sub(o Money) (Money, error) {
	c, err := m.match(o)
	if err != nil {
		return Money{}, err
	}
	diff, ok := sub(m.minor, o.minor)
	if !ok {
		return Money{}, fmt.Errorf("subtract %s from %s: %w", o, m, ErrOverflow)
	}
	return Money{minor: diff, currency: c}, nil
}

// Times is m multiplied by n, as for a quantity.
func (m Money) Times(n int64) (Money, error) {
	p, ok := mul(m.minor, n)
	if !ok {
		return Money{}, fmt.Errorf("multiply %s by %d: %w", m, n, ErrOverflow)
	}
	return Money{minor: p, currency: m.currency}, nil
}

// Split divides m into n parts that sum to m exactly. The minor units left over go one
// each to the first parts, so 10.00 GBP in three is 3.34, 3.33 and 3.33.
func (m Money) Split(n int) ([]Money, error) {
	if n < 1 {
		return nil, fmt.Errorf("split %s into %d parts: want at least one", m, n)
	}
	q, r := m.minor/int64(n), m.minor%int64(n)
	step := int64(1)
	if r < 0 {
		step, r = -1, -r
	}
	parts := make([]Money, n)
	for i := range parts {
		parts[i] = Money{minor: q, currency: m.currency}
		if int64(i) < r {
			parts[i].minor += step
		}
	}
	return parts, nil
}

// Cmp is -1, 0 or +1 as m is less than, equal to or more than o.
func (m Money) Cmp(o Money) (int, error) {
	if _, err := m.match(o); err != nil {
		return 0, err
	}
	switch {
	case m.minor < o.minor:
		return -1, nil
	case m.minor > o.minor:
		return 1, nil
	}
	return 0, nil
}

// Sum adds ms together. With none, it's the zero value.
func Sum(ms ...Money) (Money, error) {
	var total Money
	for _, m := range ms {
		var err error
		if total, err = total.Add(m); err != nil {
			return Money{}, err
		}
	}
	return total, nil
}

// match is the currency of m and o together: the zero value takes the other's.
func (m Money) match(o Money) (Currency, error) {
	switch {
	case m.IsZero():
		return o.currency, nil
	case o.IsZero(), m.currency == o.currency:
		return m.currency, nil
	}
	return "", fmt.Errorf("%s and %s: %w", m.currency, o.currency, ErrCurrencyMismatch)
}

// String is the amount's text form: "12.50 GBP", "-0.05 USD", "1250 JPY", or "0" for
// the zero value. Parse reads it back.
func (m Money) String() string {
	if m.IsZero() {
		return "0"
	}
	d, _ := m.currency.digits()
	sign, u := "", uint64(m.minor)
	if m.minor < 0 {
		sign, u = "-", -u
	}
	if d == 0 {
		return fmt.Sprintf("%s%d %s", sign, u, m.currency)
	}
	unit := uint64(1)
	for range d {
		unit *= 10
	}
	return fmt.Sprintf("%s%d.%0*d %s", sign, u/unit, d, u%unit, m.currency)
}

// Parse reads the text form String writes. It takes fewer decimals than the currency
// has ("12.5 GBP") but never more.
func Parse(s string) (Money, error) {
	if s == "0" {
		return Money{}, nil
	}
	amount, code, ok := strings.Cut(s, " ")
	if !ok {
		return Money{}, fmt.Errorf("parse money %q: want an amount and a currency", s)
	}
	c, err := ParseCurrency(code)
	if err != nil {
		return Money{}, fmt.Errorf("parse money %q: %w", s, err)
	}
	minor, err := parseMinor(amount, c)
	if err != nil {
		return Money{}, fmt.Errorf("parse money %q: %w", s, err)
	}
	return Money{minor: minor, currency: c}, nil
}

func parseMinor(amount string, c Currency) (int64, error) {
	d, _ := c.digits()
	neg := strings.HasPrefix(amount, "-")
	amount = strings.TrimPrefix(amount, "-")
	whole, frac, dot := strings.Cut(amount, ".")
	if whole == "" || (dot && (frac == "" || len(frac) > d)) {
		return 0, fmt.Errorf("want up to %d decimals for %s", d, c)
	}
	frac += strings.Repeat("0", d-len(frac))
	digits, err := strconv.ParseUint(whole+frac, 10, 64)
	if err != nil {
		return 0, fmt.Errorf("amount: %w", err)
	}
	if neg {
		if digits > -math.MinInt64 {
			return 0, ErrOverflow
		}
		return int64(-digits), nil
	}
	if digits > math.MaxInt64 {
		return 0, ErrOverflow
	}
	return int64(digits), nil
}

// MarshalText writes String's form, for JSON and other text formats.
func (m Money) MarshalText() ([]byte, error) { return []byte(m.String()), nil }

// UnmarshalText reads String's form, refusing an amount that doesn't parse.
func (m *Money) UnmarshalText(b []byte) error {
	v, err := Parse(string(b))
	if err != nil {
		return err
	}
	*m = v
	return nil
}

func add(a, b int64) (int64, bool) {
	s := a + b
	if (b > 0 && s < a) || (b < 0 && s > a) {
		return 0, false
	}
	return s, true
}

func sub(a, b int64) (int64, bool) {
	d := a - b
	if (b > 0 && d > a) || (b < 0 && d < a) {
		return 0, false
	}
	return d, true
}

func mul(a, b int64) (int64, bool) {
	if a == 0 || b == 0 {
		return 0, true
	}
	p := a * b
	if p/b != a || (a == -1 && b == math.MinInt64) || (b == -1 && a == math.MinInt64) {
		return 0, false
	}
	return p, true
}
