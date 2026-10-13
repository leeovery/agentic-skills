# Source guards

**A source guard is a test that reads the module's own source and fails where a rule breaks.**

Contents: a linter first · the shape of a guard · the helpers · guard 1, packages named for a job · guard 2, values worded by their owner · guard 3, a field read only through its owner · guard 4, table rows run under `t.Run` · keeping the helpers out of the binaries.

## A linter first

A linter setting is cheaper than a guard, so reach for one where it can say the rule:

| The rule | The setting |
|---|---|
| a package mustn't import another, or a module is banned | depguard, by file glob |
| a package mustn't call a function or use a variable | forbidigo, with an exclusion for the one place allowed |

A guard is for what no linter can say: a package's name, which function a call sits in, what a format string holds, what a name refers to, or a rule across the module's files.

## The shape of a guard

- **It lives with the rule.** A rule with one owner keeps its guard beside the owner (`internal/format/guard_test.go`), so the two move together. The helpers and module-wide guards live in `internal/guard`, which only tests import.
- **It finds the module by walking up to `go.mod`,** never by a fixed `../..` that breaks when the test moves.
- **It reads every Go file** but those under `testdata/`, `vendor/`, and directories starting with `.` or `_`. It never skips the root itself by that test: a checkout can sit inside a hidden directory.
- **It parses with `go/parser`,** and type-checks with `go/types` only when it must know what a name refers to. A typed guard type-checks the standard library from source, which takes seconds, so keep them few.
- **Each breach says where, what, and the fix:** `internal/report/late.go:12: words a number with a float verb: call the format package`. It reports every breach with `t.Error`, not the first with `t.Fatal`.
- **Its allowance list only shrinks.** Each entry is `path:function`, with a comment saying why it's allowed for now. A breach in an entry passes; an entry with no breach left fails, so it's deleted the day the code is fixed.
- **It's been seen to fail.** A second test writes a fixture module under `t.TempDir()` with `guard.Write`, breaking the rule once, runs the guard over it, and checks it reports exactly that. A guard reading the wrong directory otherwise passes forever.

## The helpers

```go
// internal/guard/guard.go

// Package guard reads the module's own source for the tests that hold its
// rules. Only tests import it.
package guard

import (
	"fmt"
	"go/ast"
	"go/parser"
	"go/token"
	"io/fs"
	"maps"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"
)

// File is one of the module's Go files, parsed.
type File struct {
	Path string // from the module's root, with slashes
	Fset *token.FileSet
	AST  *ast.File
}

// At is where pos is in the file, as "internal/report/report.go:42".
func (f File) At(pos token.Pos) string {
	return fmt.Sprintf("%s:%d", f.Path, f.Fset.Position(pos).Line)
}

// ModuleRoot is the directory holding go.mod, found from the test's
// directory up.
func ModuleRoot(tb testing.TB) string {
	tb.Helper()
	dir, err := os.Getwd()
	if err != nil {
		tb.Fatal(err)
	}
	for {
		if _, err := os.Stat(filepath.Join(dir, "go.mod")); err == nil {
			return dir
		}
		parent := filepath.Dir(dir)
		if parent == dir {
			tb.Fatal("no go.mod above the test's directory")
		}
		dir = parent
	}
}

// Files parses every Go file under root, tests included, but those under
// testdata, vendor, or a directory whose name starts with "." or "_".
func Files(tb testing.TB, root string) []File {
	tb.Helper()
	fset := token.NewFileSet()
	var files []File
	err := filepath.WalkDir(root, func(path string, d fs.DirEntry, err error) error {
		switch {
		case err != nil:
			return err
		case d.IsDir() && path != root && skipped(d.Name()):
			return filepath.SkipDir
		case d.IsDir() || !strings.HasSuffix(path, ".go"):
			return nil
		}
		parsed, err := parser.ParseFile(fset, path, nil, parser.SkipObjectResolution)
		if err != nil {
			return err
		}
		rel, err := filepath.Rel(root, path)
		if err != nil {
			return err
		}
		files = append(files, File{Path: filepath.ToSlash(rel), Fset: fset, AST: parsed})
		return nil
	})
	if err != nil {
		tb.Fatal(err)
	}
	return files
}

// Write writes content to the file name, a path with slashes under root,
// making its directories: a fixture module for a test that proves a guard fails.
func Write(tb testing.TB, root, name, content string) {
	tb.Helper()
	p := filepath.Join(root, filepath.FromSlash(name))
	if err := os.MkdirAll(filepath.Dir(p), 0o755); err != nil {
		tb.Fatal(err)
	}
	if err := os.WriteFile(p, []byte(content), 0o644); err != nil {
		tb.Fatal(err)
	}
}

func skipped(dir string) bool {
	return dir == "testdata" || dir == "vendor" || strings.HasPrefix(dir, ".") || strings.HasPrefix(dir, "_")
}

// FuncName names the function decl declares, a method after its receiver's
// type, as "Report.Write": "" for any other declaration.
func FuncName(decl ast.Decl) string {
	fn, ok := decl.(*ast.FuncDecl)
	if !ok {
		return ""
	}
	if fn.Recv == nil {
		return fn.Name.Name
	}
	recv := fn.Recv.List[0].Type
	if star, ok := recv.(*ast.StarExpr); ok {
		recv = star.X
	}
	if ident, ok := recv.(*ast.Ident); ok {
		return ident.Name + "." + fn.Name.Name
	}
	return fn.Name.Name
}

// Allowance is the breaches a guard lets stand, each as "path:function".
type Allowance map[string]bool

// Allow is an allowance of entries.
func Allow(entries ...string) Allowance {
	a := make(Allowance)
	for _, e := range entries {
		a[e] = false
	}
	return a
}

// Covers reports whether a breach in function fn of the file at path is
// allowed, and marks its entry as still needed.
func (a Allowance) Covers(path, fn string) bool {
	key := path + ":" + fn
	if _, ok := a[key]; !ok {
		return false
	}
	a[key] = true
	return true
}

// Stale are the entries no breach needed, in order. Each is to be deleted, so
// the allowance only shrinks.
func (a Allowance) Stale() []string {
	var stale []string
	for _, e := range slices.Sorted(maps.Keys(a)) {
		if !a[e] {
			stale = append(stale, e+": breaks the rule no more; delete it from the allowance")
		}
	}
	return stale
}
```

