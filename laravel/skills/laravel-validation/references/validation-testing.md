# Validation Testing - Complete Guide

**Comprehensive guide to testing form request validation** using the RequestDataProviderItem helper and Pest datasets for systematic validation coverage.

**Related guides:**
- [testing-conventions.md](../../laravel-testing/references/testing-conventions.md) - Test file structure and ordering conventions
- [form-requests.md](form-requests.md) - Form request validation rules
- [Quality](../../laravel-quality/SKILL.md) - Testing standards and architecture tests
- [Testing](../../laravel-testing/SKILL.md) - Complete testing guide

## Philosophy

Validation testing should be:
- **Comprehensive** - Test all validation rules systematically
- **Readable** - Each test case has a descriptive name
- **Maintainable** - Reusable helpers reduce duplication
- **Consistent** - Standard patterns across all tests

## Testing Approaches

### Basic Inline Testing

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
```

**Use inline testing when:**
- Testing a single validation rule
- Testing happy path scenarios
- Testing authorization failures
- Quick sanity checks

### Dataset-Based Testing

For comprehensive validation coverage using the **RequestDataProviderItem** pattern:

```php
test(
    'will fail to create an order with invalid request data',
    function (RequestDataProviderItem $dataProviderItem): void {
        actingAs(User::factory()->create())
            ->postJson('/orders', $dataProviderItem->buildRequest())
            ->assertValidationErrors($dataProviderItem);
    }
)->with('order create');
```

**Use dataset testing when:**
- Testing comprehensive validation scenarios
- Testing all edge cases for a field
- Testing multiple related fields
- Need to test 5+ validation cases

---

## RequestDataProviderItem Helper Class

### Required Setup

The validation testing system requires two components:

1. **RequestDataProviderItem class** - The data provider helper class
2. **assertValidationErrors macro** - TestResponse macro for clean assertions

**[📖 Complete Macro Setup Guide →](./macro-setup.md)**

**Component Implementations:**
- **[View RequestDataProviderItem →](./RequestDataProviderItem.php)**
- **[View MacroServiceProvider →](./MacroServiceProvider.php)**

### File Locations

- `tests/Concerns/RequestDataProviderItem.php` - Helper class
- `app/Providers/MacroServiceProvider.php` - Macro registration

**⚠️ Important:** The `assertValidationErrors` macro must be registered in `MacroServiceProvider` and added to `bootstrap/providers.php` (or `config/app.php` for Laravel 10). See the [macro setup guide](./macro-setup.md) for complete installation instructions.

### The RequestDataProviderItem Class

The foundation for all validation testing:

**File location:** `tests/Concerns/RequestDataProviderItem.php`

### Key Methods

#### Value Setting Methods

| Method | Purpose | Example |
|--------|---------|---------|
| `attribute()` | Set the field being tested | `->attribute('email')` |
| `value()` | Set any custom test value | `->value('invalid-email')` |
| `empty()` | Set value to null | `->empty()` |
| `number()` | Generate random integer (10-1000) | `->number()` |
| `boolean()` | Set boolean value | `->boolean()` or `->boolean(false)` |
| `string()` | Generate string of specific length | `->string(256)` |
| `email()` | Generate valid or invalid email | `->email()` or `->email(false)` |
| `date()` | Generate formatted date | `->date()` or `->date('d/m/Y')` |
| `array()` | Generate array of specific size | `->array(101, ['product_id' => 1])` |

#### Assertion Methods

| Method | Purpose | Example |
|--------|---------|---------|
| `assertError()` | Expected validation error | `->assertError('The email field is required.')` |
| `assertNotError()` | Assert error should NOT occur | `->assertNotError('...')` |

#### Request Building Methods

| Method | Purpose | Example |
|--------|---------|---------|
| `with()` | Add additional request data | `->with(['is_active' => true])` |
| `tap()` | Run callback before building (supports multiple) | `->tap(fn() => /* setup */)` |
| `buildRequest()` | Generate request array | Called automatically in tests |

#### Static Helper Methods

| Method | Purpose | Example |
|--------|---------|---------|
| `buildString()` | Generate string of specific length | `RequestDataProviderItem::buildString(256)` |
| `buildArray()` | Generate array of specific size | `RequestDataProviderItem::buildArray(101, [])` |

---

## Creating Datasets

### Dataset File Structure

```
tests/
├── Datasets/
│   ├── OrderValidation.php
│   ├── UserValidation.php
│   └── DocumentValidation.php
```

Each dataset file contains multiple named datasets for different contexts.

### Basic Dataset Example

```php
<?php

