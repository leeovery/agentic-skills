---
name: laravel-validation
description: Form Requests validate every input with one-per-line array rules and no authorize() or mapping; validation is tested with endpoint datasets. Use when writing or changing a Form Request, a custom validation rule, or a validation test.
---

# Laravel Validation

Every input an endpoint accepts is validated by a Form Request, and the Form Request does nothing else. It doesn't authorize: the route does. It doesn't map: the code that builds the DTO reads the validated request. Each Form Request is pinned by a dataset-driven test that sends a broken request to the real endpoint, one rule per case, and asserts the exact message.

## Rules

- **A Form Request validates every input.** Never `$request->validate()` or `Validator::make()` in a controller. laravel-data's validation is switched off, so the Form Request is the only validation that runs (see [laravel-dtos](../laravel-dtos/SKILL.md)).
- **One request per surface and operation.** `App\Http\{Surface}\Requests\{Verb}{Entity}Request`, for example `App\Http\Api\V1\Requests\CreateOrderRequest`. When two surfaces take the same input, each gets its own request, so one surface's contract can change without touching the other's.
- **A request only validates.** No `authorize()`: authorization is `->can()` on the route (see [laravel-routing](../laravel-routing/SKILL.md)). No `toDto()`, `prepareForValidation()` or `passedValidation()`: mapping and casting happen where the DTO is built, through typed request helpers.
- **Array rules, one per line.** Never a pipe string. Each rule gets its own line, so a diff shows exactly which rule changed and a rule object sits alongside the strings.
- **Order: presence, type, bounds, then the expensive checks.** `required` or `nullable` first, then the type (`string`, `integer`, `array`), then size and format, then database lookups and rule objects.
- **`bail` sits just before the rule it protects.** It applies to the whole attribute wherever it appears; Laravel treats it as a flag, not a position. Placing it before the lookup or rule object tells the reader what the type checks guard, and that check never runs on input that already failed.
- **Enums use `new Enum(OrderPriority::class)`**, after `'string'` and `'bail'`. Never an `in:` list of case values: the list goes stale when a case is added.
- **Name fields in `attributes()`, not `messages()`.** Laravel builds every message from the attribute's name, so mapping `items.*.product_ulid` to `product` fixes all of that field's messages at once. Use `messages()` only for wording the attribute name can't produce. A rule object writes its own message.

## Example

```php
<?php

declare(strict_types=1);

namespace App\Http\Api\V1\Requests;

use App\Enums\OrderPriority;
use App\Models\Product;
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
                'max:255',
                'email',
            ],
            'delivery_date' => [
                'nullable',
                'date',
                'after:today',
            ],
            'priority' => [
                'nullable',
                'string',
                'bail',
                new Enum(OrderPriority::class),
            ],
            'is_gift' => [
                'boolean',
            ],
            'items' => [
                'required',
                'array',
                'max:100',
            ],
            'items.*.product_ulid' => [
                'required',
                'string',
                'bail',
                Rule::exists(Product::class, 'ulid')->where('active', true),
            ],
            'items.*.quantity' => [
                'required',
                'integer',
                'min:1',
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'items.*.product_ulid' => 'product',
            'items.*.quantity' => 'quantity',
        ];
    }
}
```

## Custom rules

| The check | Write |
|---|---|
| A built-in rule or a `Rule::` / `File::` builder expresses it | That builder: `Rule::exists(Product::class, 'ulid')->where('active', true)`, `Rule::requiredIf(fn (): bool => $this->boolean('is_gift'))` |
| It belongs to this one request, usually comparing fields | A closure in the rules array, `function (string $attribute, mixed $value, Closure $fail): void`, reading the other field with `$this->input()` |
| It's reused across requests, or its logic deserves its own unit test | A `{Concept}Rule` class in `app/Rules/` implementing `ValidationRule` |

A rule class checks the value's type before using it, and fails cleanly when the type is wrong. Under `strict_types`, passing a non-string into a `string` parameter throws a `TypeError`, which is a 500 rather than a 422. Instantiate it without parentheses (`new PostcodeRule`), as the Laravel Pint preset's `new_with_parentheses` rule enforces (see [laravel-quality](../laravel-quality/SKILL.md)).

