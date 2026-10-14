# Properties, Snapshots and Benchmarks

**A parser or stored format is tested on input nobody chose, output too long to write inline is pinned in a snapshot, and a claim about speed is a measurement.**

Contents: properties with hypothesis · what makes a property · pinning what it finds · the database and CI · snapshots with syrupy · benchmarks.

## Properties with hypothesis

Example tests check the cases the author thought of. A property states what holds for every input, and hypothesis generates the inputs, then shrinks a failure to the smallest one that still fails.

```python
# tests/storage/test_stored_form.py
lines = st.builds(
    make_line,
    sku=st.text(min_size=1, max_size=20),
    quantity=st.integers(min_value=1, max_value=10_000),
    unit_minor=st.integers(min_value=0, max_value=10**9),
)
orders = st.builds(
    make_order,
    customer_id=st.text(min_size=1, max_size=20),
    status=st.sampled_from(Status),
    payment_id=st.none() | st.text(min_size=1, max_size=20),
    lines=st.lists(lines, min_size=1, max_size=5).map(tuple),
)


@given(first=orders, then=orders)
@example(first=make_order(), then=make_order(customer_id="c-2"))
def test_an_order_saved_over_another_reads_back_as_saved(
    tmp_path_factory: pytest.TempPathFactory, first: Order, then: Order
) -> None:
    with closing(connect(tmp_path_factory.mktemp("store") / "shop.db")) as db:
        store = SqliteOrders(db=db)
        store.save(first)
        store.save(then)

        assert store.get(then.id) == then
```

- **Its first run failed.** The store's upsert updated the status and the payment but kept the old `customer_id` and currency, and no example test had saved one order twice. hypothesis shrank it to two saves of one order whose customers differ, `'0'` then `'00'`. The `@example` holds the same case in the builders' words.
- **Strategies are built from the builders,** `st.builds(make_order, ...)`, so a generated order passes the same checks as a hand-built one, and the ranges say what the domain allows: a quantity of at least one, a price of no less than nothing.
- **A function-scoped fixture can't serve `@given`:** hypothesis runs every example inside one call of the test, so they'd share it, and it fails the test with `FailedHealthCheck: ... uses a function-scoped fixture 'tmp_path'`. `tmp_path_factory`, which is session-scoped, makes a fresh directory per example.

## What makes a property

| Property | Shape |
|---|---|
| Round trip | what's saved reads back; what's formatted parses back: `parse(format(x)) == x` |
| Invariant | an order's total is the sum of its lines, in any order of lines |
| Agreement | the fast implementation and a slow, obvious one give the same answer |
| No crash | any input parses or raises the parser's own error, never `KeyError` or `IndexError` |

Not a property: a check the strategy already guarantees. `assert len(order.lines) >= 1` over `min_size=1` tests hypothesis, not the code. If the assertion can't fail whatever the code does, it's the strategy restated.

## Pinning what it finds

- **`@example(...)` pins a failure in the test,** as above, so it runs first, every time, everywhere, whatever hypothesis generates. It's the regression case, written where a reviewer reads it.
- **Fix the code, never the strategy.** Narrowing a strategy until the failure stops being generated hides the bug. Narrow it only to what the domain allows, and say why.

## The database and CI

- **hypothesis keeps the failures it found in `.hypothesis/`** and replays them first on the next run. It writes a `.gitignore` there itself: the directory is a local cache, not committed. `@example` is the shared record.
- **On CI it loads its `ci` profile by itself,** when it sees `CI` or a known runner's variable (`GITHUB_ACTIONS` and others): derandomised, so a run reproduces; no example database; no deadline, as CI machines stall; and it prints a blob that reproduces a failure locally. Nothing to configure.
- **100 examples per test** by default. Raise `@settings(max_examples=...)` for a parser that matters, not for every test.

## Snapshots with syrupy

Output too long to write as a literal in the test (a stored file, a report, a rendered page) is compared with a snapshot: syrupy writes it to a file beside the test on the first run, and every later run compares against that file.

```python
# tests/storage/test_stored_form.py
def test_an_order_is_stored_in_the_rows_the_snapshot_shows(
    tmp_path: Path, snapshot: SnapshotAssertion
) -> None:
    order = copy.replace(
        make_order(status=Status.CANCELLED, payment_id="pay-1"),
        cancelled_at=NOW,
        cancel_reason=Reason.FRAUD,
    )

    with closing(connect(tmp_path / "shop.db")) as db:
        SqliteOrders(db=db).save(order)
        rows = "\n".join(db.iterdump())

    assert rows == snapshot
```

The snapshot is the store's schema and rows, in `tests/storage/__snapshots__/test_stored_form.ambr`:

```text
  INSERT INTO "orders" VALUES('o-1','c-1','cancelled','GBP','pay-1','2026-10-14T09:30:00+00:00','fraud');
```

- **`uv run pytest --snapshot-update`** writes new snapshots, rewrites changed ones and deletes those no test uses. Review the `.ambr` diff as you'd review code: it's the spec of the stored format, and an update nobody reads is a test that checks nothing.
- **A plain run fails on a snapshot no test uses,** so a renamed test can't leave its old snapshot behind.
- **Only stable output.** Fix the clock (`NOW`), sort what comes from a set, and keep absolute paths and generated ids out. A snapshot that changes on every run gets updated without being read.
- **Short values stay inline:** `assert display.money(m) == "12.50 GBP"`. A snapshot is for what's too long to read in the test, and it hides the expected value in another file.
- **A stored format's own encoder** is still pinned by a test of its bytes ([python-types](../../python-types/SKILL.md)); the snapshot is that test when the output is long.

## Benchmarks

A claim that something is fast enough, or faster than before, is a benchmark. Use pytest-benchmark, which times a function across many rounds in the ordinary test run and keeps runs to compare. pytest-codspeed is the alternative when a project tracks speed on every pull request through the hosted CodSpeed service; without that service, pytest-benchmark is the one that answers on your machine.

```python
# tests/order/test_order_speed.py
def test_a_500_line_order_totals(benchmark: BenchmarkFixture) -> None:
    order = make_order(lines=tuple(make_line(sku=f"sku-{n}") for n in range(500)))

    total = benchmark(lambda: order.total)

    assert total == Money(minor=500 * 2500, currency="GBP")
```

- **The gate runs it once, as a test.** `--benchmark-disable` in the `[tool.pytest]` `addopts` makes `benchmark(...)` call the function once and time nothing, so the assert still runs and the gate stays fast.
- **Measure on purpose,** on a quiet machine: `uv run pytest --benchmark-enable --benchmark-only --benchmark-autosave` before the change, then the same with `--benchmark-compare` after it. Keep the numbers with the change that claims the speed-up.
- **It checks the result too.** A benchmark of a function that returns the wrong answer quickly is measuring a bug.
- **Benchmark what the claim is about,** at the size it's about: a 500-line order, not a one-line one.