declare(strict_types=1);

use Tests\Concerns\RequestDataProviderItem;

// tests/Datasets/OrderValidation.php
dataset('order create', [
    'Email is required' => [
        new RequestDataProviderItem()
            ->attribute('customer_email')
            ->empty()
            ->assertError('The customer email field is required.'),
    ],
    'Email must be a string' => [
        new RequestDataProviderItem()
            ->attribute('customer_email')
            ->value(['invalid'])
            ->assertError('The customer email field must be a string.'),
    ],
    'Email must be valid' => [
        new RequestDataProviderItem()
            ->attribute('customer_email')
            ->email(valid: false)
            ->assertError('The customer email field must be a valid email address.'),
    ],
]);
```

### Complete Field Validation

Test every validation rule for a field:

```php
dataset('order create', [
    // Required validation
    'Email is required' => [
        new RequestDataProviderItem()
            ->attribute('customer_email')
            ->empty()
            ->assertError('The customer email field is required.'),
    ],

    // Type validation
    'Email must be a string' => [
        new RequestDataProviderItem()
            ->attribute('customer_email')
            ->value(['invalid'])
            ->assertError('The customer email field must be a string.'),
    ],

    // Format validation
    'Email must be valid' => [
        new RequestDataProviderItem()
            ->attribute('customer_email')
            ->email(valid: false)
            ->assertError('The customer email field must be a valid email address.'),
    ],

    // Length validation
    'Email max length is 255' => [
        new RequestDataProviderItem()
            ->attribute('customer_email')
            ->string(256)
            ->value(fn ($item) => $item->value . '@example.com')
            ->assertError('The customer email field must not be greater than 255 characters.'),
    ],

    // Uniqueness (if applicable)
    'Email must be unique' => [
        new RequestDataProviderItem()
            ->attribute('customer_email')
            ->email()
            ->tap(fn ($item) => User::factory()->create(['email' => $item->value]))
            ->assertError('The customer email has already been taken.'),
    ],
]);
```

---

## Common Validation Patterns

### String Validation

```php
dataset('product create', [
    'Name is required' => [
        new RequestDataProviderItem()
            ->attribute('name')
            ->empty()
            ->assertError('The name field is required.'),
    ],
    'Name must be a string' => [
        new RequestDataProviderItem()
            ->attribute('name')
            ->value(123)
            ->assertError('The name field must be a string.'),
    ],
    'Name minimum length is 3' => [
        new RequestDataProviderItem()
            ->attribute('name')
            ->value('ab')
            ->assertError('The name field must be at least 3 characters.'),
    ],
    'Name maximum length is 100' => [
        new RequestDataProviderItem()
            ->attribute('name')
            ->string(101)
            ->assertError('The name field must not be greater than 100 characters.'),
    ],
]);
```

### Numeric Validation

```php
dataset('order items', [
    'Quantity is required' => [
        new RequestDataProviderItem()
            ->attribute('quantity')
            ->empty()
            ->assertError('The quantity field is required.'),
    ],
    'Quantity must be integer' => [
        new RequestDataProviderItem()
            ->attribute('quantity')
            ->value('invalid')
            ->assertError('The quantity field must be an integer.'),
    ],
    'Quantity minimum is 1' => [
        new RequestDataProviderItem()
            ->attribute('quantity')
            ->value(0)
            ->assertError('The quantity field must be at least 1.'),
    ],
    'Quantity maximum is 999' => [
        new RequestDataProviderItem()
            ->attribute('quantity')
            ->value(1000)
            ->assertError('The quantity field must not be greater than 999.'),
    ],
]);
```

### Array Validation

```php
dataset('order create', [
    'Items are required' => [
        new RequestDataProviderItem()
            ->attribute('items')
            ->empty()
            ->assertError('The items field is required.'),
    ],
    'Items must be an array' => [
        new RequestDataProviderItem()
            ->attribute('items')
            ->value('invalid')
            ->assertError('The items field must be an array.'),
    ],
    'Items minimum count is 1' => [
        new RequestDataProviderItem()
            ->attribute('items')
            ->value([])
            ->assertError('You must add at least one item to the order.'),
    ],
    'Items maximum count is 100' => [
        new RequestDataProviderItem()
            ->attribute('items')
            ->array(101, ['product_id' => 1, 'quantity' => 1])
            ->assertError('The items field must not have more than 100 items.'),
    ],
]);
```

### Nested Array Validation

```php
dataset('order items', [
    'Product ID is required' => [
        new RequestDataProviderItem()
            ->attribute('items.0.product_id')
            ->empty()
            ->with(['items' => [[]]])
            ->assertError('The product field is required.'),
    ],
    'Product ID must be integer' => [
        new RequestDataProviderItem()
            ->attribute('items.0.product_id')
            ->value('invalid')
            ->with(['items' => [[]]])
            ->assertError('The product field must be an integer.'),
    ],
    'Product must exist' => [
        new RequestDataProviderItem()
            ->attribute('items.0.product_id')
            ->value(99999)
            ->with(['items' => [[]]])
            ->assertError('The selected product is not available.'),
    ],
    'Quantity is required' => [
        new RequestDataProviderItem()
            ->attribute('items.0.quantity')
            ->empty()
            ->with(['items' => [['product_id' => 1]]])
            ->assertError('The quantity field is required.'),
    ],
]);
```

### File Upload Validation

```php
use Illuminate\Http\UploadedFile;

