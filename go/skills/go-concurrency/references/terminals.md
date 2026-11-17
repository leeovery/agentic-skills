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
- **A `synctest` test of what reads the terminal hands it an `io.Pipe`,** which blocks on channels made in the bubble. An `os.Pipe` read is real I/O, so the bubble's clock never moves past it; it serves only the `*os.File` detection path.

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

- **It's the only importer of `golang.org/x/term`,** and the only reader of the streams' modes, held by a source guard ([go-quality's source-guards.md](../../go-quality/references/source-guards.md)). It reads nothing forbidigo bans: `run` hands it the streams and `getenv`, so it needs no exclusion.
- **Whether the program may ask** a question is decided here too: stdin and stdout both terminals. A command never looks at the terminal; it asks the renderer, which says it can't where nobody is there to answer.
- **Nothing it calls starts a process.** A library that runs `tmux` or `tput` to learn the terminal's colours does it outside the runner, so detection reads the environment and the terminal itself.

## A read that can't be cancelled

A blocked read of a terminal can't be interrupted from Go: no context reaches it, and closing stdin under it isn't safe. So one goroutine owns the read, at most one is ever outstanding, and an ask that gives up leaves it for the next ask to take:

```go
// lineReader reads the terminal a line at a time, for answers.
type lineReader struct {
	src     io.Reader // the terminal
	in      *bufio.Reader
	lines   chan lineRead // holds the outstanding read's result until an ask takes it
	reading bool          // a read is outstanding; only the asking goroutine reads or sets it
}

type lineRead struct {
	line string
	err  error
}

func newLineReader(stdin io.Reader) *lineReader {
	return &lineReader{src: stdin, in: bufio.NewReader(stdin), lines: make(chan lineRead, 1)}
}

// ready is called once each question is shown. It drops a line read before
// it, left by an ask that gave up, and discards what was typed ahead, so only
// a line typed after the question answers it. discard flushes the terminal's
// input queue (tcflush), from the detection file.
func (r *lineReader) ready(discard func() error) error {
	if r.reading {
		select {
		case <-r.lines:
			r.reading = false
		default: // still reading: its line will be one typed from now on
		}
	}
	if !r.reading {
		r.in.Reset(r.src) // what the buffer read ahead was typed before the question
	}
	return discard()
}

// next is the next line typed since ready, or ctx's error if ctx ends first.
// A read ctx cut short stays outstanding: the next ready drops its line if it
// came before that question, and the next call takes it if it came after.
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
- **A line left from an ask that gave up answers nothing:** `ready` drops it, and what was typed ahead, once the new question shows, so a stray Enter pressed while a step ran never takes a default unseen.
- **The buffer is reset only with no read outstanding:** while one is, its goroutine owns `in`.
- **Reads happen only while an answer is awaited,** so nothing else is reading the terminal when a child or another part of the program needs it.

## A writer and its record

A live view redraws in place: it moves the cursor back over the rows it drew last, then draws again. The bytes written and its record of them (how many rows they took at the terminal's width) must change together, or a redraw erases rows it never drew. One lock guards both, and is held across the write:

```go
// screen redraws a region at the bottom of the terminal.
type screen struct {
	mu    sync.Mutex // guards out, width and rows: a draw writes and records under one hold
	out   io.Writer
	width int // the terminal's columns, as last read
	rows  int // rows the last draw took, which the next moves back over
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
	s.rows = 0
	for _, l := range lines {
		s.rows += max(1, (cells(l)+s.width-1)/s.width) // a line wider than the terminal wraps onto more
	}
	return nil
}
```

- **It's the I/O exception [sync.md](sync.md#keeping-locks-off-io) allows,** widened to the record: nothing else goes under the lock, and what can be built first (the lines) is built before it's taken.
- **Every write to that stream goes through the same lock,** a warning or a question included, or one lands in the middle of a region still being redrawn.
- **The cost:** a terminal paused with Ctrl-S blocks the writer, and every caller waits behind it.
- **Each draw is one write,** so the terminal never shows a half-erased region.
- **Rows are counted at the terminal's width,** `cells` measuring what a line shows (a wide character two columns, an escape none), so a line that wraps is moved back over whole. A resize changes `width` under the same lock.

## Signals while the mode is changed

Signals become cancellation once, in `run` ([context.md](context.md#signals)). Code that changes the terminal's mode (echo off to read a reply, raw for a full-screen view) has one more duty: the terminal must be put back however the process ends, or the person's shell is left without echo. So while the mode is changed, it watches the signals that would end the process and that nothing else handles:

```go
// withMode runs f with the terminal set by set, and puts it back however f
// ends. A Ctrl-C reaches f as run's cancellation, or, in raw mode, as a key
// f reads. A hang-up or a quit, which the program has no handler for, cancels
// f's ctx while the mode is changed, and is delivered again once the terminal
// is back, so it takes its default action as if nothing had watched it. A
// signal the process was started ignoring, as nohup leaves a hang-up, is left
// out of the watch, so it stays ignored.
func withMode(ctx context.Context, set func() (restore func() error, err error), f func(context.Context) error) error {
	held := make(chan os.Signal, 2) // one of each signal watched
	if watched := notIgnored(syscall.SIGHUP, syscall.SIGQUIT); len(watched) > 0 {
		signal.Notify(held, watched...) // never with none: Notify of no signals relays them all
	}
	restore, err := set()
	if err != nil {
		signal.Stop(held)
		return err
	}
	fctx, cancel := context.WithCancel(ctx)
	var caught os.Signal
	var wg sync.WaitGroup
	wg.Go(func() {
		select {
		case caught = <-held:
			cancel()
		case <-fctx.Done():
		}
	})
	err = f(fctx)
	cancel()
	wg.Wait()
	err = errors.Join(err, restore())
	signal.Stop(held)
	if caught == nil {
		select {
		case caught = <-held: // one that came as f returned
		default:
		}
	}
	if s, ok := caught.(syscall.Signal); ok {
		_ = syscall.Kill(os.Getpid(), s) // nothing watches it now, so its default action ends the process
	}
	return err
}

// notIgnored are those of sigs the process isn't ignoring.
func notIgnored(sigs ...os.Signal) []os.Signal {
	var out []os.Signal
	for _, s := range sigs {
		if !signal.Ignored(s) {
			out = append(out, s)
		}
	}
	return out
}
```

- **Watch before changing the mode, restore before letting go,** so no signal lands while the terminal is changed and unwatched.
- **A held signal cancels `f`,** so `f` must return promptly once its ctx ends: after a hang-up the terminal is gone, and a read loop that waits on it would never end.
- **A signal the process was ignoring stays ignored:** `signal.Notify` would install a handler over the ignore, and the re-raise would then kill a `nohup`'d process.
- **The whole function is the exception to rule 5,** and its comment says so; nothing else but `run` watches signals.
- `syscall.Kill` is Unix's, so the file carries `//go:build unix`.

## Checklist

- [ ] `run` takes `*os.File` for its streams; `getenv` stays.
- [ ] One file asks the terminal anything, once, and runs no process to do it.
- [ ] One goroutine owns a terminal read, at most one outstanding, its result held in a channel of one; each question drops what came before it.
- [ ] A redrawn view's writer and its record change under one lock, held for the write alone.
- [ ] Code that changes the terminal's mode watches the ending signals it isn't ignoring, cancels on one, restores, then delivers it again.
