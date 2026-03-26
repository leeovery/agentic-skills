# Form Requests & Validation - Complete Guide

**Always use Form Requests** for validation. Form Requests encapsulate validation logic and keep controllers clean.

**Related guides:**
- [Controllers](../../laravel-controllers/SKILL.md) - Controllers use form requests
- [DTOs](../../laravel-dtos/SKILL.md) - Transformers convert validated requests to DTOs
- [validation-testing.md](validation-testing.md) - Testing form request validation
- [testing-conventions.md](../../laravel-testing/references/testing-conventions.md) - Test file structure

## Philosophy

Form Requests are the **single source of truth** for what data is valid. They:
- Keep controllers thin
- Centralize validation logic
- Provide type-safe validated data
- Are easily testable
- Auto-validate before reaching controller

## Basic Structure

```php
<?php

declare(strict_types=1);

namespace App\Http\Api\V1\Requests;

use App\Enums\OrderStatus;
use App\Rules\ValidPostcodeRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class CreateOrderRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'customer_email' => [
                'required',
                'string',
                'email:rfc,dns',
                'max:255',
            ],
            'items' => [
                'required',
                'array',
                'min:1',
                'max:100',
            ],
            'items.*.product_id' => [
                'required',
                'integer',
                'bail',
                Rule::exists('products', 'id')->where('active', true),
            ],
            'items.*.quantity' => [
                'required',
                'integer',
                'min:1',
                'max:999',
            ],
            'items.*.price' => [
                'required',
                'numeric',
                'min:0',
            ],
            'shipping.address_1' => [
                'required',
                'string',
                'max:100',
            ],
            'shipping.postcode' => [
                'required',
                'string',
                'bail',
                new ValidPostcodeRule,
            ],
            'status' => [
                'required',
                'string',
                'bail',
                new Enum(OrderStatus::class),
            ],
            'delivery_date' => [
                'nullable',
                'date',
                Rule::date()
                    ->format('Y-m-d')
                    ->after('today'),
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'customer_email' => 'customer email address',
            'items.*.product_id' => 'product',
            'items.*.quantity' => 'quantity',
            'shipping.postcode' => 'shipping postcode',
        ];
    }

    public function messages(): array
    {
        return [
            'items.min' => 'You must add at least one item to the order.',
            'items.*.product_id.exists' => 'The selected product is not available.',
        ];
    }
}
```

## Key Patterns

### 1. Array-Based Rules

**Always use array format** (never pipe-delimited strings):

```php
// ✅ Good
'email' => [
    'required',
    'string',
    'email',
    'max:255',
],

// ❌ Bad
'email' => 'required|string|email|max:255',
```

**Why:**
- Better diffs in version control
- Easier to add/remove rules
- Supports complex rule objects
- More readable

### 2. One Rule Per Line

```php
'product_id' => [
    'required',
    'integer',
    'bail',
    Rule::exists('products', 'id')->where('active', true),
],
```

### 3. Enum Validation

```php
use Illuminate\Validation\Rules\Enum;

'status' => [
    'required',
    'string',
    'bail',
    new Enum(OrderStatus::class),
],
```

### 4. Conditional Validation

```php
'discount_code' => [
    Rule::requiredIf($this->input('total') > 10000),
    'nullable',
    'string',
    Rule::exists('discount_codes', 'code')->where('active', true),
],

'vat_number' => [
    Rule::requiredIf($this->boolean('is_business')),
    'nullable',
    'string',
],
```

### 5. Nested Array Validation

```php
'items' => [
    'required',
    'array',
    'min:1',
],
'items.*.product_id' => [
    'required',
    'integer',
    Rule::exists('products', 'id'),
],
'items.*.quantity' => [
    'required',
    'integer',
    'min:1',
],
```

### 6. Bail Strategy

**Use `'bail'`** before expensive validations:

```php
'email' => [
    'required',
    'string',
    'bail',  // Stop here if required/string fails
    'email:rfc,dns',
    Rule::unique('users', 'email'),
],
```

**When to use:**
- Before DNS checks
- Before database existence checks
- Before custom rule classes
- After type checks

### 7. Custom Validation Rules

**[View full implementation →](./ValidPostcodeRule.php)**

**Usage:**

```php
'postcode' => [
    'required',
    'string',
    'bail',
    new ValidPostcodeRule,
],
```

### 8. Relative Field Validation

```php
'start_date' => [
    'required',
    'date',
],
'end_date' => [
    'required',
    'date',
    'after:start_date',  // Relative to another field
],
```

### 9. Database Existence with Conditions

```php
'coupon_code' => [
    'nullable',
    'string',
    Rule::exists('coupons', 'code')
        ->where('active', true)
        ->where('expires_at', '>', now()),
],
```

### 10. Sometimes Validation

```php
protected function prepareForValidation(): void
{
    $this->merge([
        'is_gift' => $this->boolean('is_gift'),
    ]);
}

public function rules(): array
{
    return [
        'gift_message' => [
            'required_if:is_gift,true',
            'nullable',
            'string',
            'max:500',
        ],
    ];
}
```