dataset('document create', [
    'File is required' => [
        new RequestDataProviderItem()
            ->attribute('file')
            ->empty()
            ->assertError('The file field is required.'),
    ],
    'File must be valid' => [
        new RequestDataProviderItem()
            ->attribute('file')
            ->value('not-a-file')
            ->assertError('The file field must be a file.'),
    ],
    'File must be an image' => [
        new RequestDataProviderItem()
            ->attribute('file')
            ->value(UploadedFile::fake()->create('document.txt'))
            ->assertError('The file field must be an image.'),
    ],
    'File max size is 2MB' => [
        new RequestDataProviderItem()
            ->attribute('file')
            ->value(UploadedFile::fake()->image('photo.jpg')->size(2049))
            ->assertError('The file field must not be greater than 2048 kilobytes.'),
    ],
    'File must be specific type' => [
        new RequestDataProviderItem()
            ->attribute('file')
            ->value(UploadedFile::fake()->image('photo.gif'))
            ->assertError('The file field must be a file of type: jpg, jpeg, png.'),
    ],
]);
```

### Enum Validation

```php
use App\Enums\OrderStatus;

dataset('order create', [
    'Status is required' => [
        new RequestDataProviderItem()
            ->attribute('status')
            ->empty()
            ->assertError('The status field is required.'),
    ],
    'Status must be a string' => [
        new RequestDataProviderItem()
            ->attribute('status')
            ->value(123)
            ->assertError('The status field must be a string.'),
    ],
    'Status must be valid enum' => [
        new RequestDataProviderItem()
            ->attribute('status')
            ->value('invalid-status')
            ->assertError('The selected status is invalid.'),
    ],
]);
```

### Boolean Validation

```php
dataset('user preferences', [
    'Is active is required' => [
        new RequestDataProviderItem()
            ->attribute('is_active')
            ->empty()
            ->assertError('The is active field is required.'),
    ],
    'Is active must be boolean' => [
        new RequestDataProviderItem()
            ->attribute('is_active')
            ->value('invalid')
            ->assertError('The is active field must be true or false.'),
    ],
]);
```

### Date Validation

```php
dataset('event create', [
    'Start date is required' => [
        new RequestDataProviderItem()
            ->attribute('start_date')
            ->empty()
            ->assertError('The start date field is required.'),
    ],
    'Start date must be valid date' => [
        new RequestDataProviderItem()
            ->attribute('start_date')
            ->value('invalid-date')
            ->assertError('The start date field must be a valid date.'),
    ],
    'Start date must be after today' => [
        new RequestDataProviderItem()
            ->attribute('start_date')
            ->date()
            ->value(now()->subDay()->format('Y-m-d'))
            ->assertError('The start date field must be a date after today.'),
    ],
    'End date must be after start date' => [
        new RequestDataProviderItem()
            ->attribute('end_date')
            ->value('2024-01-01')
            ->with(['start_date' => '2024-01-10'])
            ->assertError('The end date field must be a date after start date.'),
    ],
]);
```

### Conditional Validation

```php
dataset('order create', [
    'Gift message required when is_gift is true' => [
        new RequestDataProviderItem()
            ->attribute('gift_message')
            ->empty()
            ->with(['is_gift' => true])
            ->assertError('The gift message field is required when is gift is true.'),
    ],
    'Gift message not required when is_gift is false' => [
        new RequestDataProviderItem()
            ->attribute('gift_message')
            ->empty()
            ->with(['is_gift' => false])
            ->assertNotError('The gift message field is required when is gift is true.'),
    ],
    'Discount code required when total exceeds threshold' => [
        new RequestDataProviderItem()
            ->attribute('discount_code')
            ->empty()
            ->with(['total' => 10001])
            ->assertError('The discount code field is required when total is greater than 10000.'),
    ],
]);
```

### Custom Rule Validation

```php
dataset('shipping create', [
    'Postcode is required' => [
        new RequestDataProviderItem()
            ->attribute('postcode')
            ->empty()
            ->assertError('The postcode field is required.'),
    ],
    'Postcode must be valid UK format' => [
        new RequestDataProviderItem()
            ->attribute('postcode')
            ->value('INVALID')
            ->assertError('The postcode must be a valid UK postcode.'),
    ],
]);
```

---

## Advanced Patterns

### Using tap() for Complex Setup

The `tap()` method runs callbacks before building the request. Multiple `tap()` calls are supported:

```php
dataset('user create', [
    'Email must be unique' => [
        new RequestDataProviderItem()
            ->attribute('email')
            ->email()
            ->tap(fn ($item) => User::factory()->create(['email' => $item->value]))
            ->assertError('The email has already been taken.'),
    ],
    'Multiple setup steps' => [
        new RequestDataProviderItem()
            ->attribute('role')
            ->value('admin')
            ->tap(fn () => Role::factory()->create(['name' => 'admin']))
            ->tap(fn () => Permission::factory()->create(['name' => 'manage-users']))
            ->assertError('...'),
    ],
]);
```

### Using with() for Additional Data

The `with()` method adds extra request data needed for validation context. Now supports Closures:

```php
dataset('order items', [
    'Product ID required in nested array' => [
        new RequestDataProviderItem()
            ->attribute('items.0.product_id')
            ->empty()
            ->with([
                'customer_email' => 'customer@example.com',
                'items' => [[]],  // Empty item to test
            ])
            ->assertError('The product field is required.'),
    ],
    'Dynamic additional data' => [
        new RequestDataProviderItem()
            ->attribute('discount_code')
            ->value('SAVE10')
            ->with(fn () => ['total' => random_int(100, 1000)])
            ->assertError('...'),
    ],
]);
```

### Using Helper Methods for Values

Use the built-in helper methods for common value types:

```php
dataset('product create', [
    'Name exceeds maximum length' => [
        new RequestDataProviderItem()
            ->attribute('name')
            ->string(101)  // Generate 101 character string
            ->assertError('The name field must not be greater than 100 characters.'),
    ],
    'Too many tags' => [
        new RequestDataProviderItem()
            ->attribute('tags')
            ->array(11, 'tag')  // Generate array of 11 items
            ->assertError('The tags field must not have more than 10 items.'),
    ],
    'Invalid email format' => [
        new RequestDataProviderItem()
            ->attribute('email')
            ->email(valid: false)  // Generate invalid email
            ->assertError('The email field must be a valid email address.'),
    ],
]);
```

### Testing Multiple Validation States

Test the same field with different validation rules:

```php
dataset('order update', [
    // When status is 'pending'
    'Cannot cancel pending order without reason' => [
        new RequestDataProviderItem()
            ->attribute('cancel_reason')
            ->empty()
            ->with(['status' => 'cancelled'])
            ->tap(fn () => Order::factory()->create(['status' => 'pending']))
            ->assertError('The cancel reason field is required when status is cancelled.'),
    ],

    // When status is 'shipped'
    'Cannot modify shipped order items' => [
        new RequestDataProviderItem()
            ->attribute('items')
            ->array(1, ['product_id' => 1, 'quantity' => 2])
            ->tap(fn () => Order::factory()->create(['status' => 'shipped']))
            ->assertError('Cannot modify items for shipped orders.'),
    ],
]);
```

---

## Testing Structure

### Feature Test Example

```php
<?php