## Guard 1: packages named for a job

The [SKILL.md](../SKILL.md#pattern) pattern: syntax alone, over every file, reporting each grab-bag package once.

## Guard 2: values worded by their owner

Display code words no value itself: no time layout and no float verb outside `format` (see [ownership](../../go-architecture/references/ownership.md)). Only the display packages are read, so a wire format written elsewhere, with a layout of its own, is untouched. A second test proves the guard against a fixture, the allowance's stale-entry check included.

```go
// internal/format/guard_test.go

package format_test

import (
	"go/ast"
	"go/token"
	"path"
	"regexp"
	"slices"
	"strings"
	"testing"

	"example.com/shop/internal/guard"
)

// displayPackages show values to people, by directory from the module's
// root. Each words them through format.
var displayPackages = []string{"internal/cli", "internal/report"}

// wordingAllowed are the functions of displayPackages that still word a value
// themselves, as "path:function". The list only shrinks.
var wordingAllowed = []string{
	// The yearly rate predates format.Percent; it moves with the yearly report.
	"internal/report/yearly.go:Yearly",
}

var floatVerb = regexp.MustCompile(`%[-+# 0]*[0-9]*(\.[0-9]+)?[eEfFgG]`)

func TestValuesAreWordedByFormat(t *testing.T) {
	for _, problem := range wordingBreaches(t, guard.ModuleRoot(t), wordingAllowed) {
		t.Error(problem)
	}
}

func TestTheWordingGuardFindsWhatItGuardsAgainst(t *testing.T) {
	root := t.TempDir()
	guard.Write(t, root, "go.mod", "module example.com/fixture\n")
	guard.Write(t, root, "internal/report/total.go", "package report\n\nimport \"fmt\"\n\nfunc Total(n float64) string { return fmt.Sprintf(\"%.2f\", n) }\n")
	got := wordingBreaches(t, root, []string{"internal/report/gone.go:Old"})
	want := []string{
		"internal/report/total.go:5: words a number with a float verb: call the format package",
		"internal/report/gone.go:Old: breaks the rule no more; delete it from the allowance",
	}
	if !slices.Equal(got, want) {
		t.Errorf("wordingBreaches = %q, want %q", got, want)
	}
}

// wordingBreaches are where the display packages under root word a value
// themselves, but in a function allowed, then the allowed entries no longer
// needed.
func wordingBreaches(t *testing.T, root string, allowed []string) []string {
	t.Helper()
	allow := guard.Allow(allowed...)
	var problems []string
	for _, f := range guard.Files(t, root) {
		if strings.HasSuffix(f.Path, "_test.go") || !slices.Contains(displayPackages, path.Dir(f.Path)) {
			continue
		}
		for _, decl := range f.AST.Decls {
			ast.Inspect(decl, func(n ast.Node) bool {
				call, ok := n.(*ast.CallExpr)
				if !ok {
					return true
				}
				if says := wordsItself(call); says != "" && !allow.Covers(f.Path, guard.FuncName(decl)) {
					problems = append(problems, f.At(call.Pos())+": "+says+": call the format package")
				}
				return true
			})
		}
	}
	return append(problems, allow.Stale()...)
}

// wordsItself says how call words a value itself, or is "" where it doesn't.
func wordsItself(call *ast.CallExpr) string {
	sel, ok := call.Fun.(*ast.SelectorExpr)
	if !ok {
		return ""
	}
	pkg, _ := sel.X.(*ast.Ident)
	switch {
	case sel.Sel.Name == "Format" || sel.Sel.Name == "AppendFormat":
		if slices.ContainsFunc(call.Args, isLayout) {
			return "words a time with its own layout"
		}
	case pkg != nil && pkg.Name == "strconv" && sel.Sel.Name == "FormatFloat":
		return "words a number with strconv.FormatFloat"
	case pkg != nil && pkg.Name == "fmt":
		if slices.ContainsFunc(call.Args, hasFloatVerb) {
			return "words a number with a float verb"
		}
	}
	return ""
}

// isLayout reports whether arg is a layout written in place: a string, or one
// of time's constants.
func isLayout(arg ast.Expr) bool {
	switch a := arg.(type) {
	case *ast.BasicLit:
		return a.Kind == token.STRING
	case *ast.SelectorExpr:
		pkg, ok := a.X.(*ast.Ident)
		return ok && pkg.Name == "time"
	}
	return false
}

func hasFloatVerb(arg ast.Expr) bool {
	lit, ok := arg.(*ast.BasicLit)
	return ok && lit.Kind == token.STRING && floatVerb.MatchString(lit.Value)
}
```

Syntax can't see a `time.Duration` printed with `%v`, or a layout held in a variable. Where those matter, guard 3's type-checking finds them.

## Guard 3: a field read only through its owner

Whether an invoice is overdue is `billing`'s to say, so no other package reads `Invoice.DueAt`; it asks `inv.Overdue(now)`. Only types can tell an invoice's `DueAt` from any other, so this guard type-checks each package with the standard library's source importer.

```go
// internal/billing/guard_test.go

package billing_test

import (
	"go/ast"
	"go/importer"
	"go/types"
	"maps"
	"path"
	"slices"
	"strings"
	"testing"

	"example.com/shop/internal/guard"
)

// Whether an invoice is overdue is billing's to say, so no other package reads
// an invoice's DueAt: it asks inv.Overdue(now).
func TestDueDatesAreReadThroughBilling(t *testing.T) {
	files := guard.Files(t, guard.ModuleRoot(t))
	byDir := make(map[string][]guard.File)
	for _, f := range files {
		dir := path.Dir(f.Path)
		if dir != "internal/billing" && !strings.HasSuffix(f.Path, "_test.go") {
			byDir[dir] = append(byDir[dir], f)
		}
	}
	fset := files[0].Fset
	conf := types.Config{Importer: importer.ForCompiler(fset, "source", nil)}
	for _, dir := range slices.Sorted(maps.Keys(byDir)) {
		var syntax []*ast.File
		for _, f := range byDir[dir] {
			syntax = append(syntax, f.AST)
		}
		info := &types.Info{Selections: make(map[*ast.SelectorExpr]*types.Selection)}
		if _, err := conf.Check(dir, fset, syntax, info); err != nil {
			t.Fatalf("type-checking %s: %v", dir, err)
		}
		for _, f := range byDir[dir] {
			ast.Inspect(f.AST, func(n ast.Node) bool {
				sel, ok := n.(*ast.SelectorExpr)
				if !ok {
					return true
				}
				if s := info.Selections[sel]; s != nil && s.Kind() == types.FieldVal && isDueAt(s.Obj()) {
					t.Errorf("%s: reads Invoice.DueAt: ask inv.Overdue(now)", f.At(sel.Pos()))
				}
				return true
			})
		}
	}
}

// isDueAt reports whether field is billing's DueAt, read through any type
// that embeds an invoice too.
func isDueAt(field types.Object) bool {
	return field.Name() == "DueAt" && field.Pkg() != nil && strings.HasSuffix(field.Pkg().Path(), "/internal/billing")
}
```

- The source importer finds the module's packages through the `go` command, so this guard needs the `go` command on `PATH` when tests run. A test sandbox that forbids running it needs a loader of its own that reads the module's packages from their directories.
- Add an allowance list as guard 2 does when existing code breaks the rule on the day the guard lands.

## Guard 4: table rows run under `t.Run`

Every row of a table runs as a subtest, so a failing row names itself and runs alone with `-run` ([go-testing](../../go-testing/SKILL.md)). Syntax alone holds it: a `range` over a variable named `tests` or `cases`, in a test file, whose body calls no `Run`.

```go
// internal/guard/rows_test.go

package guard_test

import (
	"go/ast"
	"slices"
	"strings"
	"testing"

	"example.com/shop/internal/guard"
)

func TestTableRowsRunAsSubtests(t *testing.T) {
	for _, problem := range rowsOutsideRun(t, guard.ModuleRoot(t)) {
		t.Error(problem)
	}
}

func TestTheRowGuardFindsWhatItGuardsAgainst(t *testing.T) {
	root := t.TempDir()
	guard.Write(t, root, "go.mod", "module example.com/fixture\n")
	guard.Write(t, root, "order/order_test.go", `package order

import "testing"

func TestTotal(t *testing.T) {
	tests := []struct{ in, want int }{{1, 1}}
	for _, tt := range tests {
		if tt.in != tt.want {
			t.Errorf("Total(%d) = %d, want %d", tt.in, tt.in, tt.want)
		}
	}
}

func TestParse(t *testing.T) {
	cases := []struct{ name string }{{"empty"}}
	for _, tt := range cases {
		t.Run(tt.name, func(t *testing.T) {})
	}
}
`)
	want := []string{"order/order_test.go:7: a table's rows run outside t.Run: run each as t.Run(tt.name, …)"}
	if got := rowsOutsideRun(t, root); !slices.Equal(got, want) {
		t.Errorf("rowsOutsideRun = %q, want %q", got, want)
	}
}

// rowsOutsideRun are the loops in the test files under root that range over a
// table, a variable named tests or cases, without calling Run in their body.
func rowsOutsideRun(t *testing.T, root string) []string {
	t.Helper()
	var problems []string
	for _, f := range guard.Files(t, root) {
		if !strings.HasSuffix(f.Path, "_test.go") {
			continue
		}
		ast.Inspect(f.AST, func(n ast.Node) bool {
			loop, ok := n.(*ast.RangeStmt)
			if ok && isTable(loop.X) && !callsRun(loop.Body) {
				problems = append(problems, f.At(loop.Pos())+": a table's rows run outside t.Run: run each as t.Run(tt.name, …)")
			}
			return true
		})
	}
	return problems
}

func isTable(x ast.Expr) bool {
	id, ok := x.(*ast.Ident)
	return ok && (id.Name == "tests" || id.Name == "cases")
}

// callsRun reports whether body calls a Run method: t.Run, or b.Run in a benchmark.
func callsRun(body *ast.BlockStmt) bool {
	found := false
	ast.Inspect(body, func(n ast.Node) bool {
		if call, ok := n.(*ast.CallExpr); ok {
			if sel, ok := call.Fun.(*ast.SelectorExpr); ok && sel.Sel.Name == "Run" {
				found = true
			}
		}
		return !found
	})
	return found
}
```

- It goes by the variable's name, so a table named otherwise passes unseen. Name every table `tests`, or `cases` beside another, and the guard reads them all.
- It looks for any `X.Run` call in the loop's body, so `b.Run` in a benchmark passes as `t.Run` does. A loop that calls `t.Run` for some rows and not others passes too: review catches that, and rule 3 of go-testing forbids the branch.

## Keeping the helpers out of the binaries

`internal/guard` imports `testing`. A depguard rule keeps it to tests:

```yaml
linters:
  settings:
    depguard:
      rules:
        tests-only:
          files: ["$all", "!$test"]
          deny:
            - pkg: example.com/shop/internal/guard
              desc: "it reads the module's source for tests; keep it out of every binary"
```
