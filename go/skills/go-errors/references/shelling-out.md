# Shelling Out

**A tool that runs other binaries runs them through one runner, reads each binary's failures in that binary's own adapter, and tests the adapters against a fake runner seeded with what the real binary printed.**

Contents: one runner seam · an adapter per binary · reading stderr into sentinels · the fake runner · the integration suite · checklist.

## One runner seam

Every process the program starts goes through one interface, and one package implements it over `os/exec`:

```go
// Package runner is the only code that starts a process.
package runner

// Command is one run of a binary.
type Command struct {
	Dir   string   // where it runs; "" for the program's own directory
	Env   []string // added to the program's environment
	Stdin []byte   // piped afresh on every run, never an io.Reader a retry would find drained
	Name  string
	Args  []string
}

// Result is what a run printed.
type Result struct {
	Stdout, Stderr []byte
}

// Runner runs commands.
type Runner interface {
	Run(ctx context.Context, cmd Command) (Result, error)
}
```

- **No other non-test code imports `os/exec`.** The drop-in's depguard bans it only in the domain packages it names; holding it module-wide takes a depguard rule over `$all` that allows the runner's package and `_test.go` files, or a source guard ([go-quality](../../go-quality/SKILL.md)), so no call slips past the seam.
- **It takes the place of [go-interfaces' seams.md](../../go-interfaces/references/seams.md#processes)'s two-method runner** once a tool runs more than one binary: one `Run` carries each binary's directory, environment and stdin alike.
- **The real runner** uses `exec.CommandContext`, captures both streams, and returns the exit as an error with `os/exec`'s kept in its chain. `run` builds it once and hands it to every adapter.
- **A child never gets the terminal.** Its output is captured and its stdin is what the caller pipes, so a binary that prompts can't hang the run waiting on a person.

## An adapter per binary

Each binary has one package that runs it, the only place its name appears: `git` in `internal/git`, `gh` in `internal/github`. It builds the argv, pins what the binary reads from its surroundings, and turns the output into domain values:

- **Pin the environment that shapes the output:** `LC_ALL=C`, so the stderr it reads is English whatever the person's language; the binary's own "never prompt" setting (`GIT_TERMINAL_PROMPT=0`, `GH_PROMPT_DISABLED=1`).
- **Every call goes through one function in the adapter,** which runs the command and reads its failure, so the pinning and the reading can't differ between calls.
- **Callers see the adapter's types and sentinels,** never a `runner.Result`.

## Reading stderr into sentinels

A binary has no error types; its failures are an exit code and some text. Reading that text inside its adapter is the boundary translation of the [SKILL.md](../SKILL.md)'s rule 6, not matching a message: the adapter turns it into the sentinels callers branch on, and no code past the adapter reads text.

```go
// ErrNotLoggedIn is returned, wrapped, when gh has no login.
var ErrNotLoggedIn = errors.New("gh is not logged in")

// Error is a gh call that failed: what gh said, and the run's error.
type Error struct {
	Stderr string
	Err    error
	class  error // the sentinel the stderr was read as, or nil
}

func (e *Error) Error() string { return strings.TrimSpace(e.Stderr) }

// Unwrap gives the class the stderr was read as, and the run's own error.
func (e *Error) Unwrap() []error {
	if e.class == nil {
		return []error{e.Err}
	}
	return []error{e.class, e.Err}
}

// failed reads gh's stderr into the class a caller branches on.
func failed(res runner.Result, err error) *Error {
	e := &Error{Stderr: string(res.Stderr), Err: err}
	switch {
	case bytes.Contains(res.Stderr, []byte("gh auth login")):
		e.class = ErrNotLoggedIn
	case bytes.Contains(res.Stderr, []byte("(HTTP 404)")):
		e.class = ErrNotFound
	}
	return e
}
```

- **Each phrase it reads is pinned by a sample** of the real binary's stderr in `testdata/`, captured from a real run, with the version and exit code noted beside it.
- **A binary missing from `PATH`** is its own sentinel, with the fix that installs it.
- **A context that ended** is returned as `ctx`'s error, joined with what the binary said, so a Ctrl-C reads as an interrupt and not as the binary's failure.
- The error carries several identities ([matching.md](matching.md#several-identities)).

## The fake runner

For an adapter over a binary, a fake runner stands where `httptest` stands for an HTTP client ([go-interfaces' services.md](../../go-interfaces/references/services.md)): the adapter runs for real, against answers the test seeded.

```go
func TestPullRequestsNeedsALogin(t *testing.T) {
	fake := runnertest.New()
	fake.Seed("gh api repos/acme/shop/pulls", runner.Result{Stderr: testdata(t, "not-logged-in.stderr")}, exitCode(4))
	c := github.New(fake)

	_, err := c.PullRequests(t.Context(), "acme/shop")

	if !errors.Is(err, github.ErrNotLoggedIn) {
		t.Errorf("PullRequests(acme/shop) = %v, want ErrNotLoggedIn", err)
	}
}
```

`testdata` reads a file under `testdata/`, and `exitCode` builds the error a run that exited with that code returns.

- **Seeded by argv,** and recording each call, so a test checks the exact command the adapter built as well as what it made of the answer.
- **Answers are trimmed from real ones** in `testdata/`, never written from memory of what the binary prints.
- **It honours `ctx`:** a call made once the context has ended returns its error and consumes nothing seeded.
- **It lives in a `runnertest` package** that every adapter's tests share ([go-interfaces](../../go-interfaces/SKILL.md)).
- The default suite never runs the real binary.

## The integration suite

The fakes assume the binary's output has a shape: the porcelain format, the exit code of a conflict, the phrase a refusal uses. An opt-in suite pins those assumptions against the real binary, in temp directories:

```go
//go:build integration

package git_test

func TestMergeTreeExitsOneOnAConflict(t *testing.T) { /* a real repo under t.TempDir() */ }
```

- **Run by a build tag,** `go test -tags integration ./internal/git/`, so the default suite stays fast and needs no binary installed.
- **It never touches the person's own setup:** its home, config and credentials are temp ones of its own.
- **A new shape a fake assumes gets an integration test** in the same change, so a binary upgrade that moves it fails here, not in production.

## Checklist

- [ ] One runner interface; nothing else imports `os/exec`.
- [ ] One adapter per binary, the only place its name appears, its environment pinned.
- [ ] Stderr read into sentinels inside the adapter alone, each phrase pinned by a captured sample.
- [ ] A fake runner seeded by argv with real output, honouring `ctx`.
- [ ] An opt-in integration suite pinning each shape the fakes assume.
