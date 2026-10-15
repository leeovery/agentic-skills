# Boundaries

**The outside world's shapes stop at the edge: a pydantic model parses each, and one function maps it into the domain's dataclasses.**

Contents: boundary models · `strict` · the mapper · every error at once · going out · ORM and vendor types · pinned formats · secrets at the edge · where a model lives · checklist.

pydantic v2 is used at trust boundaries only: request bodies, messages, files read back, API responses, and the settings. Inside, values are frozen dataclasses ([values.md](values.md)).

## Boundary models

A payload has its own model, in the edge module that reads it:

```python
class ExportedLine(BaseModel):
    """A line as the export holds it."""

    model_config = ConfigDict(extra="forbid", frozen=True, strict=True)

    sku: str
    quantity: int = Field(gt=0)
    unit_price: int = Field(ge=0)


class ExportedOrder(BaseModel):
    """An order as the export holds it: the shape of one line of the file."""

    model_config = ConfigDict(extra="forbid", frozen=True, strict=True)

    id: str
    customer_id: str
    status: Status
    currency: Currency
    lines: tuple[ExportedLine, ...]
    payment_id: str | None
    cancelled_at: AwareDatetime | None
    cancel_reason: Reason | None
```

- **`extra="forbid"`** turns a key the model doesn't know into an error. pydantic's default ignores it, so a misspelt `cancel_reson` is dropped without a word, and a field with a default reads as unset.
- **`frozen=True`:** assigning to a field raises `ValidationError`. What was checked stays checked. It's shallow like a dataclass's, so collections are tuples.
- **Fields are the wire's types,** with the domain's enums where the wire holds one of their values: the model then refuses an unknown status and lists the valid ones ([enums.md](enums.md)). Ids stay `str` here; the mapper makes them `OrderId`.
- **`AwareDatetime`, never `datetime`,** for a time from outside: plain `datetime` accepts `2026-10-14T09:30:00` with no zone, and nobody knows which wall clock it was.
- **A rule the domain holds is said in the model too,** `Field(gt=0)` on a quantity and `Field(ge=0)` on a price, so a bad line is reported with every other problem in it, by field. `Line` still checks both, for every other way a line is built.
- **No defaults on a field the wire must send.** An optional field is `X | None` with no default when the format always writes it, as the export's are, so a line missing it is refused.

## `strict`

A model that parses JSON sets `strict=True`. Checked on pydantic 2.14 with `model_validate_json`:

| Input for the field | Lax, the default | Strict |
|---|---|---|
| `"quantity": "2"` | `2` | refused |
| `"quantity": 2.0` | `2` | refused |
| `"at": 1760434200` | a `datetime` | refused |
| `"at": "2026-10-14T09:30:00Z"` | a `datetime` | a `datetime` |
| `"status": "paid"` | `Status.PAID` | `Status.PAID` |

- **Why:** the wire's types are the contract. Lax mode accepts a string where a number belongs and an integer as a time, and a sender comes to rely on it; strict reports the day a sender changes a type, at the edge, by field.
- **Fed JSON, never a `dict`.** In strict mode `model_validate` on a Python `dict` refuses `"paid"` for a `Status` and an ISO string for a `datetime`, which JSON mode accepts. A strict model is fed `model_validate_json(raw)`, never `model_validate(json.loads(raw))`.

## The mapper

```python
def order_from_export(exported: ExportedOrder) -> Order:
    """Map an exported order into the domain. It maps, and does nothing else."""
    return Order(
        id=OrderId(exported.id),
        customer_id=CustomerId(exported.customer_id),
        status=exported.status,
        lines=tuple(
            Line(
                sku=line.sku,
                quantity=line.quantity,
                unit_price=Money(minor=line.unit_price, currency=exported.currency),
            )
            for line in exported.lines
        ),
        payment_id=None
        if exported.payment_id is None
        else PaymentId(exported.payment_id),
        cancelled_at=None
        if exported.cancelled_at is None
        else exported.cancelled_at.astimezone(UTC),
        cancel_reason=exported.cancel_reason,
    )
```

