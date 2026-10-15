# Parametrize and Names

**A parametrized test is one behaviour fed many inputs. When the setup or the check changes, it's another test.**

Contents: when it fits · row shape · ids · errors apart · names · what the drop-in's rules want.

## When it fits

| Fits `parametrize` | Wants a test of its own |
|---|---|
| The same call and the same checks, with different values | A different arrangement: another fake, another stored state |
| Rows a reader can scan as data | A row that needs a `setup` callable or a `should_raise` flag |
| Adding a case means adding a row | Adding a case means adding an `if` to the body |

A body that branches on a row's fields is several tests sharing one body, and each branch is tested less clearly than it would be alone. Split it.

## Row shape

```python
# tests/order/test_order.py
@pytest.mark.parametrize(
    ("status", "cancellable"),
    [
        pytest.param(Status.PENDING, True, id="pending can be cancelled"),
        pytest.param(Status.PAID, True, id="paid can be cancelled"),
        pytest.param(Status.SHIPPED, False, id="shipped can't"),
        pytest.param(Status.CANCELLING, True, id="cancelling can, to finish"),
        pytest.param(Status.CANCELLED, False, id="cancelled can't"),
    ],
)
def test_a_status_says_whether_it_allows_cancelling(
    *, status: Status, cancellable: bool
) -> None:
    assert status.cancellable is cancellable
```

`Status.cancellable` is the property under test ([python-types](../../python-types/SKILL.md)).

- **The expected value is data in the row,** never a flag that picks which check runs.
- **Rows hold values, not fixtures or callables.** A row's value is built once, at collection, so it's a frozen value from a builder, as the [SKILL.md](../SKILL.md#pattern) pattern's rows are from `make_order`. A list or a fake in a row is one object that a test can change under the next.
- **The parameters are keyword-only (`*`)** when one is a `bool`: ruff's `FBT001` flags a positional `bool`, and pytest passes parameters by name, so the `*` costs nothing.
- **Every case of a closed set gets a row,** as above: a member added to `Status` without a row is a case nobody decided.

## Ids

- **Each row is a `pytest.param(..., id="...")`,** the id beside its values. A separate `ids=[...]` list drifts out of step with the rows the first time one is inserted.
- **An id reads as the end of a sentence that starts with the test's name:** `test_cancel_refunds_only_a_paid_order[refunds a paid order in full]`. Without ids, pytest makes them from the values, and an `Order` becomes `order0`.
- **Ids are unique.** The drop-in's `strict = true` fails collection on a duplicate id, and names the fix.
- **One row runs alone** by its node id, quoted: `uv run pytest "tests/order/test_cancel.py::test_cancel_refunds_only_a_paid_order[refunds a paid order in full]"`. `-k "paid and full"` matches on words in it.

## Errors apart

A success checks a value; a failure checks an exception. Mixing them puts a `should_raise` branch in the body, so the failures get a test of their own:

```python
# tests/order/test_order.py
@pytest.mark.parametrize(
    "stored",
    [
        pytest.param("", id="nothing"),
        pytest.param("lost", id="a status it doesn't know"),
        pytest.param("PAID", id="the right word in capitals"),
        pytest.param("paid ", id="a trailing space"),
    ],
)
def test_a_status_it_doesnt_know_is_refused(stored: str) -> None:
    with pytest.raises(ValueError, match="is not a valid Status"):
        Status(stored)
```

- **Match on the type first.** `ValueError` is the standard library's, raised for many reasons, so `match=` says which one; ruff's `PT011` insists on it for `ValueError`, `OSError` and `Exception`.
- **For your own exceptions, check attributes,** not the message: `refused.value.status is Status.SHIPPED`. A subclass exists because a caller branches on it ([python-errors](../../python-errors/SKILL.md)), and the test branches on the same thing.
- **One statement inside `pytest.raises`.** Setup inside the block can raise the same type and pass the test for the wrong reason. ruff's `PT012` flags a block with more.

## Names

- **Tests:** `test_` and the behaviour, as a sentence about the subject: `test_cancel_refuses_a_shipped_order`, `test_settle_runs_no_more_than_its_limit_at_once`. Not `test_cancel_2`, `test_cancel_error` or `test_happy_path`.
- **Rows:** lower-case phrases that finish the sentence: `test_a_status_it_doesnt_know_is_refused[a trailing space]`.
- **Modules mirror the source:** `tests/order/test_cancel.py` tests `src/shop/order/cancel.py`. Every test directory is a package with an `__init__.py`, and every module opens with a docstring saying what it tests ([python-quality](../../python-quality/SKILL.md)).
- **Helpers in a test module** are named for what they build (`canceller(orders)`), with a docstring that says what's fixed: "A Canceller over the fakes, its clock stopped at NOW."

## What the drop-in's rules want

| Rule | Wants |
|---|---|
| `PT006` | several names as a tuple of strings, `("order", "refunded")`; one name as a plain string, `"stored"` |
| `PT007` | the rows in a list, each row a tuple or a `pytest.param` |
| `PT011` | `match=` on `pytest.raises` for `ValueError`, `OSError`, `Exception` and the other broad types |
| `PT012` | one simple statement inside `pytest.raises` |
| `FBT001` | a `bool` parameter keyword-only |
| `strict = true` | unique ids, and every marker registered |

These are ruff's defaults for `flake8-pytest-style`; the drop-in changes none of them.
