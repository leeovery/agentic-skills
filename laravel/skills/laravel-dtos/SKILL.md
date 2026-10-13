---
name: laravel-dtos
description: DTOs are spatie/laravel-data classes filled by explicit, typed mapping, usually a transformer per surface; Form Requests validate. Use when creating or changing a Data class, mapping a request or model into one, or writing DTO test factories.
---

# Laravel DTOs

Structured data that crosses a layer boundary, such as an operation's input, travels as a DTO: a spatie/laravel-data class extending the app's own base `Data`. Filling one is explicit. Every input key is read with a typed request helper and handed to a named property, so the mapping reads top to bottom and nothing depends on laravel-data guessing names or casting raw strings. The Form Request validates; the DTO only carries.

## Rules

- **Extend `App\Data\Data`, never Spatie's class.** The base adds `testFactory()` and `has('prop')`, which is false for null, `Optional` or an empty Collection. Copy [Data.php](references/Data.php).
- **Name for what it carries.** `{Verb}{Entity}Data` for an operation's input (`CreateOrderData`), `{Entity}Data` for an entity's shape, `{Descriptor}{Entity}Data` for a part (`ShippingAddressData`). They live in `app/Data/`; a DTO only one service uses lives in `app/Services/{Name}/Data/`.
- **Promoted, typed constructor properties.** A list is a `Collection` with `/** @var Collection<int, OrderItemData> */` above the parameter: laravel-data reads that to build the children. Dates are `CarbonImmutable`.
- **Map with typed request helpers.** `$request->date()`, `->enum()`, `->boolean()`, `->integer()`, `->collect()`, `->input('tags', [])`. Values arrive already typed, so laravel-data never has to cast a raw request string.
- **Map names by hand.** snake_case input goes to camelCase properties key by key. No `#[MapInputName]` or mapper attributes on a DTO filled from a request: the transformer is the one place a reader sees which key lands where.
- **Validation is the Form Request's; laravel-data's is off.** Set `validation_strategy` to `disabled` (see Configuration). Left at the default, `CreateOrderData::from($request)` runs laravel-data's pipeline over the raw request before calling your creation method: rules inferred from camelCase properties meet snake_case input, and a request the Form Request passed fails with a 422.
- **Usually, a transformer per surface.** `App\Data\Transformers\{Surface}\{Dto}Transformer`, the surface mirroring `App\Http\{Surface}` (`Api\V1`, `Web`), with one `fromRequest()` that type-hints the surface's Form Request. Each surface's mapping stays separate and explicit: a V2 API maps into the same DTO without touching V1. A transformer that returns a list is `{Item}DataCollectionTransformer`, returning `Collection<int, ItemData>`. This is the usual choice, not an absolute one; the alternative follows.
- **A model is mapped explicitly too.** Write a `fromModel()` (or a transformer), never a bare `OrderData::from($order)`. The model normaliser matches attributes by name, so snake_case columns miss camelCase properties and nullable ones arrive as null without complaint.
- **Transformers only map.** No writes, events or business rules. Those belong in the action that takes the DTO.
- **Test data comes from `testFactory()`**, never `factory()`, which laravel-data already uses for its creation-context builder.

## Example

```php
<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\OrderPriority;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * @see \Database\Factories\Data\CreateOrderDataFactory
 */
class CreateOrderData extends Data
{
    public function __construct(
        public string $customerEmail,
        public ?CarbonImmutable $deliveryDate,
        public OrderPriority $priority,
        public bool $isGift,
        /** @var Collection<int, OrderItemData> */
        public Collection $items,
    ) {}
}
```

```php
<?php

declare(strict_types=1);

namespace App\Data\Transformers\Api\V1;

use App\Data\CreateOrderData;
use App\Data\OrderItemData;
use App\Enums\OrderPriority;
use App\Http\Api\V1\Requests\CreateOrderRequest;

class CreateOrderDataTransformer
{
    public static function fromRequest(CreateOrderRequest $request): CreateOrderData
    {
        return new CreateOrderData(
            customerEmail: $request->input('customer_email'),
            deliveryDate: $request->date('delivery_date'),
            priority: $request->enum('priority', OrderPriority::class, OrderPriority::Standard),
            isGift: $request->boolean('is_gift'),
            items: $request->collect('items')->map(
                fn (array $item, int $index): OrderItemData => new OrderItemData(
                    productId: $request->integer("items.{$index}.product_id"),
                    quantity: $request->integer("items.{$index}.quantity"),
                ),
            ),
        );
    }
}
```

The controller hands `CreateOrderDataTransformer::fromRequest($request)` to the action. Read [dto-transformers.md](references/dto-transformers.md) when mapping nested lists, writing a collection transformer, mapping an external payload, or testing a transformer.

## The alternative: a creation method on the DTO

laravel-data sends `CreateOrderData::from($x)` to any public static `from…` method whose parameter accepts `$x`. Build inside it with `new self(...)` and the same typed helpers:

