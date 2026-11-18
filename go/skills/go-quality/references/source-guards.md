# Source guards

**A source guard is a test that reads the module's own source and fails where a rule breaks.**

Contents: a linter first · the shape of a guard · the helpers · guard 1, packages named for a job · guard 2, values worded by their owner · guard 3, a field read only through its owner · guard 4, table rows run under `t.Run` · guard 5, what each build-tag set links · guard 6, starting the binary runs nothing · the detection file · keeping the helpers out of the binaries.

## A linter first

A linter setting is cheaper than a guard, so reach for one where it can say the rule:

| The rule | The setting |
|---|---|
| a package mustn't import another, or a module is banned | depguard, by file glob |
| a package mustn't call a function or use a variable | forbidigo, with an exclusion for the one place allowed |

A guard is for what no linter can say: a package's name, which function a call sits in, what a format string holds, what a name refers to, or a rule across the module's files.

A rule that ties an import to the one function or file that may use it, such as "only `start` names `os.Stdin`", stays a guard test whole, even where depguard could hold the import half. Split across a linter and a test, one rule has two homes, and the test is the half that can be seen to fail.

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

Every row of a table runs as a subtest, so a failing row names itself and runs alone with `-run` ([go-testing](../../go-testing/SKILL.md)). Syntax alone holds it: a `range` over a variable named `tests` or `cases`, in a test file's function or package-level function literal, whose body calls `Run` on a `*testing.T` or `*testing.B` parameter (of that function, or of a function literal in it), or calls a runner of its package's own: a function of its test files, not itself a `Test`, `Benchmark` or `Fuzz` function, that takes a `*testing.T` or `*testing.B` and calls `Run` on it (`forEachEra(t, tt.name, func(t *testing.T, e era) {…})`).

```go
// internal/guard/rows_test.go

package guard_test

import (
	"go/ast"
	"path"
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

import (
	"os/exec"
	"testing"
)

type era string

var shared *testing.T

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

func TestLoad(t *testing.T) {
	tests := []struct{ name string }{{"empty"}}
	for _, tt := range tests {
		forEachEra(t, tt.name, func(t *testing.T, e era) {})
	}
}

func TestSum(t *testing.T) {
	tests := []struct{ name string }{{"empty"}}
	for _, tt := range tests {
		forAll[int](t, tt.name, []int{1, 2}, func(t *testing.T, v int) {})
	}
}

func TestHelp(t *testing.T) {
	tests := []struct{ args []string }{{[]string{"help"}}}
	for _, tt := range tests {
		shop(t, tt.args...)
	}
}

func TestShared(t *testing.T) {
	tests := []struct{ name string }{{"empty"}}
	for _, tt := range tests {
		viaShared(tt.name, func(t *testing.T) {})
	}
}

func TestExec(t *testing.T) {
	tests := []struct{ args []string }{{[]string{"help"}}}
	for _, tt := range tests {
		if err := exec.Command("shop", tt.args...).Run(); err != nil {
			t.Fatal(err)
		}
	}
}

func TestEntry(t *testing.T) {
	tests := []struct{ args []string }{{[]string{"help"}}}
	for _, tt := range tests {
		if err := cli.Run(tt.args); err != nil {
			t.Fatal(err)
		}
	}
}

func TestFake(t *testing.T) {
	tests := []struct{ name string }{{"git"}}
	for _, tt := range tests {
		f := &fakeRunner{}
		if err := f.Run(tt.name); err != nil {
			t.Fatal(err)
		}
	}
}

// forEachEra runs a row as a subtest named for it, each era a subtest inside.
func forEachEra(t *testing.T, name string, row func(t *testing.T, e era)) {
	t.Run(name, func(t *testing.T) {
		for _, e := range []era{"old", "new"} {
			t.Run(string(e), func(t *testing.T) { row(t, e) })
		}
	})
}

// forAll runs a row as a subtest named for it, each value a subtest inside.
func forAll[V any](t *testing.T, name string, vs []V, row func(t *testing.T, v V)) {
	t.Run(name, func(t *testing.T) {
		for _, v := range vs {
			t.Run("", func(t *testing.T) { row(t, v) })
		}
	})
}

// shop runs the binary: its Run is exec's, so it runs no subtest.
func shop(t *testing.T, args ...string) {
	if err := exec.Command("shop", args...).Run(); err != nil {
		t.Fatal(err)
	}
}

// viaShared calls Run on a *testing.T it didn't take, so it's no runner.
func viaShared(name string, row func(t *testing.T)) {
	shared.Run(name, row)
}

// app is a command line's entry point, as a CLI's own package has one.
type app struct{}

func (app) Run(args []string) error { return nil }

var cli app

// fakeRunner stands in for the program a test runs.
type fakeRunner struct{ ran []string }

func (f *fakeRunner) Run(name string) error {
	f.ran = append(f.ran, name)
	return nil
}

// checkTotals is a test's body in a package-level function literal.
var checkTotals = func(t *testing.T) {
	tests := []struct{ in, want int }{{1, 1}}
	for _, tt := range tests {
		if tt.in != tt.want {
			t.Errorf("Total(%d) = %d, want %d", tt.in, tt.in, tt.want)
		}
	}
}
`)
	want := []string{
		"order/order_test.go:14: a table's rows run outside t.Run: run each as t.Run(tt.name, …)",
		"order/order_test.go:44: a table's rows run outside t.Run: run each as t.Run(tt.name, …)",
		"order/order_test.go:51: a table's rows run outside t.Run: run each as t.Run(tt.name, …)",
		"order/order_test.go:58: a table's rows run outside t.Run: run each as t.Run(tt.name, …)",
		"order/order_test.go:67: a table's rows run outside t.Run: run each as t.Run(tt.name, …)",
		"order/order_test.go:76: a table's rows run outside t.Run: run each as t.Run(tt.name, …)",
		"order/order_test.go:132: a table's rows run outside t.Run: run each as t.Run(tt.name, …)",
	}
	if got := rowsOutsideRun(t, root); !slices.Equal(got, want) {
		t.Errorf("rowsOutsideRun = %q, want %q", got, want)
	}
}