declare(strict_types=1);

use App\Models\User;
use Tests\Concerns\RequestDataProviderItem;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

beforeEach(function () {
    $this->user = User::factory()->create();
});

describe('Order Creation Validation', function () {
    test(
        'will fail to create order with invalid data',
        function (RequestDataProviderItem $dataProviderItem): void {
            actingAs($this->user)
                ->postJson('/api/orders', $dataProviderItem->buildRequest())
                ->assertValidationErrors($dataProviderItem);
        }
    )->with('order create');

    test('will create order with valid data', function (): void {
        actingAs($this->user)
            ->postJson('/api/orders', [
                'customer_email' => 'customer@example.com',
                'items' => [
                    ['product_id' => 1, 'quantity' => 2, 'price' => 10.00],
                ],
            ])
            ->assertCreated();
    });
});
```

### Organizing Tests by Feature

```
tests/
├── Feature/
│   ├── Api/
│   │   └── V1/
│   │       ├── OrderValidationTest.php
│   │       ├── UserValidationTest.php
│   │       └── ProductValidationTest.php
└── Datasets/
    ├── OrderValidation.php
    ├── UserValidation.php
    └── ProductValidation.php
```

---

## Best Practices

### 1. Descriptive Test Names

Use clear, descriptive names for each dataset item:

```php
// ✅ Good - Clearly states what's being tested
'Email is required' => [...],
'Email must be a valid format' => [...],

