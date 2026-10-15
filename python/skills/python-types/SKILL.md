---
name: python-types
description: Python annotations, NewType ids, Money in minor units, frozen value dataclasses and StrEnums, with wire shapes parsed by pydantic at the edge and mapped inward. Use when annotating a signature, defining an id, amount, time, status or value with rules, parsing a request, file or API response, writing a stored or wire format, or holding a secret.
---

# Python Types

A value's type says what it is: an id, an amount, a status. Wire shapes stop at the edge, where pydantic parses them and one function maps each into the domain.

## Core rules

1. **Every signature is annotated, and `Any` appears only where a value really is anything.** *An unannotated parameter is `Any` to the checker, so every mistake that flows through it passes.* Held by ruff's `ANN` and `ANN401`, and pyrefly strict, which reports each implicit `Any`.
2. **Ids and measures are their own types.** `OrderId = NewType("OrderId", str)`. Money is an integer count of minor units in a `Money`, never a `float`. An instant is an aware `datetime` in UTC, and a span a `timedelta`. *Swapped `customer_id, order_id` arguments pass as two strings, and pyrefly refuses them as `CustomerId` and `OrderId`; a float can't hold 0.10 exactly; a naive `datetime` reads one wall clock as another.* Held by pyrefly, and ruff's `DTZ`.
3. **Two parameters of one type, or a `bool`, are keyword-only,** after a `*`. Three or more related values travel as an input dataclass. *`ship(order, True)` says nothing at the call, and two positional ids swap silently.* Held by ruff's `FBT001` to `FBT003`; `PLR0913` stops a signature past five parameters.
4. **A value is `@dataclass(frozen=True, slots=True, kw_only=True)`.** One with rules checks them in `__post_init__`, or in a `parse` classmethod when it's read from text, so every value built keeps them, and each rule has a test that builds a value breaking it. Collections inside are tuples and frozensets, as frozen is shallow, and a changed value is a new one from `copy.replace` ([3.13](https://docs.python.org/3/whatsnew/3.13.html)). *A value nothing can change is safe to share, and `copy.replace` runs `__post_init__` again, so every copy keeps the rules.*
5. **Parameters take the widest abstract type they use** (`Iterable`, `Sequence`, `Mapping`); **returns are concrete** (`list`, `tuple`, `dict`). *A caller passes a list, a tuple or a generator alike, and gets back something it can index, compare and keep.*
6. **An enum is a `StrEnum` with explicit values and `@unique`.** A `match` over one ends `case _: assert_never(x)`. Outside strings become members through the edge's parser, which lists the valid values in its error. *Stored and sent values are names that never renumber, and pyrefly names the member a `match` forgot.* Held by pyrefly, through `assert_never` and the drop-in's `non-exhaustive-match`, raised to an error.
7. **Wire shapes are pydantic models at the edge, `frozen` and with `extra="forbid"`, mapped into the domain's dataclasses by one function per source.** ORM rows, vendor types and wire models never enter the domain. *The domain never bends to a payload, and a format change touches one function.* Held by import-linter, which forbids pydantic, pydantic-settings, httpx2 and typer in the domain packages, `shop.order` and `shop.money` among them.
8. **A stored or wire format has its own encoder, and a test that pins its bytes.** It never goes through `shop.display`. *Otherwise rewording a value for the screen rewrites the files already written.*
9. **A secret is a type that redacts itself** in `__repr__` and `__str__`: `SecretStr` at the edge, `Secret` inside the domain. *Printed, logged or put in an f-string on its own, it can't leak.*
10. **`cast` and every suppression carry their reason:** `# pyrefly: ignore[bad-argument-type]  # the stub is wrong`, never `# type: ignore`. *A cast is a claim the checker takes on trust, and the reason is what review checks instead.* Held by pyrefly, which reads only its own suppressions and reports one that no longer matches ([python-quality](../python-quality/SKILL.md)).

## Pattern

