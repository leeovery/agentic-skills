---
name: python-testing
description: pytest tests with plain asserts, builders, hand-written fakes over patches, and injected time — parametrize, hypothesis, syrupy, benchmarks, coverage, mutmut. Use when writing or changing tests, fixing a flaky or slow test, adding a fake, builder or fixture, testing time, retries or async code, or judging whether tests are strong enough.
---

# Python Testing

Tests are pytest with plain asserts, a fake for each seam, and a failure that says what was called, with what, and what came back.

## Core rules

1. **pytest, plain `assert`, and test functions.** No `unittest.TestCase`, no `self.assertEqual`. *pytest rewrites a failing `assert` to show the expression and both values; a `TestCase` adds a class, a dialect of asserts, and a `setUp` the reader has to go and find.* Held by ruff's `PT009` and `PT027`.
2. **One behaviour per test, named as that behaviour:** `test_cancel_refuses_a_shipped_order`. Arrange, act, assert, each block apart. *The failing test's name is the first line of the bug report, and a test that checks three behaviours stops at the first that breaks and hides the rest.*
3. **`parametrize` only when cases differ by data,** each row a `pytest.param(..., id="refunds a paid order in full")`. Rows carry no flags and no setup callables, and errors get a test of their own. *A row that steers a branch is two tests sharing a body; an id names the row in the report and lets it run alone.* Held by ruff's `PT006` and `PT007`, and by `strict = true`, which fails a duplicate id.
4. **Inputs come from builders with keyword defaults:** `make_order(status=Status.PAID)`. Fixtures are for resources, such as a database file or a client, in the test module or the nearest `conftest.py`. *A builder call shows the one thing the test is about; a shared fixture value hides it in another file, and couples every test that uses it.*
5. **Fake the seam, not the library:** a hand-written fake of your own Protocol, `httpx2.MockTransport` under the adapter, real files and SQLite on `tmp_path`. Never `unittest.mock` or `monkeypatch.setattr`: needing one means a seam is missing. *A patch pins where a name is looked up rather than what the code does, and keeps passing after the code has moved.* Held by ruff's `TID251` and a source guard, in [python-quality](../python-quality/SKILL.md).
6. **Time is passed in:** `now` for the instant, `sleep` for waits and retries, and looptime's virtual clock for an asyncio deadline. time-machine only for a library that reads the clock itself. No real sleeps. *A real sleep makes the suite slow and its timing flaky; a passed-in clock makes a month end or a back-off exact.*
7. **An exception is checked by its type and its attributes:** `with pytest.raises(NotCancellable) as refused:`, one statement inside, then `refused.value.status`. *The message is wording, and changes; the attribute is what callers branch on.* Held by ruff's `PT011` and `PT012`.
8. **A warning fails the suite.** The drop-in's `[tool.pytest]` sets `strict = true` and `filterwarnings = ["error"]`; a third-party warning you can't fix yet gets an `ignore:` line naming its message and module, with why. *A deprecation that only warns is found the day the API is removed.* Held by the drop-in, in [python-quality](../python-quality/SKILL.md).
9. **No test touches the real network, home directory or environment.** *A test that reaches the network passes or fails with it, and one that reads the developer's environment passes only on their machine.* Held by pytest-socket's `--disable-socket`, and a session fixture that points `HOME` at a temporary directory and unsets the app's variables.
10. **Test where the bugs live.** Two outputs that show one fact are checked to agree, and cases sit at the hard moments: the boundary, the retry after a failure, the second save of one id. *Bugs sit between the cases: a round-trip property found the shop's store keeping the old customer, because no example test had saved one order twice.*
11. **Hand-picked cases get help where they run out.** A parser or stored format gets hypothesis tests whose property isn't the strategy restated; long output gets a syrupy snapshot; a claim about speed gets a benchmark; a retry policy gets three tests: it recovers, it stops at its cap, and it never retries a permanent failure. *Examples test what the author thought of; the rest find what they didn't, and keep a measured claim honest.*
12. **Coverage is measured with branches and read to find what nothing runs, never set as a target.** mutmut audits, now and then, whether the asserts catch anything. *A target is met with tests that run code and check nothing; a mutant that survives points at the assert that's missing.*

## Pattern

