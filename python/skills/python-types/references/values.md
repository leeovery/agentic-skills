# Values

**A type says what a value means, so the checker keeps meanings apart and an invalid value can't be built.**

Contents: ids · money · time · values with rules · frozen is shallow · parameters · secrets · `Self`, `TypeIs`, `@override` and generics · checklist.

## Ids

```python
OrderId = NewType("OrderId", str)
CustomerId = NewType("CustomerId", str)
PaymentId = NewType("PaymentId", str)
```

- **The checker keeps them apart:** a `CustomerId` passed where an `OrderId` is wanted, or a bare `"o-1"`, is pyrefly's `bad-argument-type`.
- **It costs nothing at run time.** `OrderId("o-1")` returns the string itself, so storing, comparing and hashing are a `str`'s.
- **One conversion, at the edge.** A raw string becomes an `OrderId` where it arrives: a command's argument, a path value, a mapper. Inside, it stays an `OrderId`.
- **It checks nothing.** An id with a format rule is a value with rules, below, with a `parse`.
- **New code and the signatures a change touches,** not a sweep. When a change touches `def get(self, order_id: str)`, it becomes `OrderId`, and its callers in the change follow.
- **A loop index, a byte count or a local scratch string** stays a plain type. The rule is for values that cross a function carrying a meaning another value of the same type could be mistaken for.

## Money

An amount is a count of a currency's minor unit, such as pence, held as an `int`:

```python
AMOUNT = re.compile(
    r"(?P<sign>-?)(?P<units>[0-9]+)\.(?P<cents>[0-9]{2}) (?P<code>[A-Z]{3})"
)


@unique
class Currency(StrEnum):
    """A currency the shop sells in, by its ISO 4217 code. Each has two decimal places."""

    GBP = "GBP"
    EUR = "EUR"
    USD = "USD"


@dataclass(frozen=True, slots=True, kw_only=True)
class Money:
    """An amount in a currency's minor unit: 1250 GBP is £12.50."""

    minor: int
    currency: Currency

    @classmethod
    def parse(cls, text: str) -> Self:
        """Read an amount written as "12.50 GBP", or "-12.50 GBP" for a refund."""
        found = AMOUNT.fullmatch(text)
        if found is None or found["code"] not in Currency:
            raise ValueError(f"{text!r} is not an amount like '12.50 GBP'")
        minor = int(found["units"]) * 100 + int(found["cents"])
        return cls(
            minor=-minor if found["sign"] else minor, currency=Currency(found["code"])
        )

    def __add__(self, other: Money) -> Money:
        if other.currency != self.currency:
            raise ValueError(f"can't add {other.currency} to {self.currency}")
        return Money(minor=self.minor + other.minor, currency=self.currency)

    def __mul__(self, times: int) -> Money:
        return Money(minor=self.minor * times, currency=self.currency)

    def split(self, parts: int) -> tuple[Money, ...]:
        """Divide into parts that sum to the whole: 10.00 in three is 3.34, 3.33, 3.33."""
        if parts < 1:
            raise ValueError(f"can't split an amount into {parts} parts")
        each, left = divmod(self.minor, parts)
        return tuple(
            Money(minor=each + (1 if part < left else 0), currency=self.currency)
            for part in range(parts)
        )
```

- **Never a `float`.** `0.1 + 0.2 != 0.3`, and a total that's out by a penny is a refund that doesn't match its payment.
- **Amounts add only within one currency;** across two, it raises. It multiplies only by an `int`, a quantity.
- **Dividing goes through `split`,** which hands the pennies left over to the first parts, so the parts sum to the whole. A rate, such as a 15% discount, is a function that does its arithmetic in integers and states how it rounds.
- **`sum` needs a start:** `0 + Money` fails, so `Order.total` starts from its first line.
- **`parse` matches the whole text against one pattern,** `AMOUNT`. `Decimal(text)` would also take `1_000.00`, `12E-2`, `+12.50` and `.50`, and `\d` matches any script's digits. No `Decimal` as a field either: the domain's arithmetic stays in integers.
- **How an amount reads to people** ("1,234.50 GBP") is `shop.display.money`'s job ([python-architecture](../../python-architecture/SKILL.md)). How it's stored or sent is the format's own encoder: here, `minor` and `currency` as two fields ([boundaries.md](boundaries.md)).
- **A currency with other than two decimals** (yen has none) needs the digits on `Currency`, and display and `parse` reading them.