// ❌ Bad - Vague or unclear
'Test 1' => [...],
'Email validation' => [...],
```

### 2. Test One Thing Per Dataset Item

Each dataset item should test exactly one validation rule:

```php
// ✅ Good - Tests one rule
'Email is required' => [
    new RequestDataProviderItem()
        ->attribute('email')
        ->empty()
        ->assertError('The email field is required.'),
],

// ❌ Bad - Tests multiple rules at once
'Email validation' => [
    new RequestDataProviderItem()
        ->attribute('email')
        ->value('invalid')
        ->assertError('Multiple errors expected'),  // Unclear which error
],
```

### 3. Use Custom Messages from Form Request

Match error messages exactly as defined in your Form Request:

```php
// In Form Request
public function messages(): array
{
    return [
        'items.min' => 'You must add at least one item to the order.',
    ];
}

// In Dataset
'Items minimum count is 1' => [
    new RequestDataProviderItem()
        ->attribute('items')
        ->value([])
        ->assertError('You must add at least one item to the order.'),  // Exact match
],
```

### 4. Group Related Validations

Create separate datasets for different contexts:

```php
// Separate dataset for create vs update
dataset('order create', [...]);
dataset('order update', [...]);

// Separate dataset for different user roles
dataset('admin order create', [...]);
dataset('customer order create', [...]);
```

### 5. Test Edge Cases

Don't just test obvious failures - test boundaries:

```php
dataset('product create', [
    'Name minimum length is 3' => [
        new RequestDataProviderItem()
            ->attribute('name')
            ->value('ab')  // One character short
            ->assertError('The name field must be at least 3 characters.'),
    ],
    'Name exactly at minimum is valid' => [
        new RequestDataProviderItem()
            ->attribute('name')
            ->value('abc')  // Exactly 3 characters
            ->assertNotError('The name field must be at least 3 characters.'),
    ],
    'Name maximum length is 100' => [
        new RequestDataProviderItem()
            ->attribute('name')
            ->string(101)  // One character too long
            ->assertError('The name field must not be greater than 100 characters.'),
    ],
]);
```

### 6. Reuse Datasets Across Tests

Share datasets between related test files:

```php
// tests/Datasets/CommonValidation.php
dataset('email validation', [
    'Email is required' => [...],
    'Email must be valid' => [...],
    'Email maximum length' => [...],
]);

// Use in multiple test files
test('validates user email', fn($item) => ...)->with('email validation');
test('validates order customer email', fn($item) => ...)->with('email validation');
```

### 7. Use Factories with tap()

Combine with model factories for realistic test data:

```php
'Product must be active' => [
    new RequestDataProviderItem()
        ->attribute('product_id')
        ->tap(fn ($item) => $item->value = Product::factory()->inactive()->create()->id)
        ->assertError('The selected product is not available.'),
],
```

---

## Common Pitfalls

### ❌ Don't Forget to Call buildRequest()

```php
// BAD - Passing RequestDataProviderItem directly
->postJson('/orders', $dataProviderItem)

// GOOD - Call buildRequest() to get array
->postJson('/orders', $dataProviderItem->buildRequest())
```

### ❌ Don't Mix Multiple Attributes

```php
// BAD - Testing multiple attributes in one item
new RequestDataProviderItem()
    ->attribute('email')
    ->with(['name' => ''])  // Don't test name here
    ->assertError('...')

