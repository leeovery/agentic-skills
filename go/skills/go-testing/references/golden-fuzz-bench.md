# Golden Files, Fuzzing and Benchmarks

**Output too long to write inline is pinned in a golden file, bytes from outside are fuzzed, and a claim about speed is a benchmark.**

Contents: golden files · fuzzing · benchmarks.

## Golden files

The expected output lives in `testdata/<name>.golden`; the go tool ignores `testdata/`. An `-update` flag rewrites the files, and the diff in version control is the review: a golden file is the spec, so read every change to one before committing it.

```go
var update = flag.Bool("update", false, "rewrite the golden files in testdata")

func TestRenderMatchesItsGoldenFile(t *testing.T) {
	got := Render(Receipt{Order: "ord-1", Lines: []Line{
		{Item: "Widget", Quantity: 2, Each: 1250},
		{Item: "Gasket", Quantity: 10, Each: 35},
	}})

	checkGolden(t, "receipt", got)
}

// checkGolden compares got with testdata/name.golden, rewriting the file
// first when -update is set.
func checkGolden(t *testing.T, name string, got []byte) {
	t.Helper()
	path := filepath.Join("testdata", name+".golden")
	if *update {
		if err := os.WriteFile(path, got, 0o644); err != nil {
			t.Fatalf("update %s: %v", path, err)
		}
	}
	want, err := os.ReadFile(path)
	if err != nil {
		t.Fatalf("read %s: %v (go test -update writes it)", path, err)
	}
	if !bytes.Equal(got, want) {
		t.Errorf("%s differs (-want +got):\n%s", path, diffLines(string(want), string(got)))
	}
}
```

`diffLines` is in [tables.md](tables.md). `go test ./receipt -run TestRender -update` rewrites the one file.

- **Only stable bytes.** Fix the clock (a bubble's, or an injected `now`), sort anything that comes from a map, and keep absolute paths, random ids and durations out. A golden file that changes on every run gets updated without being read.
- **Name the file for the case,** as `checkGolden` takes a name. A subtest's `t.Name()` holds a `/`, which turns into a directory.
- **Golden files pin formats you own,** where every byte is the point: a rendered receipt, a stored file, a CLI's output. For a value with a few fields, compare the fields.
- **Other output a test produces** (a rendered image, a log worth keeping) goes in `t.ArtifactDir()` ([Go 1.26](https://go.dev/doc/go1.26)): a temporary directory, kept under the output directory when `go test -artifacts` is set. Never into the source tree.

## Fuzzing

Every function that parses bytes from outside the program (a file, a request, an import, a protocol line) gets a fuzz target. Seed it from the fixtures the other tests already use, so the fuzzer starts from realistic input.

```go
func FuzzParseLine(f *testing.F) {
	seeds, err := os.ReadFile(filepath.Join("testdata", "lines.txt"))
	if err != nil {
		f.Fatalf("read seeds: %v", err)
	}
	for line := range strings.Lines(string(seeds)) {
		f.Add(strings.TrimSuffix(line, "\n"))
	}
	f.Fuzz(func(t *testing.T, s string) {
		l, err := ParseLine(s)
		if err != nil {
			return
		}
		again, err := ParseLine(l.Format())
		if err != nil || again != l {
			t.Errorf("ParseLine(%q) = %+v, which formats as %q and reads back as %+v, %v", s, l, l.Format(), again, err)
		}
	})
}
```

`strings.Lines` ([Go 1.24](https://go.dev/doc/go1.24)) yields each line with its newline.

- **Check a property, not an answer.** The fuzzer invents the input, so the test can't know the output. Good properties: no panic (checked for free); a value parsed, formatted and parsed again comes back the same; whatever is accepted passes validation; the parser agrees with a slower, simpler one.
- **Plain `go test` runs the seeds** as ordinary cases, so the target guards the gate from the day it's written. `go test -fuzz=FuzzParseLine -fuzztime=30s ./receipt` searches for new inputs; it takes one target at a time, and stays out of the gate as it never ends on its own.
- **Commit what it finds.** A failing input is minimised and written to `testdata/fuzz/FuzzParseLine/`. Commit the file with the fix: from then on it runs as a regression case.
- **Keep targets fast and deterministic,** with no state carried between calls: they run in parallel and in any order. Arguments are limited to strings, byte slices, integers, floats and bools.

## Benchmarks

```go
func BenchmarkRender(b *testing.B) {
	r := Receipt{Order: "ord-1", Lines: slices.Repeat([]Line{{Item: "Widget", Quantity: 2, Each: 1250}}, 50)}
	for b.Loop() {
		Render(r)
	}
}
```

- **`for b.Loop()`** ([Go 1.24](https://go.dev/doc/go1.24)), never `for i := 0; i < b.N; i++`. Setup before the loop isn't timed, so no `ResetTimer`, and the compiler can't optimise the loop's work away, so no sink variable. Since [Go 1.26](https://go.dev/doc/go1.26) it no longer blocks inlining either, so every `b.N` benchmark can move to it.
- **`b.ReportAllocs()`** when allocations are the point.
- **One run proves nothing.** Compare before and after with `-count=10` or more on both sides, on a quiet machine, and keep the numbers with the change that claims the speed-up.
- **A benchmark is not a test.** It checks no output, so the function it measures still needs a test that does.
