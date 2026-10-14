# Coverage and Mutation

**Coverage finds the code no test runs; mutation finds the asserts no test makes. Both are read, now and then, and neither is a target.**

Contents: measuring · reading the report · code in other processes · never a target · mutmut · mutations by hand.

## Measuring

The drop-in's `[tool.coverage]` measures branches, lists what's missing, and skips files that are fully covered ([python-quality](../../python-quality/SKILL.md)). pytest-cov runs it:

```sh
uv run pytest --cov
```

```text
Name                           Stmts   Miss Branch BrPart  Cover   Missing
--------------------------------------------------------------------------
src/shop/__main__.py               2      2      0      0     0%   3-5
src/shop/api.py                   37      4      6      1    84%   83-86
src/shop/cli.py                   83      4      4      1    94%   81->exit, 164, 170-180
src/shop/money.py                 32      1      6      1    95%   48
src/shop/order/order.py           45      2      6      1    94%   32-33
src/shop/order/refunds.py         35      1      0      0    97%   62
src/shop/payments/gateway.py      59      2     12      1    96%   121-122
src/shop/payments/reports.py      23      5      4      0    74%   41-45
src/shop/processes.py             17      2      0      0    88%   20-21
--------------------------------------------------------------------------
TOTAL                            709     23     70      5    96%

26 files skipped due to complete coverage.
```

`uv run pytest --cov --cov-report=annotate:cov_annotate` also writes a copy of each file with `!` beside every line that never ran, which is quicker to read through than ranges. It marks lines only, not branches: read branches from the table.

## Reading the report

Each entry in `Missing` is a question, and the answer is one of four things: a test that's missing, code that's dead, code that runs somewhere coverage can't see, or code that's unreachable by design.

| Entry | Means | So |
|---|---|---|
| `81->exit` in `cli.py` | the branch at line 81 never went the other way: `if unsettled:` was never false | a test is missing: `settle` with every payment settled |
| `121-122` in `gateway.py` | `passing`'s last arm, `case _: return False`: no test raises an error the allow-list leaves out | a test is missing: a `DecodingError` from the handler fails on the first attempt, unretried |
| `62` in `refunds.py` | `Tally.report`, which nothing calls | a test is missing, or the method is |
| `48` in `money.py`, `83-86` in `api.py`, `20-21` in `processes.py` | a split into no parts; the 502 and 500 answers; `ProcessRunner.which` | tests are missing, each a few lines |
| `32-33` in `order.py` | the `case _: assert_never(self)` arm | unreachable by design, and the type checker proves it: leave it |
| `41-45` in `reports.py` | the body of `report_totals`, run in worker processes | coverage can't see it yet: below |
| `164`, `170-180` in `cli.py` | the wiring that enters asyncio and opens a real `AsyncClient` | runs only against the real provider; the parts it wires are tested apart |
| `3-5` in `__main__.py` | `raise SystemExit(main())` | run by hand; `main` itself is tested |

- **Branches matter more than lines.** A line with an `if` runs whichever way it goes; `81->exit` is the case nobody tried, and the line count can't show it.
- **Missed code that nothing should run is deleted,** not tested. `run` once ended with `except KeyboardInterrupt: return 130`, and the report showed it never ran, though the interrupt test passed: typer turns an interrupt into 130 before `run` sees it. The clause went, and the test stayed.
- **`# pragma: no cover` hides a line from every later reader.** Leave a known gap in the report, where it's seen, instead.

## Code in other processes

Coverage measures the process pytest runs in. Code a `ProcessPoolExecutor` runs shows as missed, as `report_totals` does above, although its test passes. When a project uses process pools, measure them too:

```toml
[tool.coverage.run]
concurrency = ["thread", "multiprocessing"]
```

With it, `report_totals` reads as covered. Threads and asyncio tasks run in the test's process and are measured already.

## Never a target

No `fail_under`, no `--cov-fail-under`, no percentage in CI. A target is met by tests that call code and assert nothing, which is the cheapest way to raise the number and the least use. It also punishes deleting a well-tested function. Coverage is read when someone asks whether the tests are enough, and before a mutation run, to know which code has any tests at all.

## mutmut

mutmut changes the code one small way at a time (a `<` to `<=`, a string to `None`, a `return True` to `return False`), runs the tests that reach the changed function, and reports each change the tests didn't notice. A surviving mutant is a behaviour no assert checks.

```toml
[tool.mutmut]
source_paths = ["src/"]
# The guards read the source, which mutmut rewrites, so they sit the audit out.
pytest_add_cli_args_test_selection = ["tests/", "--ignore=tests/guards"]
```

```sh
uv run --group mutation mutmut run "shop.settlement*"   # one package; it resumes where it stopped
uv run --group mutation mutmut results                  # every mutant not killed, and why
uv run --group mutation mutmut show "shop.settlement.settle.xǁSettlerǁ_settled__mutmut_9"
uv run --group mutation mutmut browse                   # step through them, and retest after a fix
```

- **It's an audit, not a gate.** It reruns tests for every mutant, so it runs now and then: on a package after a change to it, or when someone asks whether the tests are strong enough. mutmut 3.8 is Beta, and installed in a `mutation` dependency group of its own, outside `dev`, so each command names the group ([python-project](../../python-project/SKILL.md)).
- **POSIX only.** It forks a process per mutant; on Windows it runs inside WSL.
- **The source guards sit it out.** mutmut copies the project into `mutants/` with each function rewritten, so the guards reading `src/` there find code that isn't the project's and fail. Keep `mutants/` out of version control.
- **It mutates code inside functions,** and only the functions some test calls. Module-level constants and code no test reaches are coverage's to find first.
- **Read each survivor and decide.** `🙁` survived: either an assert is missing, so write the test that kills it, or the change can't matter. Running the shop's settler, every survivor was in its one log call: the message changed to `"XXpayment %s not settledXX"`, the payment's id to `None`, `exc_info=True` to `False`. Nobody reads that line as API, so they're mutants to leave. Where a project agrees for every log call, `do_not_mutate_patterns` in `[tool.mutmut]` skips them.
- **`⏰` timed out** is a mutant that never finished, such as the settler's deadline changed to `asyncio.timeout(None)`, which waits on a hung payment for good: the tests caught it by not finishing.

## Mutations by hand

The cheap version, for one change you've just made:

1. Pick a line the change added: a guard, a comparison, a call whose effect matters.
2. Change it one way: drop the call, flip the comparison, return early, swap two arguments.
3. Run the tests for that module: `uv run pytest tests/order/test_cancel.py`.
4. Revert with `git checkout -- src/shop/order/cancel.py`, then the next. One at a time, never stacked.

Delete `ensure_cancellable(order)` from `Canceller.cancel`, and `test_cancel_refuses_a_shipped_order` fails: the guard is held. A mutation that leaves every test green gets a test that fails on it, written before the revert, and seen to pass after.
