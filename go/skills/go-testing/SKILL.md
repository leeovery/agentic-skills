---
name: go-testing
description: Standard-library Go tests, with table rows as subtests, hand-written fakes over mocks, synctest for time, and failures that name the input. Use when writing or changing tests, fixing a flaky or slow test, or adding fakes, test builders, golden files, fuzz targets or benchmarks.
---

# Go Testing

Tests are plain Go: the `testing` package, a fake for each seam, and a failure that says what was called, with what, and what came back.

## Core rules

1. **Use `testing` alone.** Compare with `==`, `slices.Equal`, `maps.Equal`, or `reflect.DeepEqual` on plain data with both values printed. No testify, go-cmp, assertion helpers or mock generators: a plain `if` carries on past the first difference and says exactly what it was, where an assert library adds a dialect and a dependency. Gate: depguard.
2. **A failure names the call and its input, got before want:** `t.Errorf("ParseStatus(%q) = %v, want %v", in, got, want)`. A printed diff is labelled `(-want +got)`. The message alone should be enough to start on the fix.
3. **A table only when cases differ by data.** Every row runs under `t.Run(tt.name, …)`, rows carry no flags or setup functions, and errors get a table of their own. A row with a branch is two tests sharing a loop, and a row outside `t.Run` can't be run alone with `-run`. Gate: a source guard that every range over a table calls `t.Run`, Guard 4 in [go-quality's source-guards.md](../go-quality/references/source-guards.md).
4. **Name tests as behaviour:** `TestCancelRefusesAShippedOrder`, with rows such as `"refunds a paid order"`. The failing test's name is the first line of the bug report.
5. **Each case arranges, acts and asserts, and checks one behavioural fact.** Its input comes from a builder with options, such as `newOrder(paid)`, not from shared fixtures, so each test shows only what matters to it.
6. **Fake the seam, not the library:** a hand-written fake of your own interface, an `httptest` server for an HTTP client, a real store on `t.TempDir()`. Needing one mock to get a test green means a seam is missing. Gate: depguard bans the mocking libraries.
7. **Time and goroutines run inside `synctest.Test`** ([Go 1.25](https://go.dev/doc/go1.25)), with `synctest.Sleep` and `httptest.NewTestServer` ([Go 1.27](https://go.dev/doc/go1.27)). No real sleeps: the bubble's fake clock makes waits instant and exact, and a goroutine left blocked fails the test.
8. **Use the `testing` helpers:** `t.Context()` and `t.Chdir()` ([Go 1.24](https://go.dev/doc/go1.24)), `t.TempDir()`, `t.Setenv()` and `t.Cleanup()`. `t.Context()` is cancelled before cleanups run, so a cleanup that needs a context makes one from `context.WithoutCancel(t.Context())`. Gates: usetesting, modernize's `testingcontext`.
9. **Helpers call `t.Helper()` and fail with `t.Fatal`, only on the test's own goroutine;** another goroutine reports with `t.Error` and returns. Check errors with `errors.Is` or `errors.AsType` ([Go 1.26](https://go.dev/doc/go1.26)), never by their text. Gates: thelper, vet's `testinggoroutine`.
10. **Test where the bugs live.** Where two outputs show one fact (a list and its total, a file and its summary), a test checks they agree. Fixtures sit at the hard moments, just after a reset, after a failed read, with stale data, not at one easy instant. Bugs live between the cases.
11. **Fuzz every parser of outside bytes,** seeded from the fixtures, and commit the inputs it finds. Benchmarks loop with `for b.Loop()` ([Go 1.24](https://go.dev/doc/go1.24)).
12. **No test touches the real network, home directory, environment or state:** inject them. A project that must be sure adds a `TestMain` guard to every package and runs the tests in a sandbox.

## Pattern

```go
func TestCancel(t *testing.T) {
	tests := []struct {
		name         string
		order        Order
		wantRefunded []PaymentID
	}{
		{"cancels a pending order without a refund", newOrder(), nil},
		{"refunds a paid order", newOrder(paid), []PaymentID{"pay-1"}},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			refunds := &fakeRefunder{}
			c := Canceller{Orders: newMemoryStore(tt.order), Payments: refunds, Now: time.Now}

			_, err := c.Cancel(t.Context(), tt.order.ID, ReasonCustomer)

			if err != nil {
				t.Fatalf("Cancel(%q) error = %v", tt.order.ID, err)
			}
			if !slices.Equal(refunds.refunded, tt.wantRefunded) {
				t.Errorf("Cancel(%q) refunded %v, want %v", tt.order.ID, refunds.refunded, tt.wantRefunded)
			}
		})
	}
}
```

The builder, the fake and the store are in [fakes-and-builders.md](references/fakes-and-builders.md). A refusal is a different check, so it's a test of its own: `TestCancelRefusesAShippedOrder`, matching the error with `errors.AsType`.

## Decision table

| The test needs | Reach for |
|---|---|
| Many inputs through one behaviour | a table, each row under `t.Run` |
| A different setup or a different check | a test of its own |
| A collaborator you own | a hand-written fake of its interface |
| An outside HTTP API | your client against an `httptest` server |
| Files or a store | the real thing on `t.TempDir()` |
| Timers, tickers, timeouts, goroutines | `synctest.Test` |
| A decision that turns on the date | an injected `now` |
| Long or byte-exact output | a golden file under `testdata/` |
| Bytes from outside the program | a fuzz target |
| A claim about speed | a benchmark with `b.Loop()` |

## Anti-patterns

- ❌ `assert.Equal(t, want, got)` → `if got != want { t.Errorf("F(%v) = %v, want %v", in, got, want) }`.
- ❌ A loop over rows that checks inline, without `t.Run` → wrap each row in `t.Run(tt.name, …)`.
- ❌ A row field such as `setup func(*fakeStore)` or `wantCall bool` → split the cases into tests of their own.
- ❌ `time.Sleep(50 * time.Millisecond)` to let a goroutine finish → `synctest.Test`, with `synctest.Wait` or `synctest.Sleep`.
- ❌ `synctest.Run` → `synctest.Test`: `Run` was the [Go 1.24](https://go.dev/doc/go1.24) experiment.
- ❌ `strings.Contains(err.Error(), "not found")` → `errors.Is(err, ErrNotFound)`.
- ❌ `t.Fatal` in a goroutine the test started → `t.Error` and return, or send the result back to the test's goroutine.
- ❌ A mock of the vendor's SDK client → a fake of your own interface, and the adapter tested against `httptest`.
- ❌ `context.Background()`, or `os.Setenv` and `os.MkdirTemp` with a deferred undo → `t.Context()`, `t.Setenv`, `t.TempDir()`.
- ❌ `for i := 0; i < b.N; i++` after `b.ResetTimer()` → `for b.Loop() { … }`.
- ❌ `TestCancel2`, `TestCancel_Error` → a name that states the behaviour.

## References

- **[tables.md](references/tables.md)** — read when writing a table test or naming tests: when a table fits, row shape, names, comparing without go-cmp, and printing a diff.
- **[fakes-and-builders.md](references/fakes-and-builders.md)** — read when a test needs a collaborator or an input value: builders with options, hand-written fakes, `httptest`, and real stores.
- **[time.md](references/time.md)** — read when testing time, timers or goroutines: `synctest` patterns, Go 1.27's additions, what blocks durably, and when to inject `now` instead.
- **[golden-fuzz-bench.md](references/golden-fuzz-bench.md)** — read when adding golden files, fuzz targets or benchmarks: `-update`, `testdata/`, seeding, and `b.Loop`.
- **[isolation.md](references/isolation.md)** — read when setting up a project's test gate: injecting the outside world, the `TestMain` guard, and the sandbox.

## Related skills

- **[go-interfaces](../go-interfaces/SKILL.md)** — what a seam is, where its interface lives, and the clock and environment as seams
- **[go-concurrency](../go-concurrency/SKILL.md)** — the workers and contexts these tests drive
- **[go-errors](../go-errors/SKILL.md)** — the sentinels and typed errors a test matches on
- **[go-quality](../go-quality/SKILL.md)** — the gates and source guards that hold these rules
