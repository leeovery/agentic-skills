# Commands

**A command is an edge: typer parses the command line into typed values, the command calls one operation, and `run` turns how it ended into an exit code.**

Contents: the app · a command · output · exit codes · main · tracebacks and secrets · testing a command · start-up time · edge cases.

typer is the one library here. Version 0.27 bundles its own copy of click and no longer depends on click, so `click.ClickException` and `click.UsageError` never match its errors: catch `typer.TyperException`, `typer.Exit` and `typer.Abort`.

## The app

```python
type Settle = Callable[[Iterable[PaymentId]], list[PaymentId]]


@dataclass(frozen=True, slots=True, kw_only=True)
class Deps:
    """What the commands call. Each opens what it needs only when called."""

    canceller: Callable[[], AbstractContextManager[Canceller]]
    settle: Settle


type Wiring = Callable[[], Deps]


def build_app(wiring: Wiring) -> typer.Typer:
    """Build the command line over wiring, which a command calls only when it runs."""
    app = typer.Typer(add_completion=False, no_args_is_help=True)

    @app.callback()
    def root() -> None:
        """Look after the shop's orders."""

    @app.command()
    def cancel(
        order_id: str,
        reason: Annotated[
            Reason, typer.Option(help="Why it's cancelled.")
        ] = Reason.CUSTOMER,
    ) -> None:
        """Cancel an order, refunding it first if it was paid."""
        with wiring().canceller() as canceller:
            order = canceller.cancel(OrderId(order_id), reason=reason)
        typer.echo(f"cancelled {order.id}, worth {display.money(order.total)}")

    @app.command()
    def settle(payment_ids: list[str]) -> None:
        """Settle payments with the provider, a few at a time."""
        unsettled = wiring().settle([PaymentId(p) for p in payment_ids])
        if unsettled:
            raise SettlementError(f"payments not settled: {', '.join(unsettled)}")

    return app
```

- **`Deps` holds, for each command, what opens what it calls:** `canceller`, a context manager that opens the database and the gateway's client around a `Canceller`, and `settle`, a function that enters asyncio and opens its own async client ([python-concurrency](../../python-concurrency/SKILL.md)). A command opens only its own, so `settle` never touches the database.
- **The wiring is a function the command calls,** returning `Deps` and opening nothing. `--help`, `shop` alone and a mistyped command never call it, so they need no settings, no database and no network. `main` passes `from_environment`; a test passes `lambda: Deps(canceller=lambda: nullcontext(canceller), settle=...)`.
- **No module-level `app`.** One built at import is shared by every test that imports it, and its commands can only reach their dependencies through globals.
- **The callback keeps each command a subcommand, however few there are.** A typer app with one command and no callback runs that command as the app itself, so `shop cancel o-1` would fail as an extra argument. The callback's docstring is the app's help.
- **`add_completion=False`** drops the `--install-completion` option, which edits the user's shell start-up file. Turn it on when completion is wanted, and test it.

## A command

- **Its signature is the command line.** A parameter without a default is an argument; one typed through `Annotated[T, typer.Option(...)]` is an option, with its help beside it. typer converts each value to the annotated type before the body runs.
- **An enum option lists its values,** in `--help` and in the error, which exits 2.
- **Parse first.** Each value becomes the domain's type at the top: `OrderId(order_id)`. A value the command checks itself after typer has parsed it raises `typer.BadParameter("…")`, which `run` turns into 2 like any other usage error.
- **Enter the wiring around the call,** and close it before writing the result, so the database and the client are closed whatever happens next.
- **A secret never arrives as an option or an argument.** It shows in `ps` and the shell's history, and typer quotes a bad value in its error. It comes from the environment through `Settings` ([python-architecture](../../python-architecture/SKILL.md)), or from `typer.prompt(..., hide_input=True)`.

## Output

- **Data goes out through `typer.echo`,** to standard output: what a pipe or a script reads. `shop list | wc -l` counts orders, not warnings.
- **Diagnostics go through `typer.echo(..., err=True)`,** to standard error: progress, warnings, and `run`'s one line per failure.
- **Never `print`.** ruff's `T201` reports it. A log line goes through the module's logger, which `main` points at standard error ([python-errors](../../python-errors/SKILL.md)).
- **A value is worded by `shop.display`,** never by a format spec in the command ([python-architecture](../../python-architecture/SKILL.md)).

## Exit codes

| Code | When |
|---|---|
| 0 | it worked; `--help` too |
| 1 | the command failed, or a prompt was declined |
| 2 | a usage error: an unknown command or option, a missing argument, a value that doesn't parse, and `shop` with no command |
| 130 | the user interrupted it: 128 plus SIGINT's number, as a shell reports it |

One function sets them. `main` returns its code:

```python
def run(app: typer.Typer, argv: Sequence[str] | None) -> int:
    """Run app on argv, and return the exit code for how it ended.

    0 for success, 1 for a failure, 2 for a usage error, 130 for an interrupt.
    """
    try:
        code = app(args=argv, prog_name="shop", standalone_mode=False)
    except typer.TyperException as err:
        # With no command, the app prints its help and raises with no message.
        if message := err.format_message():
            typer.echo(f"shop: {message}", err=True)
        return err.exit_code
    except typer.Abort:
        # A prompt declined, or its input closed with Ctrl-D.
        typer.echo("shop: aborted", err=True)
        return 1
    except (SettingsError, OrderError, SettlementError) as err:
        typer.echo(f"shop: {_said(err)}", err=True)
        return 1
    except Exception:
        logger.exception("shop failed")
        return 1
    # With standalone_mode off, the app returns the code of --help, of a
    # typer.Exit, and 130 for an interrupt; a command itself returns None.
    return code if isinstance(code, int) else 0


def _said(err: Exception) -> str:
    """The error's message, then each note added to it on the way up, a line each."""
    ...
```