```php
class CreateOrderData extends Data
{
    // constructor as in the example above

    public static function fromCreateOrderRequest(CreateOrderRequest $request): self
    {
        return new self(
            customerEmail: $request->input('customer_email'),
            deliveryDate: $request->date('delivery_date'),
            priority: $request->enum('priority', OrderPriority::class, OrderPriority::Standard),
            isGift: $request->boolean('is_gift'),
            items: $request->collect('items')->map(
                fn (array $item, int $index): OrderItemData => new OrderItemData(
                    productId: $request->integer("items.{$index}.product_id"),
                    quantity: $request->integer("items.{$index}.quantity"),
                ),
            ),
        );
    }
}
```

- **Name the method after its source and type-hint that exact class**: `fromCreateOrderRequest(CreateOrderRequest $request)`, `fromModel(Order $order)`. The first method whose parameter accepts the value wins, so a `fromRequest(Request $request)` would swallow every request.
- **Call it through `from()`.** `CreateOrderData::from($request)`, `OrderData::from($order)`.

## Configuration

```php
<?php

declare(strict_types=1);

use Spatie\LaravelData\Support\Creation\ValidationStrategy;

// config/data.php, the two keys that differ from the published file
return [
    'date_format' => DATE_ATOM,
    'validation_strategy' => ValidationStrategy::Disabled->value,
];
```

- **`validation_strategy` is the switch**, not an override of `pipeline()` on the base class. One config line covers every creation path, and a hand-copied pipe list silently drops any pipe the package adds in an upgrade.
- **`date_format` is what DTOs write dates as**, in `toArray()`, JSON and Data-cast model columns, and what they parse back. Request dates never reach it, because `$request->date()` has already parsed them, and test factories pass date objects so they don't depend on it either.

## Test factories

```php
$data = CreateOrderData::testFactory()->make(['isGift' => true]);
$items = OrderItemData::testFactory()->collect(count: 3);
$data = CreateOrderData::testFactory()->express()->make();
```

- **One factory per DTO** in `database/factories/Data/{Dto}Factory.php`, linked from the DTO with an `@see` docblock.
- **Definitions use property names**, compose child DTOs through their own factories, and pass dates as objects.
- **Call `collect()` with named arguments**, `collect(count: 3, attributes: [...])`, so the call reads the same whatever the parameter order.

Read [test-factories.md](references/test-factories.md) when the project has no DTO test factories yet, or when writing a factory.

## Reach for these when the situation calls for it

**A service's API returns uniformly cased payloads.** The response DTO, in `app/Services/{Name}/Data/`, may take a class-level `#[MapInputName(SnakeCaseMapper::class)]` and fill with `ProductData::collect($response->collect('data'))`. Nothing is renamed by hand, so there's no mapping for a transformer to make explicit.

**Dates arrive as strings in a format other than `date_format`**, such as an external payload filled with `from()`. Make `date_format` a list: `[DATE_ATOM, 'd/m/Y']`. The first entry stays the output format; every entry is tried on input.

**A value must be normalised the same way however the DTO is built**, such as a trimmed, lower-cased email. Put it in a formatter, `App\Data\Formatters\EmailFormatter::format(string $email): string`, and apply it in the constructor body: `$this->customerEmail = EmailFormatter::format($this->customerEmail);`. The property can't then be `readonly`.

## Anti-patterns

- ❌ `CreateOrderData::from($request->validated())`, `::from($request->all())`, or `OrderData::from($order)` with no `fromModel()`.
- ❌ `'deliveryDate' => $request->input('delivery_date')`, leaving laravel-data to cast a raw string.
- ❌ `#[MapInputName(SnakeCaseMapper::class)]` on a DTO filled from a request.
- ❌ Validation attributes or a `rules()` method on a Data class.
- ❌ `OrderItemData::collect($request->input('items'))` into a `Collection` property. `collect()` returns the container it's given, so an array in is an array out and the constructor throws a `TypeError`. Pass `$request->collect('items')`.
- ❌ `class CreateOrderData extends \Spatie\LaravelData\Data`.
- ❌ `public static function fromRequest(Request $request)` as a creation method on the DTO.
- ❌ A write, event or business rule inside a transformer.
- ❌ `CreateOrderData::factory()` for test data.

## Related

- [laravel-actions](../laravel-actions/SKILL.md): the actions DTOs are passed to
- [laravel-validation](../laravel-validation/SKILL.md): the Form Requests that validate before mapping
- [laravel-controllers](../laravel-controllers/SKILL.md): where the transformer is called
- [laravel-models](../laravel-models/SKILL.md): casting a JSON column to a Data class
- [laravel-providers](../laravel-providers/SKILL.md): `Date::use(CarbonImmutable::class)`, and the provider that registers the DTO factory resolver
- [laravel-services](../laravel-services/SKILL.md): the services that own their DTOs
- [laravel-testing](../laravel-testing/SKILL.md): testing style