- **One mapper per source:** `order_from_export`, the SQLite store's row mapping, `order_reply` going out. One shared by two sources carries the first's quirks into the second.
- **It maps and does nothing else:** no I/O, no logging, no settings, no business rule. A rule found while mapping belongs to an operation ([python-actions](../../python-actions/SKILL.md)); a rule of the value runs in its `__post_init__` as the mapper builds it.
- **Times become UTC here.** `.astimezone(UTC)` also swaps pydantic's own `tzinfo` class for `datetime.UTC`, so nothing in the domain holds a pydantic type.
- **A round-trip test with every field set** catches a field added to `Order` that the mapper forgets: `kw_only` makes a missing field a `TypeError` only when it has no default.

## Every error at once

`ValidationError` holds every problem in the payload, each with its location, type and message, not the first one met:

```python
def read(path: Path) -> list[Order]:
    """Every order in the export at path.

    Raises ValueError, noting the line, for the first line that isn't a valid
    order: a ValidationError, with every problem on the line, when it doesn't
    parse.
    """
    orders: list[Order] = []
    with path.open("rb") as file:
        for number, line in enumerate(file, start=1):
            try:
                orders.append(
                    order_from_export(ExportedOrder.model_validate_json(line))
                )
            except ValueError as err:
                err.add_note(f"line {number} of {path}")
                raise
    return orders
```

- **`ValidationError` is a `ValueError`,** so one clause covers a line that doesn't parse and one that parses but breaks a rule in `__post_init__`. `add_note` ([3.11](https://docs.python.org/3/whatsnew/3.11.html)) says where, without a new exception ([python-errors](../../python-errors/SKILL.md)).
- **An HTTP edge answers with all of them:** `err.json(include_url=False, include_input=False)` is the 422 body, every problem by field, without echoing the input back ([python-actions](../../python-actions/SKILL.md)).
- **A test asserts the set of locations,** `{error["loc"][0] for error in err.errors()}`, not the message text.

## Going out

A reply or a message sent has its own model and its own mapper too:

```python
class OrderReply(BaseModel):
    """An order as the API sends it."""

    model_config = ConfigDict(extra="forbid", frozen=True, strict=True)

    id: str
    status: Status
    total_minor: int
    currency: Currency
    cancelled_at: AwareDatetime | None


def order_reply(order: Order) -> OrderReply:
    """Map an order onto the API's shape for it."""
    return OrderReply(
        id=order.id,
        status=order.status,
        total_minor=order.total.minor,
        currency=order.total.currency,
        cancelled_at=None
        if order.cancelled_at is None
        else order.cancelled_at.astimezone(UTC),
    )
```

- **The domain's `Order` is never the body.** Once it's serialised directly, renaming a field renames the API, and the API's quirks (`total_minor`, a field a client needs) bend the domain.
- **The shape names its units:** `total_minor`, an integer, beside `currency`. Never a float of pounds.
- **A test of a reply compares parsed JSON;** nothing stores it. A stored format pins its bytes, below.

## ORM and vendor types

- **Rows never leave the store.** `SqliteOrders.get` builds an `Order` from its row and returns that; no `sqlite3.Row`, ORM object or cursor crosses into the domain ([python-protocols](../../python-protocols/SKILL.md)).
- **A vendor's response is a wire shape.** The adapter parses it into a model of its own, or reads the one field it needs, and returns the domain's type or raises the domain's exception. An SDK's objects stay inside the adapter.
- **A generated client's types** are wire shapes too: mapped at the edge, never passed inward.

## Pinned formats

A format something reads later, such as an export, a stored row or a message another system consumes, has one encoder, and a test pins its bytes:

```python
def encode(order: Order) -> bytes:
    """The order as one line of the export: money in minor units, times in UTC."""
    record = {
        "id": order.id,
        "customer_id": order.customer_id,
        "status": order.status.value,
        "currency": order.total.currency.value,
        "lines": [
            {
                "sku": line.sku,
                "quantity": line.quantity,
                "unit_price": line.unit_price.minor,
            }
            for line in order.lines
        ],
        "payment_id": order.payment_id,
        "cancelled_at": None
        if order.cancelled_at is None
        else order.cancelled_at.astimezone(UTC).isoformat(),
        "cancel_reason": None
        if order.cancel_reason is None
        else order.cancel_reason.value,
    }
    return json.dumps(record, separators=(",", ":")).encode() + b"\n"
```