// rowsOutsideRun are the loops in the test files under root that range over a
// table, a variable named tests or cases, running neither a subtest of their
// own nor one of their package's runners in their body. Each function is read
// with its own testing parameters: a FuncDecl, or a function literal outside
// one, as a package-level var's.
func rowsOutsideRun(t *testing.T, root string) []string {
	t.Helper()
	var testFiles []guard.File
	for _, f := range guard.Files(t, root) {
		if strings.HasSuffix(f.Path, "_test.go") {
			testFiles = append(testFiles, f)
		}
	}
	runs := runners(testFiles)
	var problems []string
	for _, f := range testFiles {
		for _, d := range f.AST.Decls {
			for _, fn := range functionsIn(d) {
				tees := testingParamsIn(fn)
				ast.Inspect(fn.body, func(n ast.Node) bool {
					loop, ok := n.(*ast.RangeStmt)
					if ok && isTable(loop.X) && !runsSubtests(loop.Body, tees, runs[packageOf(f)]) {
						problems = append(problems, f.At(loop.Pos())+": a table's rows run outside t.Run: run each as t.Run(tt.name, …)")
					}
					return true
				})
			}
		}
	}
	return problems
}

// function is a function's type and body, a FuncDecl's or a literal's.
type function struct {
	typ  *ast.FuncType
	body *ast.BlockStmt
}

// functionsIn are d's functions: a FuncDecl with a body, else each outermost
// function literal in d, whose own literals are read inside it.
func functionsIn(d ast.Decl) []function {
	if fn, ok := d.(*ast.FuncDecl); ok {
		if fn.Body == nil {
			return nil
		}
		return []function{{fn.Type, fn.Body}}
	}
	var fns []function
	ast.Inspect(d, func(n ast.Node) bool {
		lit, ok := n.(*ast.FuncLit)
		if ok {
			fns = append(fns, function{lit.Type, lit.Body})
		}
		return !ok
	})
	return fns
}

func isTable(x ast.Expr) bool {
	id, ok := x.(*ast.Ident)
	return ok && (id.Name == "tests" || id.Name == "cases")
}

// runsSubtests reports whether body calls Run on one of the testing
// parameters named, or calls one of the runners named.
func runsSubtests(body *ast.BlockStmt, tees, runners []string) bool {
	return slices.ContainsFunc(tees, func(name string) bool { return callsRunOn(body, name) }) ||
		callsRunner(body, runners)
}

// packageOf is f's package, by its directory and its name, so a package
// and its _test package in one directory don't lend each other runners.
func packageOf(f guard.File) string {
	return path.Dir(f.Path) + ":" + f.AST.Name.Name
}

// runners are, by package, the functions of the test files that take a
// *testing.T or *testing.B and call Run on it: a row handed to one runs as a
// subtest. A Test, Benchmark or Fuzz function is none.
func runners(testFiles []guard.File) map[string][]string {
	found := map[string][]string{}
	for _, f := range testFiles {
		for _, d := range f.AST.Decls {
			fn, ok := d.(*ast.FuncDecl)
			if !ok || fn.Recv != nil || fn.Body == nil || isTestFunc(fn.Name.Name) {
				continue
			}
			if slices.ContainsFunc(testingParams(fn.Type), func(name string) bool { return callsRunOn(fn.Body, name) }) {
				found[packageOf(f)] = append(found[packageOf(f)], fn.Name.Name)
			}
		}
	}
	return found
}

