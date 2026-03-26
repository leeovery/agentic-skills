# Type Safety & Strict Typing

Every file uses strict types and comprehensive type declarations.

**Related guides:**
- [DTOs](../../laravel-dtos/SKILL.md) - Typed DTOs with property promotion
- [Actions](../../laravel-actions/SKILL.md) - Typed action parameters and returns
- [Models](../../laravel-models/SKILL.md) - Model casts and property types

## Mandatory Strict Types Declaration

**Every PHP file must begin with:**

```php
<?php

declare(strict_types=1);
```

**No exceptions.** This applies to:
- Actions
- DTOs
- Controllers
- Models
- Form Requests
- Builders
- All classes

## Type All Parameters

```php
// ✅ Good
public function createOrder(
    User $user,
    CreateOrderData $data,
    bool $sendNotification = true
): Order {
    // ...
}

// ❌ Bad - missing types
public function createOrder($user, $data, $sendNotification = true) {
    // ...
}
```

## Type All Return Values

```php
// ✅ Good
public function isPaid(): bool
{
    return $this->payment_status === PaymentStatus::Paid;
}

public function getTotal(): int
{
    return $this->items->sum('total');
}

public function findUser(int $id): ?User
{
    return User::find($id);
}

public function logActivity(): void
{
    // No return value
}

// ❌ Bad - missing return types
public function isPaid()
{
    return $this->payment_status === PaymentStatus::Paid;
}
```

## Nullable Types

Two syntaxes available:

```php
// Short syntax (preferred)
public ?string $description = null;
public ?int $userId = null;

// Union syntax (alternative)
public string|null $description = null;
public CarbonImmutable|null $completedAt = null;
```

## Union Types

```php
public function process(string|int $identifier): User|Guest
{
    // ...
}

public static function fromRequest(
    CreateOrderRequest|UpdateOrderRequest $request
): OrderData {
    // ...
}
```

## Property Type Hints

**Use constructor property promotion in DTOs:**

```php
class OrderData extends Data
{
    public function __construct(
        public string $customerName,
        public int $totalAmount,
        public bool $isPaid,
        public OrderStatus $status,
        public CarbonImmutable $createdAt,
        public ?CarbonImmutable $paidAt,
        /** @var Collection<int, OrderItemData> */
        public Collection $items,
    ) {}
}
```

**All properties must be typed.**

## PHPDoc for Complex Types

Use PHPDoc when native PHP types aren't sufficient:

### Collections

```php
/**
 * @var Collection<int, OrderItemData>
 */
private Collection $items;
```

### Arrays

```php
/**
 * @var array<string, mixed>
 */
public array $metadata;

/**
 * @var int[]
 */
public array $productIds;
```

### Method Documentation

```php
/**
 * @param  array<int, string>  $tags
 * @return Collection<int, Product>
 */
public function findByTags(array $tags): Collection
{
    // ...
}
```

## Common Type Patterns

### Models

```php
class Order extends Model
{
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,         // Enum
            'total' => 'integer',
            'is_paid' => 'boolean',
            'metadata' => OrderMetadataData::class, // DTO
            'completed_at' => 'datetime',           // Carbon
        ];
    }
}
```

### Actions

```php
class CreateOrderAction
{
    public function __invoke(User $user, CreateOrderData $data): Order
    {
        // Return type required
    }
}
```

### Controllers

```php
public function store(
    CreateOrderRequest $request,
    CreateOrderAction $action
): OrderResource {
    // All params and return typed
}
```

## Benefits

1. **IDE Support** - Better autocomplete and refactoring
2. **Early Error Detection** - Type errors caught immediately
3. **Self-Documentation** - Types document intent
4. **Confidence** - Know exactly what you're working with
5. **Refactoring** - Safe to rename and restructure

## Enforcement

Use architecture tests to enforce strict types:

```php
arch('app files declare strict types')
    ->expect('App')
    ->toUseStrictTypes();

arch('test files declare strict types')
    ->expect('Tests')
    ->toUseStrictTypes();
```

See [quality.md](quality.md) for complete architecture test examples.
