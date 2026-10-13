# Test Isolation

**A test sees only what it was handed. Nothing reaches the real network, home directory, environment or state, and a project that must be sure has the machine check it.**

Contents: inject the outside world · the `TestMain` guard · the sandbox · never loosen a guard.

## Inject the outside world

Code that reaches for the machine can't be tested without touching it. Code that's handed the machine can be handed a fake one.

| Instead of reaching for | The code takes |
|---|---|
| `os.Getenv` | `getenv func(string) string` |
| `os.UserHomeDir`, or a path built in | the directory |
| `time.Now`, for a decision on the date | `now func() time.Time` |
| `http.DefaultClient`, or a URL constant | an `*http.Client` and a base URL |
| `exec.Command` | a runner: a function field, or an interface |
| `os.Stdout`, `os.Stderr` | an `io.Writer` |

`main` reads the real ones once and passes them down, as [go-architecture](../../go-architecture/SKILL.md) says. The test passes its own:

```go
// Load reads the settings file ORDERS_CONFIG names, or the one in home's
// config directory; with neither there, it returns the defaults.
func Load(getenv func(string) string, home string) (Settings, error) {
	path := getenv("ORDERS_CONFIG")
	if path == "" {
		path = filepath.Join(home, ".config", "orders", "settings.json")
	}
	data, err := os.ReadFile(path)
	if errors.Is(err, fs.ErrNotExist) {
		return Settings{Currency: "GBP"}, nil
	}
	if err != nil {
		return Settings{}, fmt.Errorf("load settings: %w", err)
	}
	var s Settings
	if err := json.Unmarshal(data, &s); err != nil {
		return Settings{}, fmt.Errorf("load settings %s: %w", path, err)
	}
	return s, nil
}
```

```go
func env(vars map[string]string) func(string) string {
	return func(name string) string { return vars[name] }
}

func TestLoadFallsBackToTheHomeConfig(t *testing.T) {
	home := t.TempDir()
	writeFile(t, filepath.Join(home, ".config", "orders", "settings.json"), `{"currency":"USD"}`)

	got, err := Load(env(nil), home)

	if err != nil {
		t.Fatalf("Load() error = %v", err)
	}
	if want := (Settings{Currency: "USD"}); got != want {
		t.Errorf("Load() = %+v, want %+v", got, want)
	}
}
```

`t.Setenv` is for code you can't change. It touches the whole process, so a test that uses it can't run in parallel, and the code it tests still reads the real environment the rest of the time.

## The `TestMain` guard

Injection is a convention; a guard makes breaking it fail the run. Every package with tests gets the same one-line `TestMain`:

```go
func TestMain(m *testing.M) { testguard.Main(m) }
```

When `TestMain` returns, the test binary exits with the code `m.Run` returned. The guard exits non-zero itself only when it fails a run its tests passed.

```go
// Main gives the tests a throwaway home, none of the app's variables and a
// network of loopback alone, and runs them. It fails the run if a dial was
// blocked or the real settings file changed, even when every test passed;
// otherwise the run ends as its tests did.
func Main(m *testing.M) {
	if err := guard(m); err != nil {
		fmt.Fprintln(os.Stderr, "testguard:", err)
		os.Exit(1)
	}
}

func guard(m *testing.M) error {
	realHome, err := os.UserHomeDir()
	if err != nil {
		return err
	}
	settings := filepath.Join(realHome, ".config", "orders", "settings.json")
	before := fingerprint(settings)

	root, err := os.MkdirTemp("", "testguard-")
	if err != nil {
		return err
	}
	defer func() { _ = os.RemoveAll(root) }() // a temporary directory left behind does no harm
	if err := isolate(root); err != nil {
		return err
	}
	transport, ok := http.DefaultTransport.(*http.Transport)
	if !ok {
		return errors.New("http.DefaultTransport isn't an *http.Transport")
	}
	dial, blocked := loopbackOnly()
	transport.DialContext = dial
	transport.Proxy = nil // a proxy on loopback would carry the request off the machine

	m.Run() // its code is the run's, as TestMain returns

	var errs []error
	if n := blocked.Load(); n > 0 {
		errs = append(errs, fmt.Errorf("%d dials off loopback were blocked", n))
	}
	if fingerprint(settings) != before {
		errs = append(errs, fmt.Errorf("the tests changed %s", settings))
	}
	return errors.Join(errs...)
}

// isolate points the home and config directories into root, and clears the
// app's own variables and the proxy settings a program the tests run would follow.
func isolate(root string) error {
	for _, kv := range os.Environ() {
		if name, _, _ := strings.Cut(kv, "="); strings.HasPrefix(name, "ORDERS_") || isProxy(name) {
			if err := os.Unsetenv(name); err != nil {
				return fmt.Errorf("unset %s: %w", name, err)
			}
		}
	}
	return errors.Join(
		os.Setenv("HOME", root),
		os.Setenv("XDG_CONFIG_HOME", filepath.Join(root, ".config")),
	)
}

// isProxy reports whether name is a proxy setting, in either case.
func isProxy(name string) bool {
	switch strings.ToUpper(name) {
	case "HTTP_PROXY", "HTTPS_PROXY", "ALL_PROXY", "NO_PROXY":
		return true
	}
	return false
}

func loopbackOnly() (func(context.Context, string, string) (net.Conn, error), *atomic.Int32) {
	blocked := new(atomic.Int32)
	var d net.Dialer
	return func(ctx context.Context, network, addr string) (net.Conn, error) {
		host, _, err := net.SplitHostPort(addr)
		if err != nil {
			return nil, fmt.Errorf("testguard: dial %s: %w", addr, err)
		}
		if ip := net.ParseIP(host); host != "localhost" && (ip == nil || !ip.IsLoopback()) {
			blocked.Add(1)
			return nil, fmt.Errorf("testguard: dial %s blocked: tests stay on loopback", addr)
		}
		return d.DialContext(ctx, network, addr)
	}, blocked
}

func fingerprint(path string) string {
	data, err := os.ReadFile(path)
	if errors.Is(err, fs.ErrNotExist) {
		return "absent"
	}
	if err != nil {
		return "unreadable: " + err.Error()
	}
	sum := sha256.Sum256(data)
	return hex.EncodeToString(sum[:])
}
```