func isTestFunc(name string) bool {
	return strings.HasPrefix(name, "Test") || strings.HasPrefix(name, "Benchmark") || strings.HasPrefix(name, "Fuzz")
}

// testingParamsIn are the names of the *testing.T and *testing.B parameters
// of fn and of every function literal in it.
func testingParamsIn(fn function) []string {
	names := testingParams(fn.typ)
	ast.Inspect(fn.body, func(n ast.Node) bool {
		if lit, ok := n.(*ast.FuncLit); ok {
			names = append(names, testingParams(lit.Type)...)
		}
		return true
	})
	return names
}

// testingParams are the names of a function's *testing.T and *testing.B
// parameters.
func testingParams(fn *ast.FuncType) []string {
	var names []string
	for _, field := range fn.Params.List {
		star, ok := field.Type.(*ast.StarExpr)
		if !ok {
			continue
		}
		sel, ok := star.X.(*ast.SelectorExpr)
		if !ok || (sel.Sel.Name != "T" && sel.Sel.Name != "B") {
			continue
		}
		if pkg, ok := sel.X.(*ast.Ident); !ok || pkg.Name != "testing" {
			continue
		}
		for _, n := range field.Names {
			names = append(names, n.Name)
		}
	}
	return names
}

// callsRunOn reports whether body calls Run on the variable named.
func callsRunOn(body *ast.BlockStmt, name string) bool {
	found := false
	ast.Inspect(body, func(n ast.Node) bool {
		if call, ok := n.(*ast.CallExpr); ok {
			if sel, ok := call.Fun.(*ast.SelectorExpr); ok && sel.Sel.Name == "Run" {
				if id, ok := sel.X.(*ast.Ident); ok && id.Name == name {
					found = true
				}
			}
		}
		return !found
	})
	return found
}

// callsRunner reports whether body calls one of the runners named, a
// generic one instantiated explicitly (forAll[int](…)) among them.
func callsRunner(body *ast.BlockStmt, names []string) bool {
	found := false
	ast.Inspect(body, func(n ast.Node) bool {
		if call, ok := n.(*ast.CallExpr); ok {
			fun := call.Fun
			switch x := fun.(type) {
			case *ast.IndexExpr:
				fun = x.X
			case *ast.IndexListExpr:
				fun = x.X
			}
			if id, ok := fun.(*ast.Ident); ok && slices.Contains(names, id.Name) {
				found = true
			}
		}
		return !found
	})
	return found
}
```

- It goes by the variable's name, so a table named otherwise passes unseen. Name every test's own table `tests`, or `cases` beside another, and the guard reads them all. A set of variants several tests share, at the package's level (`eras`, `modes`), keeps a name of its own: it's no test's table, and its rows run inside each test's.
- A `Run` counts only on a name the function, or any function literal in it, takes as a `*testing.T` or `*testing.B`, so a row that runs a binary (`exec.Command(…).Run()`), a CLI's entry (`cli.Run(…)`) or a fake runner (`f.Run(…)`) inline is flagged, and `b.Run` in a benchmark passes as `t.Run` does. It reads names alone, anywhere in the loop body, nested literals included: a fake or a variable of such a name passes, whether or not it shadows one, as does a literal holding `t.Run` that is stored and never run; review catches those.
- A runner is read by its plain name within its package, a generic one included, called bare or instantiated (`forAll[int](…)`). A runner it can't read by name is flagged, which fails loudly: a method or a method value, a closure in a variable, a runner exported from `export_test.go` to the `_test` package, one that wraps another; unless the row body itself calls `Run` on such a name, as a literal handed to it that runs `t.Run` inside does. A local closure bearing a runner's name passes; review catches that.
- It can't tell whether a runner names its row: one that runs `t.Run("old", …)` alone passes though its rows can't run alone. Review checks that a runner takes the row's name, as `forEachEra` does. A loop that calls `t.Run` for some rows and not others passes too: review catches that, and rule 3 of go-testing forbids the branch.

## Guard 5: what each build-tag set links

A module that ships a second build behind a tag (`//go:build demo`, a demo with a world of its own) holds which packages each build links. Syntax can't say it: an import three packages away pulls the demo into the shipped binary. `go list -deps` can:

