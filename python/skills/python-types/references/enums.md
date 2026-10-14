# Enums

**A fixed set is a `StrEnum` whose values are written out. What a member means lives in properties on it, so every caller asks the same way.**

Contents: `StrEnum`, always · explicit values · `@unique` · behaviour · `match` and `assert_never` · parsing · `Literal` · checklist.

## `StrEnum`, always

```python
@unique
class Status(StrEnum):
    """Where an order is in its life. Stored by value, so a value never changes."""

    PENDING = "pending"
    PAID = "paid"
    SHIPPED = "shipped"
    CANCELLED = "cancelled"

    @property
    def cancellable(self) -> bool:
        """Whether an order in this status can still be cancelled."""
        match self:
            case Status.PENDING | Status.PAID:
                return True
            case Status.SHIPPED | Status.CANCELLED:
                return False
            case _:
                assert_never(self)
```

- **A member is its value as a string:** `str(Status.PAID)`, an f-string and `json.dumps` all give `paid`, SQLite stores `paid`, and `Status.PAID == "paid"`. A stored or sent value is a name that reads in a log and survives the members being reordered.
- **`class Status(str, Enum)` is the old spelling,** and its `str()` and f-string give `Status.PAID`, not `paid`. ruff's `UP042` flags it ([3.11](https://docs.python.org/3/whatsnew/3.11.html)).
- **Never `IntEnum` for anything stored or sent.** Its numbers mean nothing outside the program, `auto()` renumbers every member after one inserted above them, and a member equals a plain int, so a bare `2` compared with a status runs and means nothing to a reader.
- **A set kept only in memory is a `StrEnum` too.** It still prints in logs and errors, and the day it's stored nothing needs to change.
- **The members are the class:** `list(Status)`, `tuple(Status)`, and `"lost" in Status` is `False`. No separate table of them to fall out of step.

## Explicit values

Write every value out. `auto()` on a `StrEnum` gives the member's name in lower case, so `OUT_OF_STOCK = auto()` is `"out_of_stock"`:

- **The stored value is tied to the member's name,** and renaming the member renames the value: every row and message already written stops parsing.
- **Some values aren't a name in lower case:** `Reason.OUT_OF_STOCK` is `"out-of-stock"`, `Currency.GBP` is `"GBP"`.
- **The value is the contract.** Written out, it's visible in review and in a diff.

## `@unique`

```python
@unique
class Reason(StrEnum):
    """Why an order was cancelled."""

    CUSTOMER = "customer"
    OUT_OF_STOCK = "out-of-stock"
    FRAUD = "fraud"
```

Two members with one value make the second an alias of the first: `Reason.STOCK_OUT = "out-of-stock"` would be `Reason.OUT_OF_STOCK` under a second name, and a `match` naming both would never reach the second. `@unique` raises `ValueError` as the class is made. ruff's `PIE796` catches a duplicate in a class based on `Enum` directly, but not in a `StrEnum`, so the decorator is the check.

## Behaviour

What a member means is a property, with a `match` that names every member:

- **Every member is named,** even those that answer like another. When a member is added, pyrefly reports, through `assert_never`, each `match` that hasn't decided about it, instead of the new member falling into a default.
- **Callers ask the property,** never `order.status in (Status.PENDING, Status.PAID)` inline: that copy of the rule drifts the day a member is added.
- **A label for people is `shop.display`'s,** not a property: wording lives in one module ([python-architecture](../../python-architecture/SKILL.md)).
- **Each change of status with side effects is its own operation** (`Canceller`, `Withdrawer`), guarded by a property such as `cancellable` ([python-actions](../../python-actions/SKILL.md)). There's no state-machine library: the property says what's allowed, and the operation says what happens.

## `match` and `assert_never`

A `match` over an enum ends `case _: assert_never(x)`. In that branch `x` has the type of the members no case named; with every member named, that's `Never`, and the call checks. Leave one out and pyrefly reports it, naming the member:

```
ERROR Argument `Literal[Status.CANCELLED]` is not assignable to parameter `arg` with type `Never` in function `typing.assert_never` [bad-argument-type]
```

- **Without it, a forgotten member can slip through.** A function that returns a value gets `bad-return` for the path that falls through, but one that returns nothing passes: pyrefly's `non-exhaustive-match` is only a warning, which the gate doesn't show.
- **At run time it raises** `AssertionError` with the value, if one outside the set ever arrives.
- **Never `case _: return False`** as the last case: it answers for every member added later, unasked.

## Parsing

A string from outside becomes a member at the edge, through the edge's parser, and both list the valid values when it isn't one:

- **A pydantic field typed `Status`:** `Input should be 'pending', 'paid', 'shipped' or 'cancelled'`, with every other problem in the payload beside it ([boundaries.md](boundaries.md)).
- **A typer option typed `Reason`:** `Invalid value for '--reason': 'bored' is not one of 'customer', 'out-of-stock', 'fraud'.`, exit 2, and `--help` lists them too ([python-actions](../../python-actions/SKILL.md)).
- **Never `Status(text)` on outside input.** Its error, `'lost' is not a valid Status`, names only the bad value, and it reports one problem where the model reports all.
- **Reading back your own stored data** is where `Status(row_value)` belongs, as in the SQLite store: a value outside the set there is a damaged store, and the `ValueError` names it.

## `Literal`

`Settings.log_level` is `Literal["DEBUG", "INFO", "WARNING", "ERROR"]`: a closed set of options at a boundary model, handed straight to a library that wants a string. That's the one place for a `Literal`. Once the set is part of the domain (stored, sent, branched on, or given behaviour), it's a `StrEnum`.

## Checklist

- [ ] A `StrEnum`, never `str, Enum` or `IntEnum`.
- [ ] Every value written out; no `auto()`.
- [ ] `@unique` on the class.
- [ ] Behaviour as properties whose `match` names every member and ends `case _: assert_never(x)`.
- [ ] Outside strings parsed by a pydantic field or a typer option typed as the enum; `Enum(text)` only on your own stored data.
