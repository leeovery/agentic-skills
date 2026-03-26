# Code Style Guidelines

Consistent code style through Laravel Pint and declarative coding practices.

**Related guides:**
- [type-safety.md](type-safety.md) - Type declarations
- [quality.md](quality.md) - Pint configuration and testing
- [package-extraction.md](../../laravel-packages/references/package-extraction.md) - Code style for packages

## Declarative Code Philosophy

**Always write declarative code.** The goal is short, well-named methods that are self-documenting.

### Key Principles

1. **Extract imperative instructions** behind well-named methods
2. **Name things clearly** - the name explains what it does
3. **Keep methods short** - extract parts into smaller methods
4. **Self-documenting code** - code explains itself without comments

### Example

**✅ Good - Declarative:**

```php
public function __invoke(Order $order): Order
{
    $this->guard($order);

    return DB::transaction(function () use ($order): Order {
        $this->cancelOrder($order);
        $this->refundPayment($order);
        $this->notifyCustomer($order);

        return $order;
    });
}

private function guard(Order $order): void
{
    throw_unless(
        $order->isPending() || $order->isProcessing(),
        OrderException::cannotCancelOrder($order)
    );
}
```

**❌ Bad - Imperative:**

```php
public function __invoke(Order $order): Order
{
    // Check if order can be cancelled
    if (! in_array($order->status, ['pending', 'processing'])) {
        throw new OrderException('Cannot cancel this order');
    }

    return DB::transaction(function () use ($order): Order {
        // Update order
        $order->status = 'cancelled';
        $order->cancelled_at = now();
        $order->save();

        // Process refund
        if ($order->payment_id) {
            $payment = Payment::find($order->payment_id);
            $payment->status = 'refunded';
            $payment->save();
        }

        // Send email
        Mail::to($order->customer->email)->send(new OrderCancelled($order));

        return $order;
    });
}
```

**When you write a comment to explain code**, extract that code into a well-named method instead.

## File Structure

Every PHP file follows this structure:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Order;

use App\Data\CreateOrderData;
use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

class CreateOrderAction
{
    public function __construct(
        private readonly CalculateOrderTotalAction $calculateTotal,
    ) {}

    public function __invoke(User $user, CreateOrderData $data): Order
    {
        $this->guard($user, $data);

        return DB::transaction(function () use ($user, $data): Order {
            // Implementation
        });
    }

    private function guard(User $user, CreateOrderData $data): void
    {
        // Guards
    }
}
```

## Ordering Rules

### File-Level

1. `declare(strict_types=1)`
2. Namespace
3. Use statements (alphabetically, grouped by vendor)
4. Class declaration

### Class-Level

1. Traits
2. Constants
3. Properties
4. Constructor
5. Public methods
6. Protected methods
7. Private methods

**Note:** Laravel Pint handles use statement ordering automatically.

## Laravel Pint Configuration

**Use Laravel Pint for all code formatting.**

### pint.json

```json
{
  "preset": "laravel",
  "rules": {
    "declare_strict_types": true,
    "global_namespace_import": {
      "import_classes": true,
      "import_constants": true,
      "import_functions": true
    },
    "ordered_class_elements": {
      "order": [
        "use_trait",
        "case",
        "constant",
        "property_public",
        "property_protected",
        "property_private",
        "construct",
        "destruct",
        "magic",
        "phpunit",
        "method_public_static",
        "method_public",
        "method_protected_static",
        "method_protected",
        "method_private_static",
        "method_private"
      ],
      "sort_algorithm": "none"
    },
    "strict_comparison": true,
    "visibility_required": true,
    "new_with_parentheses": false
  }
}
```

### Usage

```bash
# Format all files
./vendor/bin/pint

# Format specific directory
./vendor/bin/pint app/Actions

# Check without fixing (CI/CD)
./vendor/bin/pint --test

# Format only changed files
./vendor/bin/pint --dirty
```

### Key Rules

- `declare_strict_types` - Adds strict types to all files
- `global_namespace_import` - Imports classes/functions
- `ordered_class_elements` - Enforces member ordering
- `strict_comparison` - Uses `===` and `!==`
- `new_with_parentheses: false` - Allows `new ClassName`

## Composer Scripts

Use Composer scripts for common tasks:

```json
{
  "scripts": {
    "pint": "./vendor/bin/pint",
    "pint-dirty": "./vendor/bin/pint --dirty",
    "pint-test": "./vendor/bin/pint --test",

    "pest": [
      "Composer\\Config::disableProcessTimeout",
      "php artisan migrate:fresh --env=testing --quiet",
      "./vendor/bin/pest --compact"
    ],
    "pest-dirty": [
      "Composer\\Config::disableProcessTimeout",
      "./vendor/bin/pest --dirty --compact"
    ],
    "pest-coverage": [
      "Composer\\Config::disableProcessTimeout",
      "php artisan migrate:fresh --env=testing --quiet",
      "./vendor/bin/pest --coverage"
    ],

    "db:fresh": [
      "Composer\\Config::disableProcessTimeout",
      "@php artisan migrate:fresh --seed"
    ],

    "dev": [
      "Composer\\Config::disableProcessTimeout",
      "npx concurrently \"php artisan serve\" \"php artisan queue:listen\" \"npm run dev\""
    ]
  }
}
```

### Usage

```bash
# Format code
composer pint

# Run tests
composer pest

# Run tests on changed files only
composer pest-dirty

# Check coverage
composer pest-coverage

# Fresh database
composer db:fresh

# Run full dev stack
composer dev
```

### Key Patterns

**Disable timeout for long commands:**
```json
"Composer\\Config::disableProcessTimeout"
```

**Chain multiple commands:**
```json
[
  "php artisan migrate:fresh --env=testing --quiet",
  "./vendor/bin/pest --compact"
]
```

**Concurrent services:**
```json
"npx concurrently \"cmd1\" \"cmd2\" \"cmd3\""
```

## Additional Common Packages

Beyond core packages, these are frequently used:

```json
{
  "require": {
    "henzeb/enumhancer": "^3.0",
    "propaganistas/laravel-phone": "^5.3",
    "spatie/laravel-activitylog": "^4.10",
    "spatie/laravel-permission": "^6.12",
    "spatie/laravel-json-api-paginate": "^1.16",
    "laravel/pennant": "^1.16",
    "laravel/horizon": "^5.30",
    "sentry/sentry-laravel": "^4.13"
  }
}
```

**Purpose:**
- **henzeb/enumhancer** - Enhanced enum features (Comparison, Dropdown traits)
- **propaganistas/laravel-phone** - Phone validation and formatting
- **spatie/laravel-activitylog** - Log model activity
- **spatie/laravel-permission** - Roles and permissions
- **spatie/laravel-json-api-paginate** - JSON API pagination
- **laravel/pennant** - Feature flags
- **laravel/horizon** - Queue monitoring
- **sentry/sentry-laravel** - Error tracking

## Summary

**Code style principles:**
- Write declarative code with well-named methods
- Use Laravel Pint for automatic formatting
- Follow consistent file and class structure
- Use Composer scripts for common tasks
- Extract comments into method names
- Keep methods short and focused

**Don't manually format code** - let Pint handle it.
