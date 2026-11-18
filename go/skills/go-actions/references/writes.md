# Writes Without Transactions

**When the writes go to systems that share no transaction, everything is decided and checked before the first write, each write is leased on what was read, and a failure partway says exactly what landed.**

Contents: when this applies · plan, then apply · check everything first · leases · never retry what may have run · saying what landed · the edge and `--dry-run` · checklist.

## When this applies

`InTx` ([operations.md](operations.md#writes)) is for a store that has transactions. Many writes don't: a push to a git remote, a call to an outside API, the same change made in several repositories or storefronts. Nothing rolls them back together, so the work moves to before the first write and to what the error says after a failure.

## Plan, then apply

One job, two steps, sharing one set of collaborators:

```go
// Publisher sends a price list to the storefronts that sell the shop's goods.
type Publisher struct {
	Stores storefronts
}

// storefronts is each storefront's price list, as Publisher reads and writes it.
type storefronts interface {
	Prices(ctx context.Context, store StoreID) (list PriceList, version string, err error)
	CanWrite(ctx context.Context, store StoreID) (bool, error)
	Replace(ctx context.Context, store StoreID, version string, list PriceList) error
}

// Plan is what a publish would change, decided before anything is written.
type Plan struct {
	Changes []StoreChange // the storefronts whose list differs, in the order given
}

// StoreChange is one storefront's new list, leased on the version read.
type StoreChange struct {
	Store   StoreID
	Version string
	List    PriceList
}

// Plan reads every storefront and decides what Apply would change. It writes nothing.
func (p Publisher) Plan(ctx context.Context, want PriceList, stores []StoreID) (Plan, error) {
	var plan Plan
	for _, s := range stores {
		current, version, err := p.Stores.Prices(ctx, s)
		if err != nil {
			return Plan{}, fmt.Errorf("plan publish: %w", err)
		}
		if current.Equal(want) {
			continue
		}
		if err := p.ensureWritable(ctx, s); err != nil {
			return Plan{}, err
		}
		plan.Changes = append(plan.Changes, StoreChange{Store: s, Version: version, List: want})
	}
	return plan, nil
}

// Apply makes the plan's changes in order, stopping at the first that fails.
func (p Publisher) Apply(ctx context.Context, plan Plan) error {
	for i, c := range plan.Changes {
		if err := ctx.Err(); err != nil {
			return &PartialError{Plan: plan, Landed: i, Err: err, Untried: true}
		}
		if err := p.Stores.Replace(ctx, c.Store, c.Version, c.List); err != nil {
			return &PartialError{Plan: plan, Landed: i, Err: err}
		}
	}
	return nil
}
```

- **`Plan` reads, decides and checks; `Apply` writes.** Neither is a service of its own: they're the phases of one job, so they share the struct.
- **The plan is a value the edge can show,** and `Apply` takes it back, so what was shown is what runs.
- **`Apply` checks `ctx` before each write,** so an interrupt between two stops before the next, never midway through one.

## Check everything first

Every precondition is checked in `Plan`, before the first write, for every target: access, a conflict, a name that doesn't resolve, a version too old. A refusal found on the third storefront after the first two were written leaves a half state no transaction can undo.

- A check that needs a read is made in `Plan` too: reading the person's access costs a call, and writing two storefronts before finding the third refuses costs a repair.
- What can only be found by writing (a remote's own rule refusing the push) is the one failure left to `Apply`, and the error says it.

## Leases

Each write is a compare-and-swap on what `Plan` read: `If-Match` with the version an API gave, `git push --force-with-lease=<ref>:<sha>`, an update `WHERE version = ?`. Something that changed the target since the plan read it makes the write refuse, rather than overwrite a change nobody saw.

- **A lease refused on the write's first attempt changed nothing,** so it's a sentinel the adapter translates to (`ErrLeaseRefused`), and the next step is plain: read again and plan again. A lease refused on a retry may be the write's own first attempt, which landed, so a leased write is never retried blind (below).
- **A create leases on nothing being there,** so two runs at once can't both make it.

## Never retry what may have run

A write that timed out, got a 5xx, or lost its connection after the request went may have landed. Retrying it can make it land twice, and a leased retry reads its own first write as someone else's change.

- **The adapter never retries a write the other side can't de-duplicate.** It retries reads, which are safe, a write idempotent in itself that takes no lease (closing a pull request), which lands once however often it's sent, and a write that carries an idempotency key.
- **A leased write whose outcome is unknown is never retried blind,** since its own first write, landed, would refuse the retry's lease. The caller reads what's there, and the write's own value there means it landed. `Replace` is one: `Apply` reports it as "may have landed", and a fresh plan reads the storefront, finds its own list there, and leaves it.
- **It tells "never ran" from "may have run":** a request refused before it was sent, or a binary that never started, changed nothing; anything after the request left is unknown. The two are separate sentinels, and the edge words the second as "may have landed: look at <where>".
- **The person's interrupt bounds a write the storefront can't de-duplicate,** not a timeout of its own, since a timeout would turn a known outcome into an unknown one. A write that carries an idempotency key, or one idempotent in itself, keeps its deadline and may be retried, the latter where it takes no lease, and a server or worker makes each write one or the other ([go-concurrency's context.md](../../go-concurrency/references/context.md#deadlines-on-calls-that-leave-the-process)).

## Saying what landed

A failure partway names what was written, what may have been, and what wasn't tried:

```go
// PartialError is a publish that stopped partway.
type PartialError struct {
	Plan    Plan
	Landed  int   // how many of the plan's changes landed
	Err     error // why the next one failed, or why it wasn't tried
	Untried bool  // the run stopped before the next write was tried: an interrupt
}

func (e *PartialError) Error() string {
	if e.Untried {
		return e.said(e.Err.Error())
	}
	return e.said(fmt.Sprintf("%s: %v", e.Plan.Changes[e.Landed].Store, e.Err))
}

// Done is what the publish did before it was interrupted, which the renderer
// says after "interrupted": PartialError meets failure.Partial.
func (e *PartialError) Done() string { return e.said("") }

// said is what landed, then why it stopped, then what wasn't tried.
func (e *PartialError) said(stopped string) string {
	var parts []string
	if e.Landed > 0 {
		parts = append(parts, "published to "+storesOf(e.Plan.Changes[:e.Landed]))
	}
	rest := e.Plan.Changes[e.Landed:]
	if stopped != "" {
		parts = append(parts, stopped)
	}
	if !e.Untried {
		rest = rest[1:] // the one that failed
	}
	if len(rest) > 0 {
		parts = append(parts, storesOf(rest)+" not tried")
	}
	return strings.Join(parts, "; ")
}

func (e *PartialError) Unwrap() error { return e.Err }
```

- **The cause stays matchable,** so the edge tells a refused lease (nothing changed there) from a write that may have landed.
- **The words are exact,** "published to north and south; west: may have landed: …; east not tried", because the person repairs from them. A test pins them.
- **An interrupt blames no store:** stopped before a write, the renderer reads it as "interrupted; published to north; south and west not tried" ([cli.md](cli.md)'s `messageOf`).
- **Running it again is safe:** a fresh plan finds what already landed equal, and leaves it.

## The edge and `--dry-run`

```go
plan, err := deps.Publisher.Plan(ctx, list, stores)
if err != nil {
	return err
}
if dryRun {
	return show(out, plan)
}
return deps.Publisher.Apply(ctx, plan)
```

The split gives `--dry-run` for nothing: it is the plan, shown, with every check made and nothing written. A question before writing ("publish to 3 storefronts?") asks between the two.

## Checklist

- [ ] Every read and every check in the plan, before the first write.
- [ ] The plan is a value the edge can show; apply takes it back.
- [ ] Each write leased on what the plan read; a refused lease is its own sentinel.
- [ ] No write retried but one with an idempotency key, or one idempotent in itself that takes no lease; a leased write's unknown outcome read, never retried; "never ran" told from "may have run".
- [ ] `ctx` checked before each write.
- [ ] A failure partway names what landed, what may have, and what wasn't tried, pinned by a test.
