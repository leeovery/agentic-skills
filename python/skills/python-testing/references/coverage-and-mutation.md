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
src/shop/order/order.py           53      2     12      1    95%   34-35
src/shop/payments/refunds.py      35      1      0      0    97%   65
src/shop/payments/reports.py      28      6      4      0    75%   57-64
src/shop/storage/atomic.py        25      0      2      1    96%   30->exit

26 files skipped due to complete coverage.
```

`uv run pytest --cov --cov-report=annotate:cov_annotate` also writes a copy of each file with `!` beside every line that never ran, which is quicker to read through than ranges. It marks lines only, not branches: read branches from the table.

## Reading the report

Each entry in `Missing` is a question, and the answer is one of four things: a test that's missing, code that's dead, code that runs somewhere coverage can't see, or code that's unreachable by design.

| Entry | Means | So |
|---|---|---|
| `30->exit` in `atomic.py` | the branch at line 30 never went the other way: `if os.name == "posix":` was never false | it runs only on Windows, where no job measures it: leave it |
| `65` in `refunds.py` | `Tally.report`, which nothing calls | a test is missing, or the method is |
| `34-35` in `order.py` | the `case _: assert_never(self)` arm | unreachable by design, and the type checker proves it: leave it |
| `57-64` in `reports.py` | the body of `report_totals`, run in worker processes | coverage can't see it yet: below |

- **Branches matter more than lines.** A line with an `if` runs whichever way it goes; `30->exit` is the way nobody went, and the line count can't show it.
- **Missed code that nothing should run is deleted,** not tested. An `except KeyboardInterrupt: return 130` that never ran, though the interrupt test passed, was dead: typer turns an interrupt into 130 first. The clause went, and the test stayed.
- **`# pragma: no cover` hides a line from every later reader.** Leave a known gap in the report, where it's seen, instead.

## Code in other processes

Coverage measures the process pytest runs in. Code a `ProcessPoolExecutor` runs shows as missed, as `report_totals` does above, although its test passes. When a project uses process pools, measure them too:

```toml
[tool.coverage.run]
concurrency = ["thread", "multiprocessing"]
```

With it, `report_totals` reads as covered. Threads and asyncio tasks run in the test's process and are measured already.

## Never a target

No `fail_under`, no `--cov-fail-under`, no percentage in CI. A target is met by tests that call code and assert nothing, the cheapest way to raise the number and the least use. Coverage is read when someone asks whether the tests are enough, and before a mutation run, to know which code has any tests at all.

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

- **It's an audit, not a gate.** It reruns tests for every mutant, so it runs now and then: on a package after a change to it, or when someone asks whether the tests are strong enough. mutmut 3.8 is Beta, and installed in a `mutation` dependency group of its own ([python-project](../../python-project/SKILL.md)).
- **POSIX only.** It forks a process per mutant; on Windows it runs inside WSL.
- **The source guards sit it out.** mutmut copies the project into `mutants/` with each function rewritten, which the guards would fail. Keep `mutants/` out of version control.
- **It mutates code inside functions,** and only the functions some test calls. Module-level constants and code no test reaches are coverage's to find first.
- **Read each survivor and decide.** `🙁` survived: either an assert is missing, so write the test that kills it, or the change can't matter, such as a log call's message changed to `"XXpayment %s not settledXX"`. Where a project agrees for every log call, `do_not_mutate_patterns` in `[tool.mutmut]` skips them.
- **`⏰` timed out** is a mutant that never finished, such as the settler's deadline changed to `asyncio.timeout(None)`, which waits on a hung payment for good: the tests caught it by not finishing.

## Mutations by hand

The cheap version, for one change you've just made:

1. Pick a line the change added: a guard, a comparison, a call whose effect matters.
2. Change it one way: drop the call, flip the comparison, return early, swap two arguments.
3. Run the tests for that module: `uv run pytest tests/order/test_cancel.py`.
4. Revert with `git checkout -- src/shop/order/cancel.py`, then the next. One at a time, never stacked.

A mutation that leaves every test green gets a test that fails on it, written before the revert, and seen to pass after.
