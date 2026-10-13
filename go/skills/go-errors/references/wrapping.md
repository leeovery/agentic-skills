# Wrapping Errors

**An error message is a path from what was asked to what went wrong, and each layer adds one step of it.**

Contents: the shape · what the cause already says · where to wrap · wrap or translate · several failures · dropping an error.

## The shape

`"<operation> <input>: %w"`, lower case, with the cause last. The chain then reads left to right, newest to oldest:

```
cancel: order o-1: not found
load config /etc/shop/config.json: invalid character '}' looking for beginning of value
refund pay-9: Post "https://gateway.example/refunds": context deadline exceeded
```

| ❌ | ✅ | Why |
|---|---|---|
| `"failed to load config: %v"` | `"load config: %w"` | Every error is a failure, so the words add nothing; `%v` hides the cause from `errors.Is`. |
| `"Error opening ledger."` | `"open ledger: %w"` | It's the middle of a sentence: no capital, no full stop. |
| `"load config %s: open %s: %w"` | `"load config: %w"` | `os` already put the path in the cause. |
| `"could not decode: %w"` | `"decode order %s: %w"` | The decoder doesn't know which order it was reading. |

Acronyms and names keep their case inside the message: `"decode JSON body: %w"`.

## What the cause already says

Add only what's missing. Knowing what the standard library puts in its errors tells you what that is:

| Error from | Already says | You add |
|---|---|---|
| `os`, `io/fs` (`*fs.PathError`) | the operation and the path: `open /etc/shop/config.json: no such file or directory` | what you were doing: `load config: %w` |
| `encoding/json` | what was wrong, and a byte offset on `*json.SyntaxError`; never the file | the file or the record: `decode order %s: %w` |
| `strconv` | the function and the input: `strconv.Atoi: parsing "x": invalid syntax` | the field: `quantity on line %d: %w` |
| an `http.Client` call (`*url.Error`) | the method, the URL with its query, and the cause | the operation: `refund %s: %w` |
| an HTTP response | nothing: a 404 or a 503 is a successful `Do` | an error of your own, naming the status |
| `database/sql` | the driver's message; never your inputs | the id: `load order %s: %w` |

## Where to wrap

Wrap where the call leaves your code, and wherever an operation adds an input the cause lacks. A helper inside the package returns bare, because its caller already wraps:

```go
// Load reads the config at path. A missing file means the defaults.
func Load(path string) (Config, error) {
	b, err := os.ReadFile(path)
	if errors.Is(err, fs.ErrNotExist) {
		return Config{Currency: "GBP"}, nil
	}
	if err != nil {
		return Config{}, fmt.Errorf("load config: %w", err) // os names the path
	}
	cfg, err := parse(b)
	if err != nil {
		return Config{}, fmt.Errorf("load config %s: %w", path, err) // json doesn't
	}
	return cfg, nil
}

// parse is a helper inside the package: its caller adds the path, so it returns bare.
func parse(b []byte) (Config, error) {
	var cfg Config
	if err := json.Unmarshal(b, &cfg); err != nil {
		return Config{}, err
	}
	if err := cfg.validate(); err != nil {
		return Config{}, err
	}
	return cfg, nil
}
```

Wrapping in `parse` as well would print `load config /etc/shop/config.json: parse config: decode: invalid character…`, three phrases for one fact.

A missing file here is an expected outcome the function handles itself, so it matches and acts (the third way of handling once) and returns no error at all.

## Wrap or translate

- **Inside the module, wrap with `%w`.** Callers can match a cause from further down, and you own both ends of the contract.
- **At a boundary you own, translate what callers branch on.** A store, a client for an outside API and a protocol parser each turn the dependency's not-found into yours. The rest stays wrapped with `%w`, for the log.

```go
// Order is the order with id; one missing is order.ErrNotFound.
func (s *Store) Order(ctx context.Context, id order.ID) (order.Order, error) {
	o := order.Order{ID: id}
	err := s.db.QueryRowContext(ctx, `SELECT status FROM orders WHERE id = ?`, id).Scan(&o.Status)
	if errors.Is(err, sql.ErrNoRows) {
		return order.Order{}, fmt.Errorf("order %s: %w", id, order.ErrNotFound)
	}
	if err != nil {
		return order.Order{}, fmt.Errorf("load order %s: %w", id, err)
	}
	return o, nil
}
```

The same translation turns `fs.ErrNotExist` from a file store, or a 404 from an API, into `order.ErrNotFound`. Callers check one sentinel whatever the store is, and never import `database/sql`.

- **`%v` keeps a cause out of the contract.** Use it where a package's exported API wraps a dependency it may replace, so no caller can start matching the dependency's errors. Inside an application's `internal/` packages that's rare.

## Several failures

`errors.Join` returns every independent failure, and `errors.Is` still finds each:

```go
// ErrInvalid is returned, wrapped, for each setting a config gets wrong.
var ErrInvalid = errors.New("invalid config")

// validate reports every problem at once, each still matching ErrInvalid.
func (c Config) validate() error {
	var errs []error
	if len(c.Currency) != 3 || strings.ToUpper(c.Currency) != c.Currency {
		errs = append(errs, fmt.Errorf("currency %q: %w", c.Currency, ErrInvalid))
	}
	if c.Region == "" {
		errs = append(errs, fmt.Errorf("region missing: %w", ErrInvalid))
	}
	return errors.Join(errs...)
}
```

`errors.Join` of nothing is `nil`, so the success path needs no check. Its message puts each error on its own line, which suits a terminal; a log handler quotes it onto one.

Use it for failures that don't depend on each other: every bad field, every resource that failed to close. When the second step can't run without the first, return at the first.

## Dropping an error

`_ =` is the visible discard, and a comment beside it says why the drop is safe. errcheck reports an error dropped any other way:

```go
_ = os.Remove(tmp) // best effort: the write already failed, and that error is returned
```

`Close` is the common case, and it splits two ways:

- **On something you only read,** a file or a response body, its error has nothing to report: `defer func() { _ = resp.Body.Close() }()`. A bare `defer resp.Body.Close()` is reported.
- **On a file you wrote,** `Close` can be the only report that the data never reached the disk, so it joins the function's error:

```go
// Write writes the report to path.
func Write(path string, data []byte) (err error) {
	f, err := os.Create(path)
	if err != nil {
		return fmt.Errorf("write report: %w", err)
	}
	defer func() { err = errors.Join(err, f.Close()) }() // a failed Close can mean lost data
	if _, err := f.Write(data); err != nil {
		return fmt.Errorf("write report: %w", err)
	}
	return nil
}
```

golangci-lint's `std-error-handling` exclusion preset would silence every unchecked `Close` at once, the one that loses data with the rest, so [go-quality](../../go-quality/SKILL.md)'s config leaves it off.

A file that must never be seen half-written goes beside its target and is renamed over it; [go-actions](../../go-actions/SKILL.md) covers that write.
