# Terminals

**A program that drives a terminal asks once, in one file, what its streams are; reads the terminal through one goroutine that owns the read; draws under one lock that keeps the bytes and its record of them together; and puts the terminal back before any signal ends it.**

Contents: run's streams · detection in one file · a read that can't be cancelled · a writer and its record · signals while the mode is changed · checklist.

## run's streams

`run` usually takes `io.Writer`s ([go-architecture's layout.md](../../go-architecture/references/layout.md#main-and-run)), which is all a program that only writes needs. One that drives a terminal asks whether each stream is one, its size and its modes, and those need the file:

```go
func main() {
    os.Exit(run(context.Background(), os.Args[1:], os.Getenv, os.Stdin, os.Stdout, os.Stderr))
}

func run(ctx context.Context, args []string, getenv func(string) string, stdin, stdout, stderr *os.File) int
```

- **`getenv` stays:** `TERM`, `NO_COLOR` and `CI` are read through it, so a test sets them without touching the process.
- **A test passes files of its own,** an `os.Pipe` or a file under `t.TempDir()`, which are never terminals, so the program draws as it would in a pipe. A test of the drawn screen hands the renderer a size and a writer directly.

## Detection in one file

Whether a stream is a terminal, how wide it is and how many colours it takes are asked in one file, once, at startup, and everything after is handed the answers:

```go
// internal/render/startup.go: the one file that asks the terminal anything.

// modeOf is how the run draws: JSON where asked, styled at a terminal that can
// show it, plain everywhere else, CI logs among them.
func modeOf(stdout *os.File, getenv func(string) string, json bool) Mode {
    switch {
    case json:
        return ModeJSON
    case term.IsTerminal(int(stdout.Fd())) && getenv("TERM") != "" && getenv("TERM") != "dumb" && getenv("CI") == "":
        return ModeStyled
    }
    return ModePlain
}
```

- **It's the only importer of `golang.org/x/term`,** and the only reader of the streams' modes, held by a source guard, with forbidigo excluding this one file as it excludes `cmd/` ([go-quality's source-guards.md](../../go-quality/references/source-guards.md)).
- **Whether the program may ask** a question is decided here too: stdin and stdout both terminals. A command never looks at the terminal; it asks the renderer, which says it can't where nobody is there to answer.
- **Nothing it calls starts a process.** A library that runs `tmux` or `tput` to learn the terminal's colours does it outside the runner, so detection reads the environment and the terminal itself.

## A read that can't be cancelled

A blocked read of a terminal can't be interrupted from Go: no context reaches it, and closing stdin under it isn't safe. So one goroutine owns the read, at most one is ever outstanding, and an ask that gives up leaves it for the next ask to take:

```go
// lineReader reads the terminal a line at a time, for answers.
type lineReader struct {
    in      *bufio.Reader
    lines   chan lineRead // holds the outstanding read's result until an ask takes it
    reading bool          // a read is outstanding; only the asking goroutine reads or sets it
}

type lineRead struct {
    line string
    err  error
}

func newLineReader(stdin io.Reader) *lineReader {
    return &lineReader{in: bufio.NewReader(stdin), lines: make(chan lineRead, 1)}
}

// next is the next line typed, or ctx's error if it ends first. The read it
// started stays outstanding, and the next call takes its line.
func (r *lineReader) next(ctx context.Context) (string, error) {
    if !r.reading {
        r.reading = true
        // Its owner is the lineReader, and its end the read's: a line, the
        // terminal closing, or the process exiting. It never blocks on the send.
        go func() {
            line, err := r.in.ReadString('\n')
            r.lines <- lineRead{line, err}
        }()
    }
    select {
    case got := <-r.lines:
        r.reading = false
        return got.line, got.err
    case <-ctx.Done():
        return "", ctx.Err()
    }
}
```

- **It's the exception to "every goroutine has an owner that stops it":** nothing can stop it, so it's kept to one, its comment says the process's exit is its end, and a second is never started.
- **The channel holds one,** so the goroutine finishes its send and returns even when no ask is waiting.
- **A line left from an ask that gave up answers nothing:** before showing a new question, drop a result already waiting, and what was typed ahead, so a stray Enter never takes a default unseen.
- **Reads happen only while an answer is awaited,** so nothing else is reading the terminal when a child or another part of the program needs it.

## A writer and its record

A live view redraws in place: it moves the cursor back over the rows it drew last, then draws again. The bytes written and its record of them (how many rows, at what width) must change together, or a redraw erases rows it never drew. One lock guards both, and is held across the write:

```go
// screen redraws a region at the bottom of the terminal.
type screen struct {
    mu   sync.Mutex // guards out and rows: a draw writes and records under one hold
    out  io.Writer
    rows int // rows the last draw took, which the next moves back over
}

// draw replaces the region with lines.
func (s *screen) draw(lines []string) error {
    var b strings.Builder
    for _, l := range lines {
        b.WriteString(l)
        b.WriteByte('\n')
    }
    s.mu.Lock()
    defer s.mu.Unlock()
    back := ""
    if s.rows > 0 {
        back = fmt.Sprintf("\x1b[%dA\x1b[J", s.rows) // up over the last draw, then erase below
    }
    if _, err := io.WriteString(s.out, back+b.String()); err != nil {
        return err
    }
    s.rows = len(lines)
    return nil
}
```

- **It's the I/O exception [sync.md](sync.md#keeping-locks-off-io) allows,** widened to the record: nothing else goes under the lock, and what can be built first (the lines) is built before it's taken.
- **Every write to that stream goes through the same lock,** a warning or a question included, or one lands in the middle of a region still being redrawn.
- **The cost:** a terminal paused with Ctrl-S blocks the writer, and every caller waits behind it.
- **Each draw is one write,** so the terminal never shows a half-erased region.

## Signals while the mode is changed

Signals become cancellation once, in `run` ([context.md](context.md#signals)). Code that changes the terminal's mode (echo off to read a reply, raw for a full-screen view) has one more duty: the terminal must be put back however the process ends, or the person's shell is left without echo. So while the mode is changed, it watches the signals that would end the process itself:

```go
// withMode runs f with the terminal set by set, and puts it back however f
// ends. A Ctrl-C ends f through ctx, as anywhere else. A hang-up or a quit,
// which the program has no handler for, is held while the mode is changed,
// then delivered again once the terminal is back, so it takes its default
// action as if nothing had watched it.
func withMode(ctx context.Context, set func() (restore func() error, err error), f func(context.Context) error) error {
    held := make(chan os.Signal, 2) // one of each signal watched
    signal.Notify(held, syscall.SIGHUP, syscall.SIGQUIT)
    restore, err := set()
    if err != nil {
        signal.Stop(held)
        return err
    }
    err = f(ctx)
    err = errors.Join(err, restore())
    signal.Stop(held)
    select {
    case sig := <-held:
        if s, ok := sig.(syscall.Signal); ok {
            _ = syscall.Kill(os.Getpid(), s) // nothing watches it now, so the default action ends the process
        }
    default:
    }
    return err
}
```

- **Watch before changing the mode, restore before letting go,** so no signal lands while the terminal is changed and unwatched.
- **A signal the process was ignoring** (a hang-up under `nohup`) stays ignored: check `signal.Ignored` first, and leave it out of the watch.
- **The whole function is the exception to rule 5,** and its comment says so; nothing else but `run` watches signals.
- `syscall.Kill` is Unix's, so the file carries `//go:build unix`.

## Checklist

- [ ] `run` takes `*os.File` for its streams; `getenv` stays.
- [ ] One file asks the terminal anything, once, and runs no process to do it.
- [ ] One goroutine owns a terminal read, at most one outstanding, its result held in a channel of one.
- [ ] A redrawn view's writer and its record change under one lock, held for the write alone.
- [ ] Code that changes the terminal's mode watches the ending signals itself, restores, then delivers them again.
