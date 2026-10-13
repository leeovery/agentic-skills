# Logging

**A logger is a dependency like any other: built once in `main`, passed in, and replaced in tests.**

Contents: injecting it · wiring it in main · where to log · levels · attributes · secrets · testing what was logged.

## Injecting it

A component that logs takes a `*slog.Logger` with its other collaborators:

```go
// Config configures a Settler.
type Config struct {
	Invoices invoices
	Charger  charger
	Logger   *slog.Logger // nil discards
}

// Settler charges the invoices left unpaid.
type Settler struct {
	invoices invoices
	charger  charger
	log      *slog.Logger
}

// NewSettler builds a Settler from cfg.
func NewSettler(cfg Config) *Settler {
	log := cfg.Logger
	if log == nil {
		log = slog.New(slog.DiscardHandler)
	}
	return &Settler{
		invoices: cfg.Invoices,
		charger:  cfg.Charger,
		log:      log.With("component", "settler"),
	}
}
```

- **A nil logger discards.** The zero `Config` stays usable, as every `Config`'s zero fields mean defaults ([go-types](../../go-types/SKILL.md)), so a test that doesn't care about logs passes none. `slog.DiscardHandler` is [Go 1.24](https://go.dev/doc/go1.24)'s.
- **`With` once, in the constructor,** for what every line of the component carries. Inside a call that logs several lines about one order, `log := s.log.With("order", id)`.
- **No package-level logger,** no `slog.Info`, and no `slog.Default()` below `main`. A global is shared state: a test can't capture one component's lines, and tests that swap it can't run in parallel. sloglint's `no-global: "all"` reports a package-level logger and the `slog.Info`-style calls.
- **Not in the context either.** A logger fished out of a `context.Context` is a dependency nobody can see in a signature.

## Wiring it in main

`run`, which `main` hands the process to, builds the one logger and hands it, or a `With` of it, to each constructor:

```go
func run(ctx context.Context, args []string, getenv func(string) string, stdout, stderr io.Writer) int {
	ctx, stop := signal.NotifyContext(ctx, os.Interrupt)
	defer stop()

	var level slog.LevelVar
	log := slog.New(slog.NewTextHandler(stderr, &slog.HandlerOptions{Level: &level}))
	if err := level.UnmarshalText([]byte(cmp.Or(getenv("SHOP_LOG_LEVEL"), "INFO"))); err != nil {
		log.Error("read SHOP_LOG_LEVEL", "err", err)
		return 1
	}
	slog.SetDefault(log) // a dependency's stray log.Printf lands in the same place

	store, err := filestore.Open(getenv("SHOP_DATA_DIR"))
	if err != nil {
		log.Error("open store", "err", err)
		return 1
	}
	payments := &gateway.Client{HTTP: &http.Client{}, BaseURL: getenv("SHOP_GATEWAY_URL"), Timeout: 10 * time.Second}
	settler := billing.NewSettler(billing.Config{Invoices: store, Charger: payments, Logger: log})

	root := cli.NewRootCommand(cli.Deps{Settler: settler, Out: stdout}) //nolint:contextcheck // NewRootCommand takes no context; the command gets ctx from ExecuteContext
	root.SetErr(stderr)
	return cli.Execute(ctx, root, args)
}
```

The logger comes first, so even a bad `SHOP_LOG_LEVEL` has somewhere to be reported: a `LevelVar` takes its level after the handler is built.

The store and the gateway's `Client{HTTP, BaseURL, Timeout}` are built as [go-architecture](../../go-architecture/SKILL.md)'s `run` builds them, and the `Settler` takes the logger beside them. The `Client` here has a `Charge` method beside the `Refund` that [go-interfaces' services.md](../../go-interfaces/references/services.md) shows. `Deps` holds a `Settler` for the `settle` command, as [go-actions' cli.md](../../go-actions/references/cli.md) has it hold a `Canceller` for `cancel`.

`slog.SetDefault` also routes the old `log` package through the handler, which catches dependencies that print. It's set here and read nowhere.

## Where to log

Where an error stops, and nowhere it passes through:

- **An edge:** the HTTP edge's `writeError` ([matching.md](matching.md)), or `run`, for a setup failure, before it returns 1. A cobra command's error is printed by cobra, so nothing logs it as well.
- **A worker that carries on:** a failure it skips and retries later is logged at the point it's skipped.
- **Not in code that returns the error.** Its caller logs it, or matches it, or returns it in turn.

```go
// Settle charges every unpaid invoice. A charge that fails is logged and left
// for the next pass; only a failure that stops the pass is returned.
func (s *Settler) Settle(ctx context.Context) error {
	due, err := s.invoices.Unpaid(ctx)
	if err != nil {
		return fmt.Errorf("settle: %w", err)
	}
	for _, inv := range due {
		err := s.charger.Charge(ctx, inv.ID, inv.Amount)
		switch {
		case err == nil:
			s.log.InfoContext(ctx, "invoice charged", "invoice", inv.ID, "amount", inv.Amount)
		case errors.Is(err, ErrDeclined):
			s.log.InfoContext(ctx, "charge declined", "invoice", inv.ID)
		default:
			s.log.WarnContext(ctx, "charge failed, retrying next pass", "invoice", inv.ID, "err", err)
		}
	}
	return nil
}
```

## Levels

| Level | For | Such as |
|---|---|---|
| `Error` | a failure a person must act on | a 500, data that couldn't be saved, a job failing every pass for an hour |
| `Warn` | degraded, carrying on | a charge that will be retried, a stale cache served |
| `Info` | what happened, in normal running | started, listening, an invoice charged, a card declined |
| `Debug` | detail for diagnosis, off by default | a request's timing, a cache hit |

A declined card, a 404, a failed validation and a cancelled request are outcomes, not failures. Logged at `Error`, they bury the lines that need someone.

## Attributes

- **The message is a constant naming the event,** and the values follow as key-value pairs: `"charge failed", "invoice", inv.ID, "err", err`. Every line for one event then shares a message to search for. sloglint's `static-msg` reports a message built with `fmt.Sprintf` or `+`, and its `kv-only` a `slog.String(…)` among the pairs, so there's one style to read.
- **The error goes under `err`,** on every line that has one.
- **One key per thing, everywhere:** `invoice` in every package, not `invoice` here and `invoiceID` there.
- **The `…Context` methods** (`InfoContext`, `WarnContext`) pass the context to the handler, which can add a request's trace id from it. Use them where a `ctx` is in scope.
- `go vet` reports a key with no value.

## Secrets

- **A secret is a type that redacts itself,** so it's safe in any attribute and any `%v` ([go-types](../../go-types/SKILL.md)):

  ```go
  // APIKey is a credential. Every way of printing it prints the redaction.
  type APIKey string

  func (APIKey) String() string { return "[redacted]" }

  // GoString redacts the key from %#v.
  func (APIKey) GoString() string { return "[redacted]" }

  // LogValue redacts the key from a log line.
  func (APIKey) LogValue() slog.Value { return slog.StringValue("[redacted]") }
  ```

- **Never log a whole request,** a header map, a config struct or the environment. Log the fields you need.
- **An `http.Client` error carries the URL, query and all.** The client masks a password in the URL's user info but prints `?api_key=…` as it stands, and that error reaches the log through every wrap. Send credentials in a header; where an API insists on the query, the adapter strips it from the error before returning.

## Testing what was logged

Most tests pass no logger at all. When a line is the behaviour under test, such as a failure logged and carried past, log JSON into a buffer and check the attributes, not the formatted text:

```go
func TestSettleLogsAFailedChargeAndCarriesOn(t *testing.T) {
	var buf bytes.Buffer
	s := NewSettler(Config{
		Invoices: &fakeInvoices{unpaid: []Invoice{{ID: "inv-1", Amount: 1250}}},
		Charger:  &fakeCharger{errs: map[InvoiceID]error{"inv-1": fmt.Errorf("charge inv-1: timeout")}},
		Logger:   slog.New(slog.NewJSONHandler(&buf, nil)),
	})

	if err := s.Settle(t.Context()); err != nil {
		t.Fatalf("Settle() = %v, want nil", err)
	}

	lines := logLines(t, &buf)
	if len(lines) != 1 {
		t.Fatalf("logged %d lines, want 1:\n%s", len(lines), buf.String())
	}
	got := lines[0]
	if got["level"] != "WARN" || got["invoice"] != "inv-1" || got["err"] == nil {
		t.Errorf("log line = %v, want level WARN, invoice inv-1 and an err", got)
	}
}

// logLines decodes a JSON handler's output, one record per line.
func logLines(t *testing.T, buf *bytes.Buffer) []map[string]any {
	t.Helper()
	var lines []map[string]any
	for line := range bytes.Lines(buf.Bytes()) {
		var rec map[string]any
		if err := json.Unmarshal(line, &rec); err != nil {
			t.Fatalf("log line %q: %v", line, err)
		}
		lines = append(lines, rec)
	}
	return lines
}
```

- `bytes.Lines` is [Go 1.24](https://go.dev/doc/go1.24)'s.
- Read the buffer once the work has stopped. The handler serialises its own writes, but a read while another goroutine logs is a race.
