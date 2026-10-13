# Table Tests and Names

**A table is one behaviour fed many inputs. When the setup or the check changes, it's another test.**

Contents: when a table fits · row shape · errors · names · comparing without go-cmp · printing a diff · Error or Fatal · parallel rows.

## When a table fits

| Fits a table | Wants its own test |
|---|---|
| The same call, the same checks, different values | A different arrangement: another fake, another store state |
| Rows a reader can scan as data | A row that needs a `setup` func or a `wantCall bool` |
| Adding a case means adding a line | Adding a case means adding an `if` in the loop |

A loop that branches on a row's fields is several tests sharing a loop, and each branch is tested less clearly than it would be alone. Split it.

## Row shape

```go
func TestParseStatus(t *testing.T) {
	tests := []struct {
		name string
		in   string
		want Status
	}{
		{"reads pending", "pending", StatusPending},
		{"reads paid", "paid", StatusPaid},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			got, err := ParseStatus(tt.in)
			if err != nil {
				t.Fatalf("ParseStatus(%q) error = %v", tt.in, err)
			}
			if got != tt.want {
				t.Errorf("ParseStatus(%q) = %v, want %v", tt.in, got, tt.want)
			}
		})
	}
}
```

- `name` comes first, and reads as the end of a sentence that starts with the test's name.
- `want` fields hold the expected value, never a flag deciding which check runs.
- Name the fields in each row (`{name: …, in: …}`) once a row is long, or when two fields share a type and could be swapped unseen.
- The failure message prints the input, even though the subtest name is in the output too: the name says which case, the message says what happened.
- Never identify a row by its index.

## Errors get their own table

Successes check a value; failures check an error. Mixing them puts a `wantErr` branch in the loop.

```go
func TestParseStatusRejects(t *testing.T) {
	tests := []struct {
		name string
		in   string
	}{
		{"an empty string", ""},
		{"a status it doesn't know", "lost"},
		{"a trailing space", "pending "},
		{"another case", "PAID"},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			_, err := ParseStatus(tt.in)
			if !errors.Is(err, ErrUnknownStatus) {
				t.Errorf("ParseStatus(%q) error = %v, want ErrUnknownStatus", tt.in, err)
			}
		})
	}
}
```

Match the error by identity or type, never its text. To check that a message names the bad input, check for the input, not the whole sentence.

## Names

- **Tests:** `Test` + subject + behaviour, as one sentence: `TestCancelRefundsAPaidOrder`, `TestLoadDefaultsWithoutAFile`. Not `TestCancel2`, `TestCancel_Error` or `TestCancelHappyPath`.
- **Rows:** lower-case phrases that finish the sentence: `TestParseStatusRejects` / `"a trailing space"`.
- `t.Run` turns spaces into underscores, so one row runs alone with `go test -run 'TestParseStatusRejects/a_trailing_space'`. Keep `/` out of names: it separates subtest levels.
- **Files:** `order_test.go` tests `order.go`. Use `package order` to test internals and `package order_test` to test only the exported API, or to break an import cycle.

## Comparing without go-cmp

| Comparing | Use | Watch for |
|---|---|---|
| Comparable values and structs | `==` | A struct with a slice, map or func field won't compile with `==` |
| Slices | `slices.Equal`, or `slices.EqualFunc` for non-comparable elements | nil and empty are equal |
| Maps | `maps.Equal`, `maps.EqualFunc` | nil and empty are equal |
| `time.Time` | `got.Equal(want)` | `==` also compares the location and the monotonic reading |
| Floats | `math.Abs(got-want) < tolerance` | Money is integer cents, so it never needs this |
| Nested data with slices or maps | `reflect.DeepEqual`, printing both with `%+v` | It compares unexported fields, and nil and empty slices differ |
| Errors | `errors.Is`, `errors.AsType` | Never `err.Error()` |

When a struct holds one slice, compare the comparable fields with `==` and the slice with `slices.Equal`: the message then says which part differs.

## Printing a diff

For long text, print which lines differ, labelled `(-want +got)`. A short helper does it without a dependency:

```go
// diffLines lists the lines that differ, position by position. A line
// inserted near the top shows every line after it as changed.
func diffLines(want, got string) string {
	w, g := strings.Split(want, "\n"), strings.Split(got, "\n")
	var b strings.Builder
	for i := range max(len(w), len(g)) {
		switch {
		case i >= len(w):
			fmt.Fprintf(&b, "+%d: %q\n", i+1, g[i])
		case i >= len(g):
			fmt.Fprintf(&b, "-%d: %q\n", i+1, w[i])
		case w[i] != g[i]:
			fmt.Fprintf(&b, "-%d: %q\n+%d: %q\n", i+1, w[i], i+1, g[i])
		}
	}
	return b.String()
}
```

`%q` shows trailing spaces and tabs, which is usually where two lines that look the same differ. [golden-fuzz-bench.md](golden-fuzz-bench.md) uses it for golden files.

## Error or Fatal

- `t.Errorf` by default, so one run reports every difference.
- `t.Fatalf` when the rest of the test can't mean anything: the call returned an error, or a setup step failed.
- Inside `t.Run`, `t.Fatal` ends only that row.

## Parallel rows

`t.Parallel()` at the top of the row's function is fine when the rows share nothing. It can't be combined with `t.Setenv` or `t.Chdir`, which change the whole process. Loop variables are per iteration since [Go 1.22](https://go.dev/doc/go1.22), so a parallel row needs no `tt := tt`.