// GOOD - Separate dataset items
'Email is required' => [
    new RequestDataProviderItem()
        ->attribute('email')
        ->empty()
        ->assertError('...')
],
'Name is required' => [
    new RequestDataProviderItem()
        ->attribute('name')
        ->empty()
        ->assertError('...')
],
```

### ❌ Don't Use Vague Error Messages

```php
// BAD - Generic error message
->assertError('Validation failed')

// GOOD - Exact Laravel validation message
->assertError('The email field is required.')
```

### ❌ Don't Skip Edge Cases

```php
// BAD - Only testing obvious failures
'Name is required' => [...],

// GOOD - Test boundaries and edge cases
'Name is required' => [...],
'Name minimum length is 3' => [...],
'Name exactly at minimum is valid' => [...],
'Name maximum length is 100' => [...],
```

---

## Complete Example

### Form Request

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateOrderRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'customer_email' => [
                'required',
                'string',
                'email',
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
                Rule::exists('products', 'id')->where('active', true),
            ],
            'items.*.quantity' => [
                'required',
                'integer',
                'min:1',
                'max:999',
            ],
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

### Dataset File

```php
<?php

declare(strict_types=1);

use Tests\Concerns\RequestDataProviderItem;

// tests/Datasets/OrderValidation.php
dataset('order create', [
    // Customer Email Validation
    'Email is required' => [
        new RequestDataProviderItem()
            ->attribute('customer_email')
            ->empty()
            ->assertError('The customer email field is required.'),
    ],
    'Email must be a string' => [
        new RequestDataProviderItem()
            ->attribute('customer_email')
            ->value(['invalid'])
            ->assertError('The customer email field must be a string.'),
    ],
    'Email must be valid' => [
        new RequestDataProviderItem()
            ->attribute('customer_email')
            ->email(valid: false)
            ->assertError('The customer email field must be a valid email address.'),
    ],
    'Email maximum length is 255' => [
        new RequestDataProviderItem()
            ->attribute('customer_email')
            ->string(256)
            ->value(fn ($item) => $item->value . '@example.com')
            ->assertError('The customer email field must not be greater than 255 characters.'),
    ],

    // Items Array Validation
    'Items are required' => [
        new RequestDataProviderItem()
            ->attribute('items')
            ->empty()
            ->assertError('The items field is required.'),
    ],
    'Items must be an array' => [
        new RequestDataProviderItem()
            ->attribute('items')
            ->value('invalid')
            ->assertError('The items field must be an array.'),
    ],
    'Items minimum count is 1' => [
        new RequestDataProviderItem()
            ->attribute('items')
            ->value([])
            ->assertError('You must add at least one item to the order.'),
    ],
    'Items maximum count is 100' => [
        new RequestDataProviderItem()
            ->attribute('items')
            ->array(101, ['product_id' => 1, 'quantity' => 1])
            ->assertError('The items field must not have more than 100 items.'),
    ],

    // Nested Items Validation
    'Product ID is required' => [
        new RequestDataProviderItem()
            ->attribute('items.0.product_id')
            ->empty()
            ->with(['items' => [[]]])
            ->assertError('The product field is required.'),
    ],
    'Product ID must be integer' => [
        new RequestDataProviderItem()
            ->attribute('items.0.product_id')
            ->value('invalid')
            ->with(['items' => [[]]])
            ->assertError('The product field must be an integer.'),
    ],
    'Product must exist and be active' => [
        new RequestDataProviderItem()
            ->attribute('items.0.product_id')
            ->value(99999)
            ->with(['items' => [[]]])
            ->assertError('The selected product is not available.'),
    ],
    'Quantity is required' => [
        new RequestDataProviderItem()
            ->attribute('items.0.quantity')
            ->empty()
            ->with(['items' => [['product_id' => 1]]])
            ->assertError('The quantity field is required.'),
    ],
    'Quantity must be integer' => [
        new RequestDataProviderItem()
            ->attribute('items.0.quantity')
            ->value('invalid')
            ->with(['items' => [['product_id' => 1]]])
            ->assertError('The quantity field must be an integer.'),
    ],
    'Quantity minimum is 1' => [
        new RequestDataProviderItem()
            ->attribute('items.0.quantity')
            ->value(0)
            ->with(['items' => [['product_id' => 1]]])
            ->assertError('The quantity field must be at least 1.'),
    ],
    'Quantity maximum is 999' => [
        new RequestDataProviderItem()
            ->attribute('items.0.quantity')
            ->value(1000)
            ->with(['items' => [['product_id' => 1]]])
            ->assertError('The quantity field must not be greater than 999.'),
    ],
]);
```

### Test File

```php
<?php