```python
OrderId = NewType("OrderId", str)
CustomerId = NewType("CustomerId", str)
PaymentId = NewType("PaymentId", str)


@unique
class Status(StrEnum):
    """Where an order is in its life. Stored by value, so a value never changes."""

    PENDING = "pending"
    PAID = "paid"
    SHIPPED = "shipped"
    # Claimed by a cancel, before its refund: one that stopped there runs again.
    CANCELLING = "cancelling"
    CANCELLED = "cancelled"

    @property
    def cancellable(self) -> bool:
        """Whether an order in this status can still be cancelled."""
        match self:
            case Status.PENDING | Status.PAID | Status.CANCELLING:
                return True
            case Status.SHIPPED | Status.CANCELLED:
                return False
            case _:
                assert_never(self)


...


@dataclass(frozen=True, slots=True, kw_only=True)
class Order:
    """A customer's order, from placing it to shipping or cancelling it."""

    id: OrderId
    customer_id: CustomerId
    status: Status
    lines: tuple[Line, ...]
    payment_id: PaymentId | None = None
    cancelled_at: datetime | None = None
    cancel_reason: Reason | None = None

    def __post_init__(self) -> None:
        if not self.lines:
            raise ValueError("an order has at least one line")
        if len({line.unit_price.currency for line in self.lines}) > 1:
            raise ValueError("an order's lines are priced in one currency")
        if self.status.paid and self.payment_id is None:
            raise ValueError(f"a {self.status} order has its payment's id")

    ...
```

A status says what else an order holds: a paid or shipped one its payment's id, a pending one none, and a cancelled one, alone, the time and the reason. `Line` holds a `Money`, an integer of minor units and a `Currency`, and asks for a quantity of at least one at a price of zero or more; [values.md](references/values.md) has every rule.

## Decision table

| Value | Type |
|---|---|
| An identity | `OrderId = NewType("OrderId", str)` |
| Money | `Money(minor=1250, currency=Currency.GBP)` |
| An instant, a span | an aware `datetime` in UTC, a `timedelta` |
| A calendar day | a `date`, taken from an instant in a named zone |
| A fixed set | `@unique class Status(StrEnum)` with explicit values |
| A value with rules | a frozen dataclass: `__post_init__`, or `parse` from text |
| A collection inside a value | `tuple[Line, ...]`, `frozenset[str]` |
| Three or more related inputs | keyword-only parameters, or an input dataclass |
| A request, a file line, an API response | a pydantic model at the edge, and one mapping function |
| A secret | `SecretStr` at the edge, `Secret` inside |

## Anti-patterns

- ❌ `def find(order_id: str, customer_id: str)` → `OrderId`, `CustomerId`.
- ❌ `price: float`, or `round(total * 1.2, 2)` → `Money` in minor units, its arithmetic in integers.
- ❌ `datetime.now()`, `datetime.utcnow()`, `date.today()` → the injected `now`, which `main` sets to `partial(datetime.now, UTC)`.
- ❌ `ship(order, True)` → `ship(order, express=True)`.
- ❌ `lines: list[Line]` on a frozen dataclass → `tuple[Line, ...]`.
- ❌ `object.__setattr__(order, "status", ...)` to get round frozen → `copy.replace(order, status=...)`.
- ❌ `class Status(str, Enum)`, `IntEnum`, or `PAID = auto()` → `StrEnum` with explicit values (`UP042` catches the first).
- ❌ `Status(body["status"])` on outside input → a pydantic field typed `Status`, whose error lists the values.
- ❌ `class Order(BaseModel)` in the domain, or `model_validate` on a domain type → a frozen dataclass, a model at the edge, and a mapper.
- ❌ An export line built with `display.money(...)` → its own encoder, its bytes pinned in a test.
- ❌ `token: str` passed into the domain → `Secret`.
- ❌ `cast(Order, row)`, or `# type: ignore` with no code → narrow with `isinstance` or a mapper; `# pyrefly: ignore[code]  # why` where the checker is wrong.

## References

- **[values.md](references/values.md)** — read when defining an id, an amount, a time or a value with rules: `NewType`, `Money`, aware times, `__post_init__` and `parse`, frozen and shallow, `copy.replace`, keyword-only and abstract parameters, secrets, and `Self`, `TypeIs`, `@override` and generics.
- **[enums.md](references/enums.md)** — read when adding a fixed set of values: `StrEnum`, explicit values, `@unique`, behaviour as properties, `match` and `assert_never`, and parsing.
- **[boundaries.md](references/boundaries.md)** — read when parsing a request, a file or an API response, or writing a stored or wire format: pydantic models, `strict`, the mapper, every error at once, ORM and vendor types, pinned formats, secrets, and where a model lives.

## Related skills

- **[python-actions](../python-actions/SKILL.md)** — the operations that take these types, and the edges that parse into them.
- **[python-errors](../python-errors/SKILL.md)** — the exceptions a value's rules raise, and reporting every problem at once.
- **[python-architecture](../python-architecture/SKILL.md)** — `shop.display`, where a value is worded for people.
- **[python-protocols](../python-protocols/SKILL.md)** — the adapters and stores that map vendor shapes and rows into these types.
- **[python-testing](../python-testing/SKILL.md)** — pinned bytes, snapshots, and properties of a parser.
