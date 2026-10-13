# DTO transformers

## A list the action takes on its own

When an action takes a list rather than a DTO that holds one, the mapping gets a collection transformer, `{Item}DataCollectionTransformer`. When several Form Requests on one surface carry the same input, one transformer takes them all as a union.

```php
<?php

declare(strict_types=1);

namespace App\Data\Transformers\Api\V1;

use App\Data\OrderItemData;
use App\Http\Api\V1\Requests\CreateOrderRequest;
use App\Http\Api\V1\Requests\UpdateOrderRequest;
use Illuminate\Support\Collection;

class OrderItemDataCollectionTransformer
{
    /**
     * @return Collection<int, OrderItemData>
     */
    public static function fromRequest(CreateOrderRequest|UpdateOrderRequest $request): Collection
    {
        return $request->collect('items')->keys()->map(
            fn (int $index): OrderItemData => new OrderItemData(
                productId: $request->integer("items.{$index}.product_id"),
                quantity: $request->integer("items.{$index}.quantity"),
            ),
        );
    }
}
```

Each child is read back through the request by index, so every value still goes through a typed helper. Pass a list straight through, `TagData::collect($request->collect('tags'))`, only when every child key is already a property name and every value is a string. Pass `$request->collect()`, not `input()`: `collect()` returns the container it's given.

## An external payload

A transformer that maps one service's payloads lives with that service, in `app/Services/{Name}/Transformers/`, next to its `Data/`. Read nested or optional keys with `data_get()`, and translate the service's values onto your own enum with `match`.

```php
<?php

declare(strict_types=1);

namespace App\Services\Shipping\Transformers;

use App\Services\Shipping\Data\ShipmentData;
use App\Services\Shipping\Enums\ShipmentStatus;
use Illuminate\Support\Facades\Date;

class ShipmentDataTransformer
{
    public static function fromPayload(array $payload): ShipmentData
    {
        $deliveredAt = data_get($payload, 'delivery.completed_at');

        return new ShipmentData(
            reference: data_get($payload, 'id'),
            trackingNumber: data_get($payload, 'tracking.number'),
            status: match (data_get($payload, 'status')) {
                'in_transit', 'out_for_delivery' => ShipmentStatus::InTransit,
                'delivered' => ShipmentStatus::Delivered,
                default => ShipmentStatus::Unknown,
            },
            deliveredAt: $deliveredAt ? Date::parse($deliveredAt) : null,
        );
    }
}
```

A list in a payload maps with the same method as a first-class callable: `collect(data_get($payload, 'shipments', []))->map(ShipmentDataTransformer::fromPayload(...))`.

## Testing a transformer

Build the Form Request with `::create()` and call the transformer. No route, controller or validation runs, so the test pins only the mapping.

```php
<?php

declare(strict_types=1);

use App\Data\Transformers\Api\V1\CreateOrderDataTransformer;
use App\Enums\OrderPriority;
use App\Http\Api\V1\Requests\CreateOrderRequest;

test('will map a create order request', function (): void {
    $request = CreateOrderRequest::create('/', 'POST', [
        'customer_email' => 'jane@example.com',
        'delivery_date' => '2026-03-01',
        'priority' => 'express',
        'is_gift' => '1',
        'items' => [['product_id' => '7', 'quantity' => '2']],
    ]);

    $data = CreateOrderDataTransformer::fromRequest($request);

    expect($data)
        ->customerEmail->toBe('jane@example.com')
        ->priority->toBe(OrderPriority::Express)
        ->isGift->toBeTrue()
        ->and($data->deliveryDate->toDateString())->toBe('2026-03-01')
        ->and($data->items->sole())
        ->productId->toBe(7)
        ->quantity->toBe(2);
});
```

String input in the test is deliberate: it is what a real request carries, and it proves the typed helpers do the casting.
