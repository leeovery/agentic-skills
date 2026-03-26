# DTO Transformers

**Transformer classes transform external system data into internal DTOs.** Use when you need explicit, testable mapping logic between different data structures.

**Related guides:**
- [SKILL.md](../SKILL.md) - Core DTO patterns
- [test-factories.md](test-factories.md) - Test factories for tests
- [helpers.php](./helpers.php) - `collect_get()` helper implementation

## When to Use Transformers

**Use transformers when:**
- Integrating external systems (APIs, webhooks, message queues)
- Multiple data sources map to the same DTO
- Complex transformations with enum conversions, date handling, nested DTOs
- Transformation logic warrants dedicated testing

**Don't use transformers when:**
- Simple data - use `Data::from($array)` directly
- Direct model to DTO - use `Data::from($model)`
- Internal application data - use `::from()` with arrays

## Transformer Structure

```php
<?php

declare(strict_types=1);

namespace App\Data\Transformers;

use App\Data\PaymentData;
use App\Enums\PaymentStatus;

class PaymentDataTransformer
{
    public static function fromStripePaymentIntent(array $payload): PaymentData
    {
        return PaymentData::from([
            'id' => data_get($payload, 'id'),
            'amount' => data_get($payload, 'amount'),
            'currency' => data_get($payload, 'currency'),
            'status' => match (data_get($payload, 'status')) {
                'succeeded', 'paid' => PaymentStatus::Succeeded,
                'processing', 'pending' => PaymentStatus::Pending,
                default => PaymentStatus::Unknown,
            },
            'rawData' => $payload,
        ]);
    }

    public static function fromPayPalOrder(array $order): PaymentData
    {
        return PaymentData::from([
            'id' => data_get($order, 'purchase_units.0.payments.captures.0.id'),
            'amount' => (int) (data_get($order, 'purchase_units.0.amount.value') * 100),
            'currency' => data_get($order, 'purchase_units.0.amount.currency_code'),
            'status' => match (data_get($order, 'status')) {
                'COMPLETED' => PaymentStatus::Succeeded,
                'PENDING' => PaymentStatus::Pending,
                default => PaymentStatus::Unknown,
            },
            'rawData' => $order,
        ]);
    }
}
```

**Naming conventions:**
- `from{SystemName}{DataType}` - e.g., `fromStripePaymentIntent`
- `from{SystemName}{EventType}` - e.g., `fromFinderAutomatedMatch`

## Key Patterns

### Safe Data Access

```php
// Array access with default
'town' => data_get($match, 'address.town', default: null)

// Collection access - use collect_get() helper
'items' => collect_get($payload, 'line_items')
    ->map(fn ($item) => OrderItemData::from([...]))
```

### Enum Transformation

```php
'status' => match (data_get($data, 'type')) {
    'succeeded', 'paid' => PaymentStatus::Succeeded,
    'processing', 'pending' => PaymentStatus::Pending,
    default => PaymentStatus::Unknown,
},
```

### Nested DTO Collections

Three approaches for mapping collections, from simplest to most control:

**1. Direct pass-through** - when keys match exactly:
```php
'images' => $request->input('images'),
```

**2. Map from array** - when keys differ, let package cast:
```php
'images' => collect($request->input('images'))
    ->map(fn (array $image) => [
        'url' => $image['image_url'],
        'size' => $image['file_size'],
        'caption' => $image['alt_text'],
    ]),
```

**3. Map using request with index** - for request helpers or explicit construction:
```php
// Let package cast
'images' => collect($request->input('images'))
    ->map(fn (array $image, int $index) => [
        'url' => $request->input("images.{$index}.image_url"),
        'size' => $request->integer("images.{$index}.file_size"),
        'isPublic' => $request->boolean("images.{$index}.is_public"),
    ]),

// Or construct child DTO directly for explicit control
'images' => collect($request->input('images'))
    ->map(fn (array $image, int $index) => new PostImageData(
        url: $request->input("images.{$index}.image_url"),
        size: $request->integer("images.{$index}.file_size"),
        isPublic: $request->boolean("images.{$index}.is_public"),
    )),
```

Use request with index when you need `boolean()`, `integer()`, `date()` helpers. Use `new Data()` when you want explicit control over child DTO construction.

**First-class callable for reusable transformers:**

```php
'matches' => collect_get($payload, 'loas')
    ->map(MatchDataTransformer::fromFinderMatch(...))
```

### Conditional Nested DTOs

```php
'employer' => ($employer = $suggestion->employer)
    ? EmployerData::from([
        'id' => $employer->id,
        'name' => $employer->name,
    ])
    : null,
```

### Raw Data Preservation

Always preserve the original payload for debugging and auditing:

```php
'rawData' => $payload,
```

### Validation Guards

```php
public static function fromInboundMessage(InboundMessage $message): ResponseData
{
    throw_unless(
        data_get($message->payload, 'result'),
        InvalidResponseException::missingResult($message)
    );

    return ResponseData::from([...]);
}
```

## Organization

```
app/Data/Transformers/
├── PaymentDataTransformer.php
├── Stripe/
│   ├── PaymentDataTransformer.php
│   └── CustomerDataTransformer.php
└── Web/
    └── OrderDataTransformer.php
```

**Principles:**
- One transformer per DTO (can have multiple `from*` methods)
- Subdirectories for external services with multiple transformers
- Name: `{DTO}Transformer`

## Usage

```php
// In controller or message handler
$paymentData = PaymentDataTransformer::fromStripePaymentIntent($webhook['data']);

// Pass to action
resolve(ProcessPaymentAction::class)($paymentData);
```

## Testing

```php
test('transforms stripe payment status to enum', function (): void {
    $data = PaymentDataTransformer::fromStripePaymentIntent([
        'id' => 'pi_123',
        'amount' => 1000,
        'currency' => 'gbp',
        'status' => 'succeeded',
    ]);

    expect($data)
        ->id->toBe('pi_123')
        ->status->toBe(PaymentStatus::Succeeded);
});
```

## Anti-Patterns

**Don't put transformation logic in actions:**
```php
// BAD - transformation hidden in action
class ProcessPaymentAction
{
    public function __invoke(array $stripeData): Payment { /* mapping here */ }
}

// GOOD - explicit transformer
class ProcessPaymentAction
{
    public function __invoke(PaymentData $data): Payment { /* domain logic */ }
}
```

**Don't add business logic to transformers:**
```php
// BAD - sending emails in transformer
public static function fromStripeOrder(array $order): OrderData
{
    $data = OrderData::from([...]);
    Mail::to($data->customer)->send(new HighValueOrder($data)); // NO!
    return $data;
}
```

**Remember:** Transformers only transform. Business logic belongs in actions.

For test factories, see **[test-factories.md](test-factories.md)**.