- **A blocked dial fails the run even when the test passed:** the code under test may have swallowed the error, and the test would pass while still reaching for the network.
- **Watch what the program writes for real:** its config, its state directory, anything it installs. A file that appeared or changed during the run is a test that escaped.
- **It sees only `http.DefaultTransport`** and transports cloned from it after `Main` ran. A transport built from scratch dials past it, so code clones the default rather than writing `&http.Transport{…}`.
- **It turns proxies off,** in the transport and in the environment. With `HTTPS_PROXY` set to a proxy on loopback, as a sandbox or a CI runner behind a proxy has, the transport dials only the proxy, the guard allows that, and the proxy carries the request off the machine. Clearing the variables keeps a program the tests run from following them too.
- **Programs the code runs** can be guarded the same way: a directory of stubs first on `PATH`, each recording that it ran, and the guard failing the run if any did.
- **Hold the guard in place with a source guard:** a test that parses the module and fails if a package with tests lacks this `TestMain`, or if anything outside `testguard` changes the environment. [go-quality](../../go-quality/SKILL.md) covers writing one.
- **The guard is the one package that reads, changes and exits the process,** so the lint rules that forbid that elsewhere exclude its path, and nothing else's:

  ```yaml
  linters:
    exclusions:
      rules:
        - path: internal/testguard/
          linters: [forbidigo]
  ```

## The sandbox

The guard sees what goes through Go's own APIs. A subprocess, a library that dials for itself, or a test that builds its own transport goes past it. An operating-system sandbox around the test run catches those too.

On macOS, `sandbox-exec` with a profile:

```scheme
;; go test under macOS's sandbox: loopback only, and no writes into the home
;; directory but Go's caches. Every parameter is a path with its links resolved.
(version 1)
(allow default)

(deny network-outbound)
(allow network-outbound
  (remote ip "localhost:*")
  (remote unix-socket (subpath (param "TMPDIR"))))

(deny file-write* (subpath (param "HOME")))
(allow file-write* (subpath (param "GOCACHE")) (subpath (param "GOMODCACHE")))
```

```sh
sandbox-exec -f scripts/tests.sb \
  -D HOME="$(realpath ~)" \
  -D TMPDIR="$(realpath "${TMPDIR:-/tmp}")" \
  -D GOCACHE="$(realpath "$(go env GOCACHE)")" \
  -D GOMODCACHE="$(realpath "$(go env GOMODCACHE)")" \
  go test -race ./...
```

- **Resolve every path.** The sandbox matches real paths, and `/var` and `/tmp` are links on macOS: an unresolved `TMPDIR` matches nothing.
- **Add a deny for each real file the program owns** outside the home directory: its config or state wherever the environment puts them.
- **Prove it holds before every run.** A sandbox that has silently stopped applying looks exactly like one that works. Before the tests, the script runs a probe inside the sandbox that writes into the home directory and dials off the machine, and stops unless both are refused.
- **Clear the program's variables before the sandbox starts,** not only in the guard: the guard runs after every package's initialisation, which may already have read them.
- **On Linux,** a network namespace with only its loopback up gives the same network rule, and a read-only mount of the home directory the same write rule.

Make the sandboxed run the project's test gate, the one command that runs before work is called done.

## Never loosen a guard

A test that needs what a guard blocks has found a missing seam. Inject what it needs instead: a fake server, a temp directory, a `getenv`. Widening the guard to let one test through lets every later test through too.