## Custom Attribute Names

**Improve error messages** with friendly field names:

```php
public function attributes(): array
{
    return [
        'customer_email' => 'email address',
        'items.*.product_id' => 'product',
        'shipping.address_1' => 'shipping address',
        'billing.postcode' => 'billing postcode',
    ];
}
```

**Before:**
> "The customer_email field is required."

**After:**
> "The email address field is required."

## Custom Messages

**Override default validation messages:**

```php
public function messages(): array
{
    return [
        'items.required' => 'Please add at least one item to your order.',
        'items.*.product_id.exists' => 'One or more products are no longer available.',
        'customer_email.email' => 'Please enter a valid email address.',
    ];
}
```

## No Authorization in Form Requests

**Don't implement `authorize()`** method—handle authorization at the route or policy level:

```php
// ❌ Don't do this
public function authorize(): bool
{
    return $this->user()->can('create', Order::class);
}

// ✅ Instead use route policies
Route::post('/orders', [OrderController::class, 'store'])
    ->can('create', Order::class);
```

**Or in controller:**

```php
public function store(CreateOrderRequest $request, CreateOrderAction $action)
{
    $this->authorize('create', Order::class);

    // ...
}
```

## Preparing Data

**Transform data before validation:**

```php
protected function prepareForValidation(): void
{
    $this->merge([
        'user_id' => auth()->id(),
        'is_active' => $this->boolean('is_active'),
        'metadata' => $this->json('metadata'),
    ]);
}
```

## After Validation Hook

**Modify validated data:**

```php
protected function passedValidation(): void
{
    $this->merge([
        'processed_at' => now(),
    ]);
}
```

## Organization

### By Layer

```
app/Http/
├── Web/
│   └── Requests/
│       ├── CreateOrderRequest.php
│       └── UpdateOrderRequest.php
└── Api/
    └── V1/
        └── Requests/
            ├── CreateOrderRequest.php
            └── UpdateOrderRequest.php
```

**Different layers can have different validation rules** even for same operation.

## Common Validation Patterns

### Email Validation

```php
'email' => [
    'required',
    'string',
    'email:rfc,dns',
    'max:255',
    Rule::unique('users', 'email'),
],
```

### Password Validation

```php
use Illuminate\Validation\Rules\Password;

'password' => [
    'required',
    'string',
    Password::min(8)
        ->letters()
        ->mixedCase()
        ->numbers()
        ->symbols()
        ->uncompromised(),
],
```

### File Upload

```php
'avatar' => [
    'required',
    'file',
    'image',
    'max:2048',  // 2MB
    'mimes:jpg,jpeg,png',
],
```

### JSON Validation

```php
'settings' => [
    'required',
    'json',
],
```

### URL Validation

```php
'website' => [
    'nullable',
    'url',
    'max:255',
],
```

### Boolean Validation

```php
'is_active' => [
    'required',
    'boolean',
],
```

### Array Size Limits

```php
'tags' => [
    'required',
    'array',
    'min:1',
    'max:10',
],
'tags.*' => [
    'string',
    'max:50',
],
```

## Testing Form Requests

**Related guides:**
- [validation-testing.md](validation-testing.md) - **Complete guide to validation testing** with RequestDataProviderItem and datasets
- [Quality](../../laravel-quality/SKILL.md) - Testing standards and practices

### Basic Testing

For simple, one-off validation tests:

```php
use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

it('validates required fields', function () {
    actingAs(User::factory()->create())
        ->postJson('/orders', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['customer_email', 'items']);
});

it('validates email format', function () {
    actingAs(User::factory()->create())
        ->postJson('/orders', ['customer_email' => 'invalid'])
        ->assertJsonValidationErrors(['customer_email']);
});

it('validates minimum items', function () {
    actingAs(User::factory()->create())
        ->postJson('/orders', ['items' => []])
        ->assertJsonValidationErrors(['items']);
});
```

### Comprehensive Validation Testing

For **systematic, comprehensive validation testing** of all rules and edge cases, use the **RequestDataProviderItem pattern** with Pest datasets.

See **[validation-testing.md](validation-testing.md)** for:
- RequestDataProviderItem helper class with Makeable trait
- Creating and organizing Pest datasets
- Testing all validation types (strings, arrays, files, enums, dates, etc.)
- Advanced patterns (tap(), with(), closures)
- Complete examples and best practices

## Summary

**Form Requests provide:**
1. **Centralized validation** - One place for all rules
2. **Clean controllers** - No validation logic in controllers
3. **Reusability** - Same rules across endpoints
4. **Testability** - Easy to test validation in isolation
5. **Type safety** - Validated data is type-checked

**Best practices:**
- Array-based rules (not strings)
- One rule per line
- Use `bail` before expensive checks
- Custom messages for UX
- No authorization in Form Requests
- Extract complex rules to Rule classes
