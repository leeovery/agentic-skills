# Ownership

**Every meaning the program has is computed in one place, and everything else calls it.**

Contents: what counts as a rule · who owns it · before computing anything a screen shows · lifting a copy · shared rules first · guarding it.

## What counts as a rule

- **How a value reads to people:** an amount of money, an instant, a ratio, a count past a thousand.
- **A decision two places make:** whether an order can be cancelled, whether a refund is due, which payment a refund goes to.
- **A derived figure:** a total, a rate, a status worked out from fields.
- **An order of things,** when two screens list the same things.

If two outputs show it, or two code paths decide on it, it has one owner.

## Who owns it

- **A figure or decision about a domain type** belongs to that type's package: a property when it reads one value (`order.total`, `status.cancellable`), a function in a module named for the job when it reads many (`ratios.cancel_ratio(orders)`).
- **How a value reads to people** belongs to `shop.display`.
- **How a value is stored or sent** belongs beside its type, as its own encoder with a test that pins its bytes. It never calls the display wording, or a display tweak rewrites stored data ([python-types](../../python-types/SKILL.md)).

## Before computing anything a screen shows

1. **Search for it:** the words the figure carries on screen ("cancelled"), the fields it reads (`status is Status.CANCELLED`), the specs and calls that word it (`:.1f`, `strftime(`).
2. **An owner exists:** call it.
3. **It sits inline in another caller:** lift it (below), then call it from both.
4. **Nothing exists:** write it in its owner, with tests, then call it.

## Lifting a copy

A report needs the cancellation rate that a summary already shows, and the summary works it out inline. The wrong way, as found:

```python
# src/shop/summary.py, before: the rule and its wording, inline
"""A day's orders, summed up for the person running the shop."""

from collections.abc import Sequence

from shop.order.order import Order, Status


def headline(orders: Sequence[Order]) -> str:
    """How many of the day's orders were cancelled."""
    cancelled = sum(1 for order in orders if order.status is Status.CANCELLED)
    return f"cancelled {100 * cancelled / len(orders):.1f}%"
```

Copying this into the report makes two owners, and the first fix lands in one of them. This one already has a bug: on a day with no orders it raises `ZeroDivisionError`. python-quality's wording guard reports its last line, `src/shop/summary.py:11: words a value with a format spec: call shop.display`.

Lifted, the rule moves to `shop.order`, the wording to `shop.display`, and each caller shrinks to a call:

```python
# src/shop/order/ratios.py
"""Figures worked out over many orders."""

from collections.abc import Collection

from shop.order.order import Order, Status


def cancel_ratio(orders: Collection[Order]) -> float:
    """The share of orders cancelled, from 0 to 1: 0 when there are none."""
    if not orders:
        return 0.0
    cancelled = sum(1 for order in orders if order.status is Status.CANCELLED)
    return cancelled / len(orders)
```

```python
# src/shop/display.py
...


def percent(ratio: float) -> str:
    """Word a ratio from 0 to 1 as a percentage to one decimal place: "12.5%"."""
    return f"{ratio:.1%}"
```

```python
# tests/order/test_ratios.py
"""Figures over many orders, at their edges: none, and all of one kind."""

from shop.order.order import Status
from shop.order.ratios import cancel_ratio
from tests.order.builders import make_order


def test_the_cancel_ratio_of_no_orders_is_zero() -> None:
    assert cancel_ratio([]) == 0


def test_the_cancel_ratio_counts_only_cancelled_orders() -> None:
    orders = [
        make_order(status=Status.CANCELLED),
        make_order(status=Status.PAID),
        make_order(status=Status.SHIPPED),
        make_order(status=Status.PENDING),
    ]

    assert cancel_ratio(orders) == 0.25
```

The summary and the report each word it as `f"cancelled {display.percent(cancel_ratio(orders))}"`.

The steps, in one change:
1. Write the owner's function and its tests first, the case the copy got wrong among them (no orders).
2. Point the existing caller at it. Its output stays the same except where it was wrong, and the PR says where.
3. Write the new caller.
4. Where two copies already disagree, decide once which is right, test that case in the owner, and point both at it.
5. Search again for a third copy.

## Shared rules first

Before splitting work across agents, PRs or people:

- [ ] List each meaning more than one part will show or decide.
- [ ] Name each one's owner module and signature.
- [ ] Test each at its hard moments, not one easy instant: zero and empty, the boundary, just after a reset, data missing or stale, the largest value.
- [ ] Build them first, in one change, merged before the parts start.
- [ ] Brief each part with the owners it calls, and that computing one of these meanings itself is a bug.
- [ ] Guard each rule a part could break quietly (below).
- [ ] Where two outputs show one fact, add a test that they agree.

## Guarding it

A rule that matters is held by a gate, not by the next reviewer:

| The risk | The gate |
|---|---|
| a format spec, `format()` or `strftime()` outside `shop.display` | the wording guard |
| an import only one package may make, or a framework in the domain | an import-linter `layers` or `forbidden` contract |
| a call only `main` may make: the environment, the clock, exiting | ruff's `banned-api`, with `per-file-ignores` naming `shop/cli.py` |
| a raw read outside its owner (`.cancelled_at` outside `shop.order`) | an `ast` guard on the attribute's name, with an allowance |

Each guard keeps an allowance for the copies not yet lifted, and it only shrinks. The wording guard and the guard shape are in [python-quality](../../python-quality/SKILL.md). A guard on an attribute's name sees the name, not the type that owns it: a `cancelled_at` on another class is reported too, so pick a name only the owner's type has.
