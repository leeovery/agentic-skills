# DTO test factories

`CreateOrderData::testFactory()` returns a factory that builds a filled DTO for a test. It mirrors Eloquent's model factories without being one: a Data class isn't a model, and laravel-data already uses the name `factory()`.

## Setup

Four files, copied as they are:

| File | Goes in | Does |
|---|---|---|
| [Data.php](Data.php) | `app/Data/Data.php` | The base every DTO extends; uses `HasTestFactory` |
| [HasTestFactory.php](HasTestFactory.php) | `app/Data/Concerns/HasTestFactory.php` | Adds `testFactory()`, resolved through Laravel's factory-name resolver |
| [DataTestFactory.php](DataTestFactory.php) | `database/factories/Data/DataTestFactory.php` | Base factory: `make()`, `collect()`, `state()` |
| [AppServiceProvider.php](AppServiceProvider.php) | merge into `register()` of the app's provider | Sends classes ending in `Data` to `Database\Factories\Data\`, everything else to `Database\Factories\` |

The resolver replaces Laravel's own guess, so model factories resolve by class basename as well: `App\Models\Order` gives `Database\Factories\OrderFactory`.

## Writing a factory

```php
<?php

declare(strict_types=1);

namespace Database\Factories\Data;

use App\Data\CreateOrderData;
use App\Data\OrderItemData;
use App\Enums\OrderPriority;

/**
 * @extends DataTestFactory<CreateOrderData>
 */
class CreateOrderDataFactory extends DataTestFactory
{
    public function definition(): array
    {
        return [
            'customerEmail' => fake()->safeEmail(),
            'deliveryDate' => fake()->dateTimeBetween('+1 day', '+1 month'),
            'priority' => OrderPriority::Standard,
            'isGift' => false,
            'items' => OrderItemData::testFactory()->collect(count: 2),
        ];
    }

    public function express(): static
    {
        return $this->state([
            'priority' => OrderPriority::Express,
            'deliveryDate' => fake()->dateTimeBetween('+1 day', '+2 days'),
        ]);
    }
}
```

- **Keys are property names**, camelCase. `make()` passes the merged array to `CreateOrderData::from()`.
- **Child DTOs come from their own factories**, so a change to `OrderItemData` is made in one place.
- **Dates are date objects**, never formatted strings, so the factory never has to know the format. `make()` goes through `from()`, which formats each date with `date_format` and parses it back into the property's `CarbonImmutable`: with `DATE_ATOM` that keeps the seconds and offset and drops microseconds, so compare factory dates to the second.
- **A state takes an array.** Unlike an Eloquent factory state it receives no `$attributes`, so it can't derive one value from another.
- **The DTO links back** with `/** @see \Database\Factories\Data\CreateOrderDataFactory */`.

## Using one

```php
$data = CreateOrderData::testFactory()->make();
$data = CreateOrderData::testFactory()->make(['customerEmail' => 'jane@example.com']);
$data = CreateOrderData::testFactory()->express()->make(['isGift' => true]);
$items = OrderItemData::testFactory()->collect(count: 3, attributes: ['quantity' => 1]);
```

Overrides replace the definition after any states. `collect()` returns a `Collection` and takes named arguments.