- **`standalone_mode=False`** makes typer return or raise instead of calling `sys.exit` itself. Usage errors arrive as `typer.TyperException` subclasses carrying `exit_code`, 2; `typer.Exit(n)`, `--help` and an interrupt come back as the return value, so `run` needs no clause for 130.
- **`typer.Abort` is caught before `Exception`.** It isn't a `TyperException`, and without its own clause a declined `typer.confirm(..., abort=True)` would be logged as a crash, traceback and all.
- **Expected failures print their message and exit 1:** the `SettingsError` that `read_settings` makes of the settings' `ValidationError`, naming every bad variable at once, and the domain's `OrderError` and `SettlementError`, with each note `_said` finds. Only the unexpected is logged with its traceback, once, here ([python-errors](../../python-errors/SKILL.md)).
- **`ValidationError` itself is not among them.** Each edge turns its own into an answer or a domain error, so one that still reaches `run` came from a bug, and gets the traceback.
- **A command never exits by itself:** no `sys.exit`, and no `raise typer.Exit(1)` for a failure. It raises the domain's exception, and `run` prints it and picks the code.

## main

```python
def main(argv: Sequence[str] | None = None) -> int:
    """Run the command line on argv, or on the process's own arguments."""
    with CORRELATION_ID.set(str(uuid.uuid7())):
        return run(build_app(from_environment), argv)
```

`CORRELATION_ID` is the context variable each of the run's log lines is stamped from, so every line the run causes carries its id ([python-errors](../../python-errors/SKILL.md)).

- **`[project.scripts]` names it:** `shop = "shop.cli:main"`. The script uv installs calls `sys.exit(main())`, and `shop/__main__.py` does the same for `python -m shop` with `raise SystemExit(main())`. Nothing else exits.
- **`from_environment` is the composition root's wiring:** it reads the settings, configures logging, and returns each command's openers, with the values they need bound in ([python-architecture](../../python-architecture/SKILL.md)).
- **`argv=None` means the process's arguments;** `main([])` means none. Tests always pass a list.

## Tracebacks and secrets

typer installs its own `sys.excepthook`, which prints a rich traceback for an exception that escapes the process. Under `run`, none escapes, and the unexpected are logged with the standard traceback, which shows no local variables.

- **Leave `pretty_exceptions_show_locals` at its default, `False`.** With it on, an escaping exception prints every local of every frame, and a local such as `header = f"Bearer {token.get_secret_value()}"` prints in full.
- **Set nothing else on it.** The pretty traceback is for a developer's terminal, and `run` never lets it run.

## Testing a command

Build the app over a wiring that yields fakes, and run it with typer's `CliRunner`:

```python
def over_fakes(*, settle: Settle) -> typer.Typer:
    """The command line over fakes: one pending order, and settle as given."""
    canceller = Canceller(
        orders=FakeOrders.holding(make_order()),
        payments=FakeRefunder(),
        now=lambda: NOW,
    )
    return build_app(
        lambda: Deps(canceller=lambda: nullcontext(canceller), settle=settle)
    )


def test_cancel_prints_the_order_it_cancelled() -> None:
    result = CliRunner().invoke(
        over_fakes(settle=lambda _: []), ["cancel", "o-1", "--reason", "out-of-stock"]
    )

    assert result.exit_code == 0
    assert result.stdout == "cancelled o-1, worth 25.00 GBP\n"
```

`main` reads the environment, so its tests run it inside `CliRunner().isolation(env=...)`, every `SHOP_` variable set or unset, so the machine running the tests can't leak in ([python-testing](../../python-testing/SKILL.md)).

- **`build_app` with fakes** tests what a command translates: parsing, output and the code. What `cancel` decides is tested on `Canceller`.
- **`run` over a small app made in the test** covers the codes no real command reaches easily: a command that raises `KeyboardInterrupt` for 130, one that raises `RuntimeError` for the logged 1, and one that declines a prompt fed through `isolation(input="n\n")`.
- **`main` with no settings** proves `--help` and usage errors never build the wiring. **`main` with settings missing** proves they're all named at once.
- **Check standard output and standard error apart:** data in one, the failure in the other. Read them inside the `isolation` block; they're closed after it.

## Start-up time

Every command pays for every module `shop.cli` imports, `--help` included. Measure before moving anything:

```sh
uv run python -X importtime -c "import shop.cli" 2>&1 | sort -t'|' -k2 -n | tail
```

Where it matters (a command run many times a minute, or by shell completion), move the heavy imports into the function that uses them, `from_environment` here, and say why in a comment. Where it doesn't, leave them at the top, where a reader finds every dependency. On a 3.15 floor, `lazy import` does the same at the top of the module ([python-quality](../../python-quality/SKILL.md)).

## Edge cases

- **A closed pipe ends in typer's own `sys.exit(1)`.** `shop … | head` closes standard output early; typer catches the broken pipe and exits even with `standalone_mode=False`, so the `SystemExit` passes through `run` and `main` never returns.
- **Prompts read standard input.** A command that prompts can't run unattended; give it an option that answers, and test both.