```python
def test_encode_pins_the_line_format() -> None:
    paris = timezone(timedelta(hours=2))
    order = copy.replace(
        make_order(status=Status.CANCELLED, payment_id="pay-1"),
        cancelled_at=datetime(2026, 10, 14, 11, 30, tzinfo=paris),
        cancel_reason=Reason.OUT_OF_STOCK,
    )

    assert export.encode(order) == (
        b'{"id":"o-1","customer_id":"c-1","status":"cancelled","currency":"GBP",'
        b'"lines":[{"sku":"mug","quantity":2,"unit_price":1250}],"payment_id":"pay-1",'
        b'"cancelled_at":"2026-10-14T09:30:00+00:00","cancel_reason":"out-of-stock"}\n'
    )
```

- **Never through `shop.display`.** If the export called the function that words money for the screen, rewording "25.00 GBP" as "£25.00" would rewrite every file written after it.
- **Every key is written by name, in order,** so renaming a dataclass field can't rename the file's key, and the encoder fixes the separators and the zone.
- **The test's input sits in another zone,** so the bytes prove the encoder converts to UTC.
- **The reader is tested against the writer:** a round trip with every field set. Once an older format is still on disk somewhere, a sample of it joins the tests.
- **Long output is a snapshot** ([python-testing](../../python-testing/SKILL.md)); a line format this short is a literal.

## Secrets at the edge

- **`SecretStr` in the settings:** `Settings.gateway_token`. Its `repr`, its `str` and `model_dump_json()` all give `**********`; `get_secret_value()` is the one way to the value, called once, where it's sent: `main` puts it in the gateway client's `Authorization` header.
- **`hide_input_in_errors=True`** on the settings, so a bad value isn't echoed in the error that reports it. A request model's 422 body leaves the input out with `include_input=False`.
- **Inside the domain, a secret is a `Secret`** ([values.md](values.md)), since the domain can't import pydantic.

## Where a model lives

In the edge module that parses or sends it: `CancelRequest` and `OrderReply` in `shop/api.py`, `CancelRequested` in `shop/consumers.py`, `ExportedOrder` in `shop/storage/export.py`, `Settings` in `shop/settings.py`. Never in the domain, which import-linter enforces:

```toml
[[tool.importlinter.contracts]]
name = "The domain imports no framework, client or wire library"
type = "forbidden"
source_modules = [
    "shop.shipping",
    "shop.settlement",
    "shop.order",
    "shop.money",
    "shop.secret",
]
forbidden_modules = ["pydantic", "pydantic_settings", "httpx2", "typer"]
```

- **The edge imports the domain, never the reverse:** a model may use `Status` and `Currency` as field types, and the domain never sees the model.
- **No shared `schemas` or `models` module.** A model is one source's shape; two sources whose shapes look alike today change apart. `models` is a name the naming guard refuses ([python-quality](../../python-quality/SKILL.md)).
- **A new edge module joins the layer under `shop.cli`** in the `layers` contract, beside `shop.api` and `shop.consumers`, in the same commit ([python-architecture](../../python-architecture/SKILL.md)).

## Checklist

- [ ] Each payload has its own model in its edge module: `extra="forbid"`, `frozen=True`, and `strict=True` when it parses JSON.
- [ ] Times from outside are `AwareDatetime`, converted to UTC in the mapper.
- [ ] One mapper per source, which only maps; ids become `NewType`s there.
- [ ] Every problem reported at once from `ValidationError`, with a note saying where.
- [ ] Replies have their own model and mapper; the domain's dataclasses are never the body.
- [ ] Rows, SDK objects and generated types stay in their adapter.
- [ ] Stored formats have their own encoder, with bytes pinned in a test.
- [ ] Secrets are `SecretStr` at the edge and `Secret` inside, revealed once, where they're sent.