```go
// internal/guard/builds_test.go

package guard_test

import (
	"os/exec"
	"slices"
	"strings"
	"testing"

	"example.com/shop/internal/guard"
)

// demoWorld is the demo's made-up shop, which only a -tags demo build links.
const demoWorld = "example.com/shop/internal/demoworld"

func TestTheShippedBinaryLinksNoDemo(t *testing.T) {
	if slices.Contains(linked(t, ""), demoWorld) {
		t.Errorf("cmd/shop links %s: only the -tags demo build may", demoWorld)
	}
}

func TestTheDemoBuildLinksItsWorld(t *testing.T) {
	if !slices.Contains(linked(t, "demo"), demoWorld) {
		t.Errorf("cmd/shop with -tags demo doesn't link %s, so the guard above proves nothing", demoWorld)
	}
}

// linked lists every package cmd/shop links when built with tags.
func linked(t *testing.T, tags string) []string {
	t.Helper()
	args := []string{"list", "-deps", "-tags", tags, "./cmd/shop"}
	cmd := exec.CommandContext(t.Context(), "go", args...)
	cmd.Dir = guard.ModuleRoot(t)
	out, err := cmd.Output()
	if err != nil {
		t.Fatalf("go %s: %v", strings.Join(args, " "), err)
	}
	return strings.Fields(string(out))
}
```

- **Each set is checked both ways:** the shipped build lacks the package, and the tagged one has it, so a renamed package can't leave the first test passing over nothing.
- **A tag for tests alone** (`integration`) needs no guard: it builds no binary.
- Like guard 3, it needs the `go` command when tests run.

## Guard 6: starting the binary runs nothing

The runner seam keeps every process the program starts in one place ([go-errors' shelling-out.md](../../go-errors/references/shelling-out.md)), but a dependency's `init` runs before `main` and outside it: a terminal-colour library that asks `tmux` what it supports starts a process on every run. Only running the built binary shows it:

```go
//go:build unix

// cmd/shop/startup_test.go

package main

import (
	"os"
	"os/exec"
	"path/filepath"
	"testing"
)

// started are the binaries the tool, or a library it links, could start.
var started = []string{"git", "gh", "tmux", "tput", "stty", "infocmp"}

func TestStartingTheBinaryRunsNothing(t *testing.T) {
	dir := t.TempDir()
	bin := filepath.Join(dir, "shop")
	if out, err := exec.CommandContext(t.Context(), "go", "build", "-o", bin, ".").CombinedOutput(); err != nil {
		t.Fatalf("go build: %v\n%s", err, out)
	}
	fakes, ran := filepath.Join(dir, "bin"), filepath.Join(dir, "ran")
	if err := os.Mkdir(fakes, 0o755); err != nil {
		t.Fatal(err)
	}
	for _, name := range started {
		script := "#!/bin/sh\necho \"$0 $*\" >> " + ran + "\n"
		if err := os.WriteFile(filepath.Join(fakes, name), []byte(script), 0o755); err != nil {
			t.Fatal(err)
		}
	}
	for _, args := range [][]string{{"--version"}, {"help", "cancel"}} {
		cmd := exec.CommandContext(t.Context(), bin, args...)
		cmd.Env = append(os.Environ(), "PATH="+fakes+string(os.PathListSeparator)+os.Getenv("PATH"), "TMUX=/tmp/tmux-0/default")
		if out, err := cmd.CombinedOutput(); err != nil {
			t.Fatalf("shop %v: %v\n%s", args, err, out)
		}
	}
	if b, err := os.ReadFile(ran); err == nil {
		t.Errorf("starting shop ran:\n%s", b)
	}
}
```

- **Each fake records being started** and does nothing else; a run that started none leaves no record.
- **The environment invites the start:** `TMUX` set, so a library that asks tmux only inside it is caught.
- **It can't see a start made only at a terminal.** `CombinedOutput` gives the binary pipes, and most detection runs only where stdout is a terminal. A guard over a terminal tool also runs it under a pseudo-terminal, from a package such as `github.com/creack/pty`, or with `TERM` set and the tool's own way to force its styled mode; without one, note the gap beside the test.
- **Run every build-tag set the module ships,** `go build -tags demo` too.

## The detection file

A terminal program asks once, at startup, whether its streams are terminals and what `TERM`, `NO_COLOR` and `CI` say ([go-concurrency's terminals.md](../../go-concurrency/references/terminals.md)). `run` hands that file the `*os.File` streams and `getenv`, as it hands everything else, so it names nothing forbidigo bans and needs no exclusion. A guard holds what no linter can: that it alone imports `golang.org/x/term` and asks a stream whether it is a terminal.

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
