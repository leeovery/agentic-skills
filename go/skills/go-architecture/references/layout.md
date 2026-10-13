# Layout

**An application is `cmd/<name>` for `main`, and `internal/` for everything else, one package per job.**

Contents: the tree · main and run · naming · package docs · files · import cycles · init and package state.

## The tree

```
shop/
├── go.mod
├── cmd/
│   └── shop/
│       ├── main.go        main and run: read the process, build the dependencies
│       └── main_test.go   run, called with buffers and a fake environment
└── internal/
    ├── order/             orders: the type, its statuses, cancelling and refunding
    ├── customer/
    ├── payment/           taking payments; the provider sits behind an interface
    ├── filestore/         orders kept as files
    ├── format/            how durations, dates, money and ratios read to people
    ├── cli/               commands: parse, call one operation, write the result
    ├── server/            HTTP handlers: the same, over HTTP
    ├── redact/            one job: strip secrets from text
    └── atomicfile/        one job: write a file whole or not at all
```

- **An application** keeps everything but `main` in `internal/`, where no other module can import it. **A library** puts its package at the module root.
- **No `pkg/` or `src/`.** `internal/` already says what's private.
- **Domain packages** (`order`, `customer`, `payment`) hold a type, its rules and its operations together. **Edges** (`cli`, `server`, a worker) import them; a domain package never imports an edge.
- **Storage** gets a package named for how it stores (`filestore`, `pgstore`), implementing the interfaces its consumers declare ([go-interfaces](../../go-interfaces/SKILL.md)).

## main and run

```go
// cmd/shop/main.go

// Shop takes, cancels and refunds orders.
package main

import (
	"context"
	"io"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"time"

	"example.com/shop/internal/cli"
	"example.com/shop/internal/filestore"
	"example.com/shop/internal/gateway"
	"example.com/shop/internal/order"
)

func main() { os.Exit(run(context.Background(), os.Args[1:], os.Getenv, os.Stdout, os.Stderr)) }

func run(ctx context.Context, args []string, getenv func(string) string, stdout, stderr io.Writer) int {
	ctx, stop := signal.NotifyContext(ctx, os.Interrupt)
	defer stop()

	log := slog.New(slog.NewTextHandler(stderr, nil))
	store, err := filestore.Open(getenv("SHOP_DATA_DIR"))
	if err != nil {
		log.Error("open store", "err", err)
		return 1
	}
	payments := &gateway.Client{HTTP: &http.Client{}, BaseURL: getenv("SHOP_GATEWAY_URL"), Timeout: 10 * time.Second}
	deps := cli.Deps{
		Canceller: order.Canceller{Orders: store, Payments: payments, Now: time.Now},
		Out:       stdout,
	}
	root := cli.NewRootCommand(deps) //nolint:contextcheck // NewRootCommand takes no context; the command gets ctx from ExecuteContext
	root.SetErr(stderr)
	return cli.Execute(ctx, root, args)
}
```

```go
// cmd/shop/main_test.go

package main

import (
	"bytes"
	"strings"
	"testing"
)

func TestRunRefusesAnUnknownCommand(t *testing.T) {
	dir := t.TempDir()
	getenv := func(key string) string {
		if key == "SHOP_DATA_DIR" {
			return dir
		}
		return ""
	}
	var stdout, stderr bytes.Buffer

	code := run(t.Context(), []string{"frobnicate"}, getenv, &stdout, &stderr)

	if code != 2 || !strings.Contains(stderr.String(), "frobnicate") {
		t.Errorf("run(frobnicate) = %d, stderr %q; want 2 and an error naming the command", code, stderr.String())
	}
}
```

- **`main` exits with what `run` returns,** and does nothing else, so there is nothing in it to test. `os.Exit` skips deferred calls, so `main` holds none.
- **`run` owns setup:** signals become cancellation, the logger is built, stores open, implementations are picked by config, and all of it is handed to the edge. A setup failure is printed once, to `stderr`, and returns 1. Either way `run` returns, so its defers run.
- **The other codes come from the edge.** `cli.Execute` sets 0, 1, 2 for a usage error and 130 for an interrupt, and leaves printing a command's error to cobra, so nothing prints it twice ([go-actions' cli.md](../../go-actions/references/cli.md)).
- **`run` sets cobra's error writer, never its output writer.** Data goes to the writer in `cli.Deps`; with `root.SetOut(stdout)`, a bad command line would print its usage into the pipe.
- **A test calls `run`** with `t.Context()` ([Go 1.24](https://go.dev/doc/go1.24)), a `getenv` closure and buffers, and checks the code it returns, without touching the real environment.

## Naming

- A package name is short, lower case, one word, and the last element of its path: `filestore`, never `file_store` or `fileStore`.
- Name it for what it provides, so the call reads as a phrase: `order.Cancel`, `format.Percent`, `redact.Token`.
- Exported names don't repeat the package: `order.Status`, not `order.OrderStatus`; `filestore.Open`, not `filestore.OpenFileStore`. revive's `exported` rule flags the stutter.
- A layer is not a job. `handlers`, `services`, `repositories` and `models` each collect one kind of code from every domain, so every change touches all of them.

## Package docs

Each package has one doc comment, in one file, stating its job in a sentence:

```go
// Package filestore keeps orders as files in one directory.
package filestore
```

If the sentence needs an "and" between two unrelated jobs, it's two packages. staticcheck's ST1000 fails a package with no doc.

## Files

- **One job per file, named for it:** `order.go` holds the type, `status.go` its states, `cancel.go` and `refund.go` the operations. Tests mirror them: `cancel_test.go`.
- **Split a file when it takes on a second job,** whatever its length. A 600-line file doing one job is fine; a 150-line file doing three is not.
- **Split a package when its callers use one part without the other.** Merge two when every caller imports both.

## Import cycles

A cycle means a package reaches the wrong way:
- an edge's type leaked into the domain → move it back out to the edge;
- two domain packages call each other → the one that needs a single method declares an interface for it ([go-interfaces](../../go-interfaces/SKILL.md)), or the shared type moves to the package whose job it is;
- never a new `types`, `models` or `common` package to hold what both need. That is the grab bag rule 4 bans, made to order.

## init and package state

- **No `init()`.** What a package needs is built in its constructor, called from `run`.
- **A package-level `var` holds only what never changes after start:** sentinel errors, compiled regexps. A table callers might change is a function returning a fresh copy ([go-types](../../go-types/SKILL.md)).
- **The logger is a field or a parameter,** never a package variable or `slog.Default()`.