## Time

- **An instant is an aware `datetime` in UTC.** Code that needs the time takes `now: Callable[[], datetime]`; `main` passes `partial(datetime.now, UTC)`, and a test passes a fixed instant ([python-protocols](../../python-protocols/SKILL.md)). ruff's `DTZ` reports a naive `now()`, `today()`, `fromtimestamp()` or `strptime()`, and the drop-in bans `datetime.now` outside the module that holds `main`.
- **Convert at the edge:** an aware value from outside becomes UTC with `.astimezone(UTC)` in the mapper. A naive one is refused there, not guessed at: pydantic's `AwareDatetime` refuses it ([boundaries.md](boundaries.md)).
- **A span is a `timedelta`,** never an `int` of seconds. Where a format forces a number, its field names the unit (`timeout_seconds`) and the mapper converts it.
- **A calendar day is a `date`,** taken from an instant in a named zone: `now().astimezone(ZoneInfo("Europe/London")).date()`. Which day it is depends on where; `date.today()` asks the machine (`DTZ011`).

## Values with rules

A rule the type can't say is checked when the value is built, so every value built keeps it:

- **`__post_init__`** when the fields are already typed and the rule is about them: an order has at least one line, and a line a quantity of at least one. What a status implies about the other fields is a rule too: a paid order holds its payment's id (`Status.paid`). `Order` checks its rules in the [SKILL.md](../SKILL.md#pattern) pattern, and `Line` its own, below. A rule nothing checks holds nowhere: a paid order with no payment id would be cancelled without its refund.
- **A `parse` classmethod** when the value is read from text: `Money.parse("12.50 GBP")`. It returns `Self`, raises `ValueError` naming the text and the shape it wanted, and hands typed fields to the constructor.
- **`copy.replace(order, status=Status.CANCELLED, cancelled_at=now, cancel_reason=reason)`** ([3.13](https://docs.python.org/3/whatsnew/3.13.html)) is how a value changes: it builds a new one through `__init__`, so `__post_init__` runs again, and `copy.replace(order, lines=())` raises. It works on any dataclass, named tuple or `datetime`; `dataclasses.replace` only on dataclasses.
- **No `__init__` by hand.** A default goes on the field; a derived value is a `@property` (`Order.total`), computed when read, never a field that can disagree with the others.
- **Not for a value with no rules.** A dataclass around one unconstrained string is ceremony: a `NewType` does the job.

`Line` checks its own the same way:

```python
# src/shop/order/order.py
@dataclass(frozen=True, slots=True, kw_only=True)
class Line:
    """One product on an order, at the price it sold for."""

    sku: str
    quantity: int
    unit_price: Money

    def __post_init__(self) -> None:
        if self.quantity < 1:
            raise ValueError(f"a line's quantity is at least 1, not {self.quantity}")
        if self.unit_price.minor < 0:
            raise ValueError("a line's unit price is zero or more")

    ...
```

## Frozen is shallow

`frozen=True` stops a field being reassigned, not a list in a field being changed:

```python
# ❌ Frozen, and still changeable: append works, and hash() raises TypeError.
@dataclass(frozen=True, slots=True, kw_only=True)
class Basket:
    skus: list[str]
```

- **Collections inside a value are tuples and frozensets:** `lines: tuple[Line, ...]`. The value is then unchangeable all the way down, and hashable.
- **A mapping inside a value** is a tuple of pairs on 3.14; `frozendict` arrives in 3.15 ([python-quality](../../python-quality/SKILL.md)).
- **`slots=True`** gives the instance no `__dict__`, so `order.stauts = ...` fails as well (`missing-attribute` in pyrefly), not only the fields' names.
- **`kw_only=True`** makes every construction name its fields: `Line(sku="mug", quantity=2, unit_price=price)`, where two positional ints could swap.

## Parameters

- **Keyword-only after the first:** `cancel(order_id, *, reason)`. The subject comes first; anything that could be confused with it, or with another, is named at the call.
- **No positional `bool`:** `ship(order, express=True)`. ruff's `FBT001` reports a `bool` parameter before the `*`, `FBT002` a positional one with a default, and `FBT003` a `True` passed positionally.
- **Three or more related values** travel as an input dataclass, frozen and keyword-only like any value. `PLR0913` stops a signature past five.
- **Take the widest type you use:** `Iterable[Order]` for one pass, `Sequence[Order]` to index it or go through it twice, `Mapping[str, Money]` to look things up. A caller then passes a list, a tuple, a generator or a dict without converting.
- **Return a concrete type:** `tuple[Order, ...]`, `list[Order]`. A caller can index it, take its length and keep it; an `Iterable` return makes every caller wrap it in `list()` to find out.

## Secrets

At the edge a secret is pydantic's `SecretStr` ([boundaries.md](boundaries.md)). The domain can't import pydantic, so a secret that must travel inside it gets its own type:

```python
@dataclass(frozen=True, slots=True, kw_only=True, repr=False)
class Secret:
    """A credential, shown as ******** wherever it's printed, formatted or logged."""

    value: str

    def __repr__(self) -> str:
        return "Secret(value='********')"

    def __str__(self) -> str:
        return "********"
```

- **`str`, `repr`, an f-string, `%s` in a log line, and a list holding it** all show `********`; a test checks each.
- **`json.dumps` refuses it,** so it can't land in a payload by accident. `dataclasses.asdict` does reach the value: a dataclass holding a `Secret` is never dumped whole.
- **It never goes into an exception's message,** which is printed and logged further than the code that raised it can see.
- **Most secrets never enter the domain.** The gateway's token goes from `Settings` to the client's header in `main`.

## `Self`, `TypeIs`, `@override` and generics

- **`Self`** ([3.11](https://docs.python.org/3/whatsnew/3.11.html)) types a classmethod that builds its own class (`Money.parse`) and a method that returns a copy of `self`.
- **`TypeIs[X]`** ([3.13](https://docs.python.org/3/whatsnew/3.13.html)) is the return type of a predicate that narrows: `def is_order(value: object) -> TypeIs[Order]`. It narrows both branches, where `TypeGuard` narrows only the true one, and a plain `bool` narrows neither.
- **`@override`** ([3.12](https://docs.python.org/3/whatsnew/3.12.html)) goes on every method that overrides a parent's; pyrefly strict reports a missing one as `missing-override-decorator`, so a renamed parent method can't leave an orphan behind. It doesn't ask for one on `__repr__`, `__str__` or `__eq__` overriding `object`'s.
- **A generic earns its place** when one body serves several types: `def first[T](items: Sequence[T]) -> T` ([3.12](https://docs.python.org/3/whatsnew/3.12.html)), never `TypeVar("T")`. A domain type is never generic for one use.
- **A `type` alias** names a long annotation once: `type Settle = Callable[[Iterable[PaymentId]], list[PaymentId]]`.
- **`cast`** is a claim the checker takes on trust: narrow with `isinstance`, a mapper or `TypeIs` instead, and where nothing else will do, say why on the line.

## Checklist

- [ ] Every signature annotated; `Any` only where a value is anything.
- [ ] Each id crossing a function is a `NewType`; money is `Money`; times are aware and in UTC; spans are `timedelta`.
- [ ] A value is a frozen, slotted, keyword-only dataclass; its rules run in `__post_init__` or `parse`.
- [ ] Collections inside values are tuples and frozensets; changes go through `copy.replace`.
- [ ] Two parameters of one type, or a `bool`, are keyword-only; three or more related values are a dataclass.
- [ ] Parameters abstract, returns concrete.
- [ ] A secret has a redacting type, and no structure holding it is dumped whole.
