# Helper Functions

Global helper functions provide convenience for common operations. **Use sparingly.**

**Related guides:**
- [Actions](../../laravel-actions/SKILL.md) - Domain logic belongs in actions, not helpers

## Structure

`app/helpers.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;

// User helper
if (! function_exists('user')) {
    function user(): ?\App\Models\User
    {
        return Auth::user();
    }
}

// Environment helper
if (! function_exists('environment')) {
    function environment(string ...$environments): bool|string
    {
        if (count($environments) === 0) {
            return app()->environment();
        }

        return app()->environment($environments);
    }
}
```

## Usage

### user() Helper

```php
// Get current user
$currentUser = user();

// In actions
class CreateOrderAction
{
    public function __invoke(CreateOrderData $data): Order
    {
        return user()->orders()->create($data->toArray());
    }
}

// In controllers
public function store(CreateOrderRequest $request): OrderResource
{
    $order = $action(user(), $data);
    return OrderResource::make($order);
}
```

### environment() Helper

```php
// Get current environment
$env = environment(); // 'local', 'production', etc.

// Check single environment
if (environment('production')) {
    // Production-specific code
}

// Check multiple environments
if (environment('local', 'staging')) {
    // Local or staging code
}
```

## Autoloading

In `composer.json`:

```json
{
  "autoload": {
    "psr-4": {
      "App\\": "app/"
    },
    "files": [
      "app/helpers.php"
    ]
  }
}
```

**Run after adding:**

```bash
composer dump-autoload
```

## Key Patterns

### 1. Guard with function_exists

```php
if (! function_exists('user')) {
    function user(): ?\App\Models\User
    {
        // ...
    }
}
```

Prevents errors if helper is redefined.

### 2. Full Type Hints

```php
function user(): ?\App\Models\User
function environment(string ...$environments): bool|string
```

### 3. Short and Focused

Each helper does one thing:

```php
// ✅ Good - single purpose
function user(): ?User

// ❌ Bad - too much
function user(bool $fresh = false, array $relations = []): ?User
```

## When to Use Helpers

**✅ Good candidates:**
- Frequently-accessed singletons (`user()`)
- Environment checks (`environment()`)
- Common utility functions used everywhere

**❌ Avoid helpers for:**
- Business logic (use actions)
- Complex operations (use classes)
- Operations used in only one place
- Operations that change application state

## Alternative: Static Methods

Consider using static methods on classes instead:

```php
// Instead of helper function
function format_currency(int $cents): string

// Use static method
class Money
{
    public static function format(int $cents): string
    {
        return '$' . number_format($cents / 100, 2);
    }
}

// Usage
Money::format($total);
```

## Summary

**Helpers should be:**
- Used sparingly
- Short and focused
- Fully typed
- Guarded with `function_exists`

**Prefer:**
- Actions for business logic
- Static methods on classes
- Dedicated utility classes

**Only create helpers for truly global, frequently-used operations.**
