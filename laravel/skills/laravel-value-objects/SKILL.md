---
name: laravel-value-objects
description: Immutable value objects for domain values. Use when creating or modifying value objects like money, coordinates, or other domain primitives.
---

# Laravel Value Objects

Value objects are **simple, immutable objects** representing domain concepts.

**Related guides:**
- [DTOs](../laravel-dtos/SKILL.md) - DTOs are for data transfer, value objects for domain concepts

## When to Use

**Use value objects when:**
- Complex domain value with behavior
- Immutability required
- Rich validation logic
- Need equality comparison
- Encapsulating domain rules

**Use DTOs when:**
- Transferring data between layers
- No domain behavior needed
- See [DTOs](../laravel-dtos/SKILL.md)

## Simple Value Object

```php
<?php

declare(strict_types=1);

namespace App\Values;

use App\Enums\ProcessResult as ProcessResultEnum;

class ProcessResult
{
    public function __construct(
        public readonly ProcessResultEnum $result,
        public readonly ?string $message = null,
    ) {}

    public static function success(?string $message = null): self
    {
        return new self(ProcessResultEnum::Success, $message);
    }

    public static function skip(?string $message = null): self
    {
        return new self(ProcessResultEnum::Skip, $message);
    }

    public static function fail(?string $message = null): self
    {
        return new self(ProcessResultEnum::Fail, $message);
    }

    public function isSuccess(): bool
    {
        return $this->result === ProcessResultEnum::Success;
    }

    public function isFail(): bool
    {
        return $this->result === ProcessResultEnum::Fail;
    }
}
```

## Money Value Object

**[View full implementation →](references/Money.php)**

## Usage Examples

### ProcessResult

```php
// In actions
return ProcessResult::success('Order processed successfully');
return ProcessResult::skip('Order already processed');
return ProcessResult::fail('Payment declined');

// Checking results
if ($result->isSuccess()) {
    // Handle success
}

if ($result->isFail()) {
    // Handle failure
}
```

### Money (Brick\Money wrapper)

```php
// Creating money values
$price = Money::of(29.99);                    // From major units (£29.99)
$shipping = Money::ofMinor(500);              // From minor units (£5.00)
$usdPrice = Money::of(19.99, 'USD');          // Explicit currency

// Arithmetic (returns new instances)
$total = $price->plus($shipping);
$discounted = $total->minus(Money::of(5));
$refund = $total->negated();

// Comparison
$total->isZero();
$total->isGreaterThan($price);
$total->isEqualTo($other);

// Display
echo $total->format();                        // "£34.99"
echo $refund->format(showNegativeInParentheses: true);  // "(£34.99)"

// Storage (minor units as int)
$total->getMinorAmount();                     // 3499
$total->getCurrencyCode();                    // "GBP"
```

**[→ Full implementation: Money.php](references/Money.php)**

## Key Patterns

### 1. Immutability

Use `final readonly class` — all properties immutable, class cannot be extended:

```php
final readonly class Money implements JsonSerializable, Stringable
{
    private function __construct(private BrickMoney $money) {}
}
```

### 2. Private Constructor + Static Factories

Force controlled instantiation:

```php
private function __construct(/* ... */) {}

public static function of(BigNumber|int|float|string $amount, string $currency = 'GBP'): self
public static function ofMinor(BigNumber|int|float|string $minorAmount, string $currency = 'GBP'): self
public static function success(?string $message = null): self
```

### 3. Library Wrapping

Wrap third-party libraries behind your own API using `__call()` delegation:

```php
public function __call(string $name, array $arguments): mixed
{
    // Delegate to wrapped library, wrapping results as needed
}
```

### 4. Return New Instances

Operations always return new instances (immutability):

```php
$discounted = $price->minus(Money::of(5));  // $price unchanged
$refund = $price->negated();                // $price unchanged
```

### 5. Implement Serialization Interfaces

Value objects typically implement `JsonSerializable`, `Stringable`, and `Wireable` (Livewire) for integration with framework features and storage.

## Directory Structure

```
app/ValueObjects/
├── Money.php
├── ProcessResult.php
├── Coordinate.php
└── EmailAddress.php
```

## Summary

**Value objects:**
- Are immutable (use `readonly`)
- Have static factory methods
- Encapsulate domain logic
- Return new instances from operations
- Validate in constructor

**Use for domain concepts with behavior, not simple data transfer.**