declare(strict_types=1);

use App\Models\User;
use Tests\Concerns\RequestDataProviderItem;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

beforeEach(function () {
    $this->user = User::factory()->create();
});

describe('Order Creation Validation', function () {
    test(
        'will fail to create order with invalid data',
        function (RequestDataProviderItem $dataProviderItem): void {
            actingAs($this->user)
                ->postJson('/api/v1/orders', $dataProviderItem->buildRequest())
                ->assertValidationErrors($dataProviderItem);
        }
    )->with('order create');

    test('will successfully create order with valid data', function (): void {
        $product = Product::factory()->active()->create();

        actingAs($this->user)
            ->postJson('/api/v1/orders', [
                'customer_email' => 'customer@example.com',
                'items' => [
                    [
                        'product_id' => $product->id,
                        'quantity' => 2,
                    ],
                ],
            ])
            ->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'customer_email',
                    'items',
                ],
            ]);
    });
});
```

---

## Real-World Comprehensive Example

Here's a complete, production-ready example showing extensive validation testing for a complex form with nested arrays, multiple field types, and conditional validation:

```php
<?php

declare(strict_types=1);

use Tests\Concerns\RequestDataProviderItem;

dataset('application create', [
    // UUID Validation
    'UUID is required' => [
        new RequestDataProviderItem()
            ->attribute('uuid')
            ->empty()
            ->assertError('The uuid field is required.'),
    ],
    'UUID must be a UUID' => [
        new RequestDataProviderItem()
            ->attribute('uuid')
            ->string(5)
            ->assertError('The uuid field must be a valid UUID.'),
    ],

    // String Fields with Length Validation
    'Title is required' => [
        new RequestDataProviderItem()
            ->attribute('title')
            ->empty()
            ->assertError('The title field is required.'),
    ],
    'Title must be a string' => [
        new RequestDataProviderItem()
            ->attribute('title')
            ->array(1)
            ->assertError('The title field must be a string.'),
    ],
    'Title must be <= 100 characters' => [
        new RequestDataProviderItem()
            ->attribute('title')
            ->string(101)
            ->assertError('The title field must not be greater than 100 characters.'),
    ],

    // Email Validation with Custom Message
    'Email is required' => [
        new RequestDataProviderItem()
            ->attribute('email')
            ->empty()
            ->assertError('The email field is required.'),
    ],
    'Email must be an email' => [
        new RequestDataProviderItem()
            ->attribute('email')
            ->email(valid: false)
            ->assertError('You must provide a real functioning email address.'),
    ],

    // Date Validation with Format and Range
    'DOB must be a date' => [
        new RequestDataProviderItem()
            ->attribute('dob')
            ->string(5)
            ->assertError('The dob field must match the format Y-m-d.'),
    ],
    'DOB must adhere to a specific format' => [
        new RequestDataProviderItem()
            ->attribute('dob')
            ->date('d-m-Y')
            ->assertError('The dob field must match the format Y-m-d.'),
    ],

    // Enum Validation
    'Receiving platform must be from enum' => [
        new RequestDataProviderItem()
            ->attribute('receiving_platform')
            ->string(5)
            ->assertError('The selected receiving platform is invalid.'),
    ],

    // Boolean Validation
    'Test must be a boolean' => [
        new RequestDataProviderItem()
            ->attribute('test')
            ->string(5)
            ->assertError('The test field must be true or false.'),
    ],

    // Array Size Validation
    'Contacts must be an array' => [
        new RequestDataProviderItem()
            ->attribute('contacts')
            ->string(5)
            ->assertError('The contacts field must be an array.'),
    ],
    'Contacts must not have more than 25 items' => [
        new RequestDataProviderItem()
            ->attribute('contacts')
            ->array(26)
            ->assertError('The contacts field must not have more than 25 items.'),
    ],

    // Nested Array Validation - Contact Phone
    'Contact number is required' => [
        new RequestDataProviderItem()
            ->attribute('contacts.0.phone')
            ->empty()
            ->assertError('The phone number field is required.'),
    ],
    'Contact number must be a string' => [
        new RequestDataProviderItem()
            ->attribute('contacts.0.phone')
            ->array(1)
            ->assertError('The phone number field must be a string.'),
    ],
    'Contact number must be a phone number' => [
        new RequestDataProviderItem()
            ->attribute('contacts.0.phone')
            ->string(5)
            ->assertError('The phone number field must be a valid number.'),
    ],

    // Nested Array Validation - Address with Date Range
    'Address from date must adhere to a specific format' => [
        new RequestDataProviderItem()
            ->attribute('addresses.0.from_date')
            ->date('d-m-Y')
            ->assertError('The address from-date field must match the format Y-m-d.'),
    ],
    'Address from date must be today or earlier' => [
        new RequestDataProviderItem()
            ->attribute('addresses.0.from_date')
            ->value(now()->addDays(2)->format('Y-m-d'))
            ->assertError('The address from-date field must be a date before or equal to today.'),
    ],
    'Address to-date must be after the linked from date' => [
        new RequestDataProviderItem()
            ->attribute('addresses.0.to_date')
            ->value(now()->subDays(5)->format('Y-m-d'))
            ->with([
                'addresses' => [
                    [
                        'from_date' => now()->subDays(2)->format('Y-m-d'),
                    ],
                ],
            ])
            ->assertError('The address to-date field must be a date after address from-date.'),
    ],

    // Conditional Validation - Mutually Exclusive Fields
    'Traces provider is required when no employer name present' => [
        new RequestDataProviderItem()
            ->attribute('traces.0.provider')
            ->empty()
            ->assertError('The trace pension provider field is required when trace employer is not present.'),
    ],
    'Traces provider must be missing if employer provided' => [
        new RequestDataProviderItem()
            ->attribute('traces.0.provider')
            ->string(5)
            ->with([
                'traces' => [
                    [
                        'employer' => 'employer-name',
                    ],
                ],
            ])
            ->assertError('The trace pension provider field must be missing when trace employer is present.'),
    ],

    // String or Integer Field
    'Traces provider must be a string or an int' => [
        new RequestDataProviderItem()
            ->attribute('traces.0.provider')
            ->array(1)
            ->assertError('The trace pension provider field must be a string or an integer.'),
    ],

    // Deeply Nested Array Validation
    'Plan documents ulid is required' => [
        new RequestDataProviderItem()
            ->attribute('traces.0.documents.0.ulid')
            ->empty()
            ->assertError('The trace document ulid field is required.'),
    ],
    'Plan documents ulid must be a valid ulid' => [
        new RequestDataProviderItem()
            ->attribute('traces.0.documents.0.ulid')
            ->string(5)
            ->assertError('The trace document ulid field must be a valid ULID.'),
    ],
]);
```

**Key Techniques Demonstrated:**

1. **Helper Method Usage**: `string()`, `email()`, `date()`, `array()` for clean value generation
2. **Nested Array Testing**: Deep nested validation like `addresses.0.from_date` and `traces.0.documents.0.ulid`
3. **Conditional Validation**: Mutually exclusive fields using `with()` to provide context
4. **Date Range Validation**: Testing relative date constraints with `with()` for related field values
5. **Custom Error Messages**: Matching exact custom messages from Form Request
6. **Type Flexibility**: Testing union types (string or integer)
7. **Format Validation**: Date format constraints and specialized formats (UUID, ULID, phone)
8. **Array Size Limits**: Testing minimum and maximum array counts

---

## Summary

**The RequestDataProviderItem pattern provides:**

1. **Systematic Testing** - Test all validation rules comprehensively
2. **Readable Tests** - Descriptive names for each scenario
3. **Reusable Code** - Single helper class for all validation tests
4. **Maintainable Tests** - Easy to update when validation rules change
5. **Consistent Patterns** - Standard approach across all test files
6. **Flexible Setup** - `with()`, `tap()`, closures for complex scenarios
7. **Built-in Helpers** - `string()`, `email()`, `number()`, `date()`, `array()`, `boolean()` methods

**Key Methods:**
- **Value helpers**: `string(length)`, `email(valid)`, `number()`, `date(format)`, `array(count, item)`, `boolean(value)`
- **Assertions**: `assertError(message)`, `assertNotError(message)`
- **Request building**: `with(data)`, `tap(callback)`, `buildRequest()`
- **Static helpers**: `buildString(count, item)`, `buildArray(count, item)`

**Key Principles:**
- Test one rule per dataset item
- Use descriptive names
- Match exact error messages from Form Request
- Use helper methods for common value types
- Test edge cases and boundaries
- Organize datasets by feature/context
- Reuse datasets across related tests
- Multiple `tap()` calls are supported for complex setup
