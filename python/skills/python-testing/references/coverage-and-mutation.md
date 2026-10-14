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
src/shop/cli.py                   77      5      6      1    93%   80->exit, 99, 152, 158-168
src/shop/order/order.py           43      2      6      1    94%   31-32
src/shop/order/refunds.py         35      1      0      0    97%   62
src/shop/payments/reports.py      23      5      4      0    74%   41-45
--------------------------------------------------------------------------
TOTAL                            393     15     34      2    96%
```

`uv run pytest --cov --cov-report=annotate:cov_annotate` also writes a copy of each file with `!` beside every line that never ran, which is quicker to read through than ranges. It marks lines only, not branches: read branches from the table.

## Reading the report

Each entry in `Missing` is a question, and the answer is one of four things: a test that's missing, code that's dead, code that runs somewhere coverage can't see, or code that's unreachable by design.

| Entry | Means | So |
|---|---|---|
| `80->exit` | the branch at line 80 never went the other way: `if unsettled:` was never false | a test is missing: `settle` with every payment settled |
| `99` | `return 130` under `except KeyboardInterrupt`, though the interrupt test passes | something else returns the 130, so the arm never runs: dead code, deleted rather than tested |
| `62` in `refunds.py` | `Tally.report`, which nothing calls | a test is missing, or the method is |
| `31-32` | the `case _: assert_never(self)` arm | unreachable by design, and the type checker proves it: leave it |
| `41-45` | the body of `report_totals`, run in worker processes | coverage can't see it yet: below |
| `152`, `158-168` | the wiring that enters asyncio and opens a real `AsyncClient` | runs only against the real provider; the parts it wires are tested apart |
| `3-5` in `__main__.py` | `raise SystemExit(main())` | run by hand; `main` itself is tested |

- **Branches matter more than lines.** A line with an `if` runs whichever way it goes; `80->exit` is the case nobody tried, and the line count can't show it.
- **Missed code that nothing should run is deleted,** not tested.
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
uv run mutmut run "shop.settlement*"     # one package; it resumes where it stopped
uv run mutmut results                    # every mutant not killed, and why
uv run mutmut show "shop.settlement.settle.xǁSettlerǁ_settled__mutmut_3"
uv run mutmut browse                     # step through them, and retest after a fix
```

- **It's an audit, not a gate.** It reruns tests for every mutant, so it runs now and then: on a package after a change to it, or when someone asks whether the tests are strong enough. mutmut 3.8 is Beta, and installed in a dependency group of its own ([python-project](../../python-project/SKILL.md)).
- **POSIX only.** It forks a process per mutant; on Windows it runs inside WSL.
- **The source guards sit it out.** mutmut copies the project into `mutants/` with each function rewritten, so the guards reading `src/` there find code that isn't the project's and fail. Keep `mutants/` out of version control.
- **It mutates code inside functions,** and only the functions some test calls. Module-level constants and code no test reaches are coverage's to find first.
- **Read each survivor and decide.** `🙁` survived: either an assert is missing, so write the test that kills it, or the change can't matter. Running the shop's settler, the survivors were its log message, changed to `None` and to `"XXpayment not settledXX"`; nobody reads that message as API, so it's a mutant to leave. Where a project agrees for every log call, `do_not_mutate_patterns` in `[tool.mutmut]` skips them.
- **`⏰` timed out** is a mutant that looped forever, such as a worker's `return` removed: the tests caught it by not finishing.

## Mutations by hand

The cheap version, for one change you've just made:

1. Pick a line the change added: a guard, a comparison, a call whose effect matters.
2. Change it one way: drop the call, flip the comparison, return early, swap two arguments.
3. Run the tests for that module: `uv run pytest tests/order/test_cancel.py`.
4. Revert with `git checkout -- src/shop/order/cancel.py`, then the next. One at a time, never stacked.

Delete `ensure_cancellable(order)` from `Canceller.cancel`, and `test_cancel_refuses_a_shipped_order` fails: the guard is held. A mutation that leaves every test green gets a test that fails on it, written before the revert, and seen to pass after.
