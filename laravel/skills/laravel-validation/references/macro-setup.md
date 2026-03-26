# assertValidationErrors TestResponse Macro

**Essential macro for streamlined validation testing** with the RequestDataProviderItem pattern.

## Overview

The `assertValidationErrors` macro extends Laravel's `TestResponse` class to provide clean, consistent validation error assertions when using the RequestDataProviderItem pattern.

## Installation

### 1. Create MacroServiceProvider

**File:** `app/Providers/MacroServiceProvider.php`

```php
<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\RequestDataProviderItem;

class MacroServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerTestResponseMacros();
    }

    private function registerTestResponseMacros(): void
    {
        TestResponse::macro(
            'assertValidationErrors',
            function (RequestDataProviderItem $dataProviderItem): TestResponse {
                /* @var TestResponse $this */
                return $this
                    ->assertUnprocessable()
                    ->when(
                        filled($dataProviderItem->expectedError),
                        fn (TestResponse $test) => $test->assertInvalid([
                            $dataProviderItem->attribute => $dataProviderItem->expectedError,
                        ])
                    )
                    ->when(
                        filled($dataProviderItem->notExpectedError),
                        fn (TestResponse $test) => $test->assertValid($dataProviderItem->attribute)
                    );
            }
        );
    }
}
```

### 2. Register Service Provider

**File:** `bootstrap/providers.php` (Laravel 11+)

```php
<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\MacroServiceProvider::class,
];
```

**Or File:** `config/app.php` (Laravel 10 and earlier)

```php
'providers' => ServiceProvider::defaultProviders()->merge([
    // Other providers...
    App\Providers\MacroServiceProvider::class,
])->toArray(),
```

## How It Works

### The Macro

The `assertValidationErrors` macro provides three key behaviors:

1. **Assert Unprocessable (422)**: Automatically asserts the response is 422 Unprocessable Entity
2. **Assert Expected Error**: If `expectedError` is set, asserts that exact error message appears for the attribute
3. **Assert Not Expected Error**: If `notExpectedError` is set, asserts that error does NOT appear for the attribute

### Property Reference

The macro uses two properties from `RequestDataProviderItem`:

| Property | Type | Purpose |
|----------|------|---------|
| `expectedError` | `?string` | The error message that SHOULD appear |
| `notExpectedError` | `?string` | The error message that SHOULD NOT appear |
| `attribute` | `string` | The field being validated |

## Usage Examples

### Testing Expected Errors

```php
test(
    'will fail to create order with invalid data',
    function (RequestDataProviderItem $dataProviderItem): void {
        postJson('/orders', $dataProviderItem->buildRequest())
            ->assertValidationErrors($dataProviderItem);
    }
)->with('order create');
```

**What happens:**
1. Asserts response is 422 Unprocessable
2. Asserts the `expectedError` message appears for the specified `attribute`

### Testing That Errors Don't Appear

```php
dataset('conditional validation', [
    'Gift message not required when is_gift is false' => [
        new RequestDataProviderItem()
            ->attribute('gift_message')
            ->empty()
            ->with(['is_gift' => false])
            ->assertNotError('The gift message field is required when is gift is true.'),
    ],
]);

test(
    'conditional validation works correctly',
    function (RequestDataProviderItem $dataProviderItem): void {
        postJson('/orders', $dataProviderItem->buildRequest())
            ->assertValidationErrors($dataProviderItem);
    }
)->with('conditional validation');
```

**What happens:**
1. Asserts response is 422 Unprocessable
2. Asserts the `notExpectedError` message does NOT appear for the specified attribute

## Comparison: Before vs After

### Without Macro (Verbose)

```php
test(
    'will fail to create order with invalid data',
    function (RequestDataProviderItem $dataProviderItem): void {
        postJson('/orders', $dataProviderItem->buildRequest())
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                $dataProviderItem->attribute => $dataProviderItem->expectedError,
            ]);
    }
)->with('order create');
```

### With Macro (Clean)

```php
test(
    'will fail to create order with invalid data',
    function (RequestDataProviderItem $dataProviderItem): void {
        postJson('/orders', $dataProviderItem->buildRequest())
            ->assertValidationErrors($dataProviderItem);
    }
)->with('order create');
```

## Benefits

### 1. Consistency
All validation tests follow the same pattern, making code easier to read and maintain.

### 2. Conciseness
Reduces test boilerplate from 4 lines to 1 line per assertion.

### 3. Flexibility
Handles both positive (expected error) and negative (not expected error) validation scenarios.

### 4. Automatic 422 Check
Always verifies the response is Unprocessable Entity without explicit assertion.

### 5. Type Safety
IDE autocomplete and type hints work seamlessly with the macro.

## Advanced Examples

### Mixed Authentication Patterns

```php
test(
    'will fail to create an application with invalid request data',
    function (RequestDataProviderItem $dataProviderItem): void {
        postJson(
            '/v1/applications',
            $dataProviderItem->buildRequest(create_api_user_and_login())
        )->assertValidationErrors($dataProviderItem);
    }
)->with('application create');
```

**Note:** `buildRequest()` can accept arguments passed to `tap()` callbacks for dynamic setup.

### With ActingAs

```php
test(
    'validates user input',
    function (RequestDataProviderItem $dataProviderItem): void {
        actingAs(User::factory()->create())
            ->postJson('/users', $dataProviderItem->buildRequest())
            ->assertValidationErrors($dataProviderItem);
    }
)->with('user create');
```

### Nested Array Validation

```php
dataset('order items', [
    'Product ID required in items array' => [
        new RequestDataProviderItem()
            ->attribute('items.0.product_id')
            ->empty()
            ->with(['items' => [[]]])
            ->assertError('The product field is required.'),
    ],
]);

test(
    'validates nested arrays correctly',
    function (RequestDataProviderItem $dataProviderItem): void {
        postJson('/orders', $dataProviderItem->buildRequest())
            ->assertValidationErrors($dataProviderItem);
    }
)->with('order items');
```

## Troubleshooting

### Macro Not Found

**Error:** `Method Illuminate\Testing\TestResponse::assertValidationErrors does not exist.`

**Solution:** Ensure `MacroServiceProvider` is registered in `bootstrap/providers.php` or `config/app.php`.

### Wrong Assertions Firing

**Issue:** Tests passing when they should fail, or vice versa.

**Check:**
1. Verify you're using `assertError()` for expected errors
2. Verify you're using `assertNotError()` for errors that should NOT appear
3. Ensure error messages match exactly (including punctuation)

### Test Fails with "Expected Error Not Present"

**Issue:** Error message doesn't match what Laravel returns.

**Solution:**
1. Use Laravel's exact validation message
2. Check Form Request's custom `messages()` method
3. Run test without macro to see actual error:

```php
postJson('/orders', $dataProviderItem->buildRequest())
    ->dump(); // Shows actual response
```

## Summary

The `assertValidationErrors` macro is a **required component** of the RequestDataProviderItem validation testing pattern. It provides:

- Clean, concise test assertions
- Consistent validation testing patterns
- Support for both positive and negative test cases
- Automatic 422 status verification

**Setup checklist:**
- ✅ Create `MacroServiceProvider.php`
- ✅ Register provider in `bootstrap/providers.php` or `config/app.php`
- ✅ Create `RequestDataProviderItem.php` helper class
- ✅ Start writing validation tests with datasets
