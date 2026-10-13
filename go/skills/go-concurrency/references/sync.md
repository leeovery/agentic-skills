# Shared State

**Share as little as you can. What is shared sits behind one named mutex or one atomic, and no lock is held while waiting on anything else.**

Contents: mutex, atomic or channel · the mutex · keeping locks off I/O · atomics · values computed once · copies and the race detector.

## Mutex, atomic or channel

| The goroutines… | Use |
|---|---|
| read and write the same few fields | a `sync.Mutex` |
| bump a counter or flip a flag | `atomic.Int64`, `atomic.Bool` |
| read a value that's replaced whole now and then | `atomic.Pointer[T]` |
| hand work, or ownership of a value, from one to another | a channel |
| need to know when a set of them has finished | a `WaitGroup`, not a channel per goroutine |

A channel used as a lock is a mutex with more ways to go wrong. A mutex used to pass work between goroutines is a channel built by hand. Pick by what moves: data that stays put wants a lock, work that moves wants a channel.

## The mutex

A named field, declared just above the fields it guards, with a comment saying which:

```go
// Balances keeps what each customer owes.
type Balances struct {
	mu   sync.Mutex // guards owed
	owed map[CustomerID]Cents
}

// Charge adds amount to what the customer owes.
func (b *Balances) Charge(id CustomerID, amount Cents) {
	b.mu.Lock()
	defer b.mu.Unlock()
	b.owed[id] += amount
}
```

- Never embedded: embedding puts `Lock` and `Unlock` in the type's method set, so any caller can take the lock.
- Methods that touch it have pointer receivers. A value receiver locks a copy.
- `defer Unlock` right after `Lock`, unless the work after the critical section is long, as below.

## Keeping locks off I/O

Under a lock, do only work on memory. Copy out what the slow part needs, unlock, then do it.

```go
// FlushHeld holds the lock while it writes: every Charge waits on the disk.
func (b *Balances) FlushHeld(w io.Writer) error {
	b.mu.Lock()
	defer b.mu.Unlock()
	return json.MarshalWrite(w, b.owed)
}

// Flush copies under the lock and writes after it.
func (b *Balances) Flush(w io.Writer) error {
	b.mu.Lock()
	owed := maps.Clone(b.owed)
	b.mu.Unlock()
	if err := json.MarshalWrite(w, owed); err != nil {
		return fmt.Errorf("flush balances: %w", err)
	}
	return nil
}
```

`json` is `encoding/json/v2` ([Go 1.27](https://go.dev/doc/go1.27)), whose `MarshalWrite` encodes straight to the writer.

The same goes for:
- **A channel send or receive under a lock.** If the other side needs the same lock, neither moves.
- **A callback under a lock.** It may call back into the type, and Go's mutex isn't reentrant, so it deadlocks; or it may block on something you can't see.
- **Logging under a lock.** A handler that writes to a slow sink is I/O.

The one exception is a lock whose whole job is to keep writes to one file or connection from interleaving. It guards that file, and nothing else goes under it.

## Atomics

Typed atomics, never the `atomic.AddInt64(&n, 1)` functions on a plain integer. A typed atomic can't be read or written non-atomically by mistake, and is aligned on every platform. modernize's `atomictypes` rewrites the old form.

```go
// Stats counts what the poller has done.
type Stats struct {
	settled atomic.Int64
	paused  atomic.Bool
}

// Settled counts one more settled payment.
func (s *Stats) Settled() { s.settled.Add(1) }

// Paused reports whether settling is paused.
func (s *Stats) Paused() bool { return s.paused.Load() }
```

For a read-mostly value replaced whole, such as a price list or a config, `atomic.Pointer[T]` lets readers load it without a lock. The writer builds a new value and stores it; nobody mutates what `Load` returned.

```go
// Pricing holds the current prices, replaced whole when they change.
type Pricing struct {
	current atomic.Pointer[Prices]
}

// Load returns the current prices, which the caller must not change.
func (p *Pricing) Load() *Prices { return p.current.Load() }

// Replace makes next the current prices.
func (p *Pricing) Replace(next Prices) { p.current.Store(&next) }
```

An atomic guards one value. Two values that must change together need a mutex.

## Values computed once

`sync.OnceValue` and `sync.OnceValues` return a function that computes the value on its first call and returns the same result ever after, error included. They replace a `sync.Once` beside a variable, where the error is easy to drop.

```go
// Converter converts between currencies, reading its rates on first use.
type Converter struct {
	rates func() (Rates, error)
}

// NewConverter returns a Converter that reads its rates from path.
func NewConverter(path string) *Converter {
	return &Converter{rates: sync.OnceValues(func() (Rates, error) { return readRates(path) })}
}

// Rate returns the rate for currency.
func (c *Converter) Rate(currency string) (float64, error) {
	rates, err := c.rates()
	if err != nil {
		return 0, err
	}
	r, ok := rates[currency]
	if !ok {
		return 0, fmt.Errorf("no rate for %s", currency)
	}
	return r, nil
}
```

- A failure is cached like a success. If a later call should retry, `Once` is the wrong tool.
- Keep it in a field, as here, not a package variable: a package-level once is global state that every test shares.

## Copies and the race detector

- A struct holding a mutex, a `WaitGroup` or an atomic must not be copied after first use. vet's `copylocks` catches most copies: pass it by pointer.
- `sync.RWMutex` costs more than `Mutex` and pays only when reads far outnumber writes and the lock is contended. Start with `Mutex`.
- Run the tests with `-race`. A data race is a bug even when every test passes, and the detector finds only the races the tests exercise, so a type shared between goroutines has a test that shares it.