```python
# tests/order/test_cancel.py
@pytest.mark.parametrize(
    ("order", "refunded"),
    [
        pytest.param(make_order(), [], id="cancels a pending order without a refund"),
        pytest.param(
            make_order(status=Status.PAID, payment_id="pay-1"),
            [(PaymentId("pay-1"), Money(minor=2500, currency=Currency.GBP))],
            id="refunds a paid order in full",
        ),
    ],
)
def test_cancel_refunds_only_a_paid_order(
    order: Order, refunded: list[tuple[PaymentId, Money]]
) -> None:
    payments = FakeRefunder()

    canceller(FakeOrders.holding(order), payments).cancel(
        order.id, reason=Reason.CUSTOMER
    )

    assert payments.refunded == refunded


def test_cancel_refuses_a_shipped_order() -> None:
    orders = FakeOrders.holding(make_order(status=Status.SHIPPED, payment_id="pay-1"))

    with pytest.raises(NotCancellable) as refused:
        canceller(orders).cancel(OrderId("o-1"), reason=Reason.CUSTOMER)

    assert refused.value.status is Status.SHIPPED
    assert orders.saved == []
```

`make_order`, `FakeOrders` and `FakeRefunder` are in [fakes-and-builders.md](references/fakes-and-builders.md); `canceller` builds a `Canceller` over them with its clock stopped at `NOW`. The refusal checks a different thing, so it's a test of its own.

## Decision table

| The test needs | Reach for |
|---|---|
| Many inputs through one behaviour | `parametrize`, a `pytest.param` with an `id` per row |
| A different setup or a different check | a test of its own |
| An input value | a builder: `make_order(status=Status.PAID)` |
| A collaborator you own | a hand-written fake of its Protocol |
| The outside HTTP API | the adapter against `httpx2.MockTransport` |
| Files or a store | the real thing on `tmp_path` |
| A decision that turns on the date | an injected `now` |
| Waits and retries | an injected `sleep` that records what it was asked |
| An asyncio deadline or interval | `@pytest.mark.looptime` |
| A library that reads the clock itself | `time_machine.travel` |
| Input from outside the program | a hypothesis property |
| Long or byte-exact output | a syrupy snapshot |
| A claim about speed | a pytest-benchmark test |
| Whether the tests are strong enough | branch coverage read, then mutmut |

## Anti-patterns

- ❌ `class TestCancel(unittest.TestCase)` with `self.assertEqual` → test functions and `assert`.
- ❌ `mock.patch("shop.order.cancel.datetime")`, or `monkeypatch.setattr(...)` → pass `now` in.
- ❌ `MagicMock(spec=Refunder)` and `assert_called_once_with(...)` → `FakeRefunder()`, then `assert payments.refunded == [...]`.
- ❌ A row with `should_raise: bool`, or a `setup` callable → a test of its own for each case that differs.
- ❌ `pytest.raises(Exception)`, or `match="not found"` as the whole check → the exception's type, then its attributes.
- ❌ `time.sleep(0.1)` to let something finish → inject the sleep; looptime for a loop's timers.
- ❌ A `paid_order` fixture in `conftest.py` that twenty tests share → `make_order(status=Status.PAID)` in each.
- ❌ `@pytest.mark.asyncio` on each async test → nothing: the drop-in runs them in auto mode.
- ❌ A property that restates its strategy (`assert len(lines) >= 1` over `min_size=1`) → a round trip or an invariant.
- ❌ `--cov-fail-under=90` → read the missed lines and branches; mutmut for strength.
- ❌ `test_cancel_2`, `test_cancel_error` → a name that states the behaviour.

## References

- **[parametrize.md](references/parametrize.md)** — read when writing a parametrized test or naming tests: when cases differ by data, rows and ids, errors apart, names, and what `PT006` and `PT007` want.
- **[fakes-and-builders.md](references/fakes-and-builders.md)** — read when a test needs a collaborator or an input value: builders, hand-written fakes, `httpx2.MockTransport`, real files and SQLite, and what to do when a test seems to need a patch.
- **[time.md](references/time.md)** — read when testing time, timeouts, retries or async code: injected `now` and `sleep`, the three retry tests, looptime, time-machine and its limits, and async tests.
- **[properties-snapshots-benchmarks.md](references/properties-snapshots-benchmarks.md)** — read when testing a parser or stored format, long output, or a claim about speed: hypothesis, syrupy and pytest-benchmark.
- **[isolation.md](references/isolation.md)** — read when setting up a project's test gate or a test touches the outside world: pytest-socket, the session fixture, settings and `main` in tests.
- **[coverage-and-mutation.md](references/coverage-and-mutation.md)** — read when asked about coverage or whether the tests are strong enough: reading branch coverage, mutmut, and mutations by hand.

## Related skills

- **[python-protocols](../python-protocols/SKILL.md)** — the seams these fakes fill, the clock among them, and the retry policy the three tests pin
- **[python-quality](../python-quality/SKILL.md)** — the gates, the drop-in pytest config, and the guard against patching
- **[python-concurrency](../python-concurrency/SKILL.md)** — the tasks, deadlines and workers the async tests drive
- **[python-errors](../python-errors/SKILL.md)** — the exceptions and attributes a test checks, and `caplog`
- **[python-types](../python-types/SKILL.md)** — the stored formats a snapshot pins and a property round-trips