```php
<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PostcodeRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/^[a-z]{1,2}\d[a-z\d]?\s*\d[a-z]{2}$/i', $value)) {
            $fail('The :attribute field must be a valid postcode.');
        }
    }
}
```

## Testing

- **Every Form Request gets a dataset test against its endpoint.** One `test()` per endpoint takes a `RequestDataProviderItem`, sends `buildRequest()`, and calls `->assertValidationErrors($dataProviderItem)`. Going through HTTP proves the request is wired to the route; unit-testing `rules()` would not.
- **One broken rule per case, with the full message.** The message comes from `attributes()` and the rule, so asserting it pins both.
- **One dataset file per entity**, `tests/Datasets/Order.php`, holding datasets named `'{entity} {action}'`: `'order create'`, `'order update'`. The happy path is a separate test.
- **Use the bundled helper**: copy [RequestDataProviderItem.php](references/RequestDataProviderItem.php) to `tests/Concerns/`, and add the macro in [MacroServiceProvider.php](references/MacroServiceProvider.php). **If the project already has its own helper**, such as one with `::make()` and `expectError()`, use its API and don't add a second.

```php
<?php

declare(strict_types=1);

use App\Models\Product;
use Tests\Concerns\RequestDataProviderItem;

dataset('order create', [
    'Customer email is required' => [
        (new RequestDataProviderItem)
            ->attribute('customer_email')
            ->empty()
            ->assertError('The customer email field is required.'),
    ],
    'Product must be active' => [
        (new RequestDataProviderItem)
            ->attribute('items.0.product_ulid')
            ->value(fn (): string => Product::factory()->inactive()->create()->ulid)
            ->assertError('The selected product is invalid.'),
    ],
]);
```

In the endpoint's feature test:

```php
use function Pest\Laravel\actingAs;

test('cannot create an order with invalid data', function (RequestDataProviderItem $dataProviderItem): void {
    actingAs(User::factory()->create())
        ->postJson('/api/v1/orders', $dataProviderItem->buildRequest())
        ->assertValidationErrors($dataProviderItem);
})->with('order create');
```

Sign in the way the project's tests already do. Read [validation-testing.md](references/validation-testing.md) when a case needs the database, other fields, a missing key, an "error must not appear" assertion, or a nested list.

## Reach for these when the situation calls for it

**A file upload.** Build the rule with `File::types([...])->max(...)` rather than `file|mimes|max` strings. When limits vary by environment, read them from config and apply them with `->when(filled(...), fn (File $rule) => $rule->max(...))`.

**A pure check needed outside validation too.** When a transformer or service needs the same check, move it into `app/Validators/{Concept}Validator` with `public static function validate(string $value): bool`. The rule class then guards the type and calls it.

**A field-level error found inside an action.** Some failures surface only once the work runs. Throw a `ValidationException` subclass so the client gets a 422 on the right field (see [laravel-exceptions](../laravel-exceptions/SKILL.md)).

## Anti-patterns

- ❌ `public function authorize(): bool` in a Form Request, or `$this->authorize()` in a controller.
- ❌ `toDto()` on a request, or passing `$request->validated()` or `$request->all()` onward.
- ❌ `prepareForValidation()` merging in `auth()->id()`, casts or timestamps.
- ❌ A `user_id` rule for the acting user. The controller passes `user()` to the action.
- ❌ A business rule, such as "a shipped order can't change", in a Form Request. That's an action guard (see [laravel-actions](../laravel-actions/SKILL.md)).

## Related

- [laravel-dtos](../laravel-dtos/SKILL.md): mapping the validated request into a DTO
- [laravel-routing](../laravel-routing/SKILL.md): `->can()` on routes
- [laravel-controllers](../laravel-controllers/SKILL.md): the controllers that receive Form Requests
- [laravel-exceptions](../laravel-exceptions/SKILL.md): field errors raised from actions
- [laravel-testing](../laravel-testing/SKILL.md): general test style
