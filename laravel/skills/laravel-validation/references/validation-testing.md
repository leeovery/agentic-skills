# Validation testing: the helper in depth

The rules and the basic shape are in [SKILL.md](../SKILL.md). This file covers setup and the cases that need more than a value on one field.

## Setup

- Copy [RequestDataProviderItem.php](RequestDataProviderItem.php) to `tests/Concerns/RequestDataProviderItem.php`.
- Add the `assertValidationErrors` macro from [MacroServiceProvider.php](MacroServiceProvider.php) to the app's `MacroServiceProvider`. If the app doesn't have one yet, create it and list it in `bootstrap/providers.php`. The provider is app code that imports a test class, and that's safe: a closure's parameter type is resolved only when the macro is called, which only happens in tests.
- If the project already has a helper with another API (`RequestDataProviderItem::make()`, `expectError()`, `doNotExpectError()`), write datasets against that one.

## The item

| Method | Does |
|---|---|
| `attribute('items.0.quantity')` | The field under test, in dot notation |
| `value($value)` | Sends `$value`; a closure is called when the request is built |
| `empty()` | Sends the key with `null` |
| `absent()` | Leaves the key out of the request |
| `string(256)`, `array(101, $item)` | A string or list of that length, for `max` and `min` cases |
| `number()`, `boolean(false)`, `email(valid: false)`, `date('d/m/Y')` | A value of that type |
| `with([...])` or `with(fn (array $request) => [...])` | Other fields the case needs |
| `tap(fn () => ...)` | Setup that runs before the request is built; repeatable |
| `assertError($message)` | The message the field must fail with |
| `assertNotError($message)` | The field must not fail (see below) |
| `RequestDataProviderItem::buildString(244)`, `::buildArray(101)` | The same values as static calls, for composing a larger value |

## Anything that needs the app goes in a closure

Pest loads dataset files before the application boots, so a factory, config or database call in a dataset runs too early and fails. `value()`, `tap()` and `with()` closures run inside `buildRequest()`, during the test:

```php
'Product must be active' => [
    (new RequestDataProviderItem)
        ->attribute('items.0.product_ulid')
        ->value(fn (): string => Product::factory()->inactive()->create()->ulid)
        ->assertError('The selected product is invalid.'),
],
'Reference must be unique' => [
    (new RequestDataProviderItem)
        ->attribute('reference')
        ->value('ORD-1')
        ->tap(fn (): Order => Order::factory()->create(['reference' => 'ORD-1']))
        ->assertError('The reference has already been taken.'),
],
```

A closure never receives the item. `value()` and `tap()` closures receive the arguments the test passes to `buildRequest()`. `with()` closures receive the request built so far, then those arguments. Pass in the model the endpoint acts on when a case needs it:

```php
'Reference must be unique for the customer' => [
    (new RequestDataProviderItem)
        ->attribute('reference')
        ->value('ORD-1')
        ->tap(fn (Customer $customer): Order => Order::factory()->for($customer)->create(['reference' => 'ORD-1']))
        ->assertError('The reference has already been taken.'),
],
```

```php
$customer = Customer::factory()->create();

postJson("/api/v1/customers/{$customer->ulid}/orders", $dataProviderItem->buildRequest($customer))
    ->assertValidationErrors($dataProviderItem);
```

To build on a generated string, compose it in a single `value()`. A second `value()` call replaces the first:

```php
->value(RequestDataProviderItem::buildString(244).'@example.com')
```

## Other fields: `with()`

Use `with()` when a rule reads another field:

```php
'Gift message is required for a gift' => [
    (new RequestDataProviderItem)
        ->attribute('gift_message')
        ->empty()
        ->with(['is_gift' => true])
        ->assertError('The gift message field is required.'),
],
```

`with()` data is merged over the item, so its keys win. A `with()` that supplies a complete valid payload must leave out the field under test, `fn (array $request): array => Arr::except(validOrderPayload(), ['gift_message'])`, or the case tests nothing.

## Missing keys: `empty()` or `absent()`

| Rule | `empty()` (key sent as `null`) | `absent()` (no key) |
|---|---|---|
| `required` | fails | fails |
| `present` | passes | fails |
| `sometimes` | its rules run | its rules are skipped |
| `missing` | fails | passes |

## `assertNotError()`

`assertNotError()` asserts that the field has no error at all. The message isn't compared; it records which rule the case proves stays quiet. The macro still asserts a 422, so the rest of the request must fail somewhere. Leave the other required fields out, and never pair `assertNotError()` with an otherwise valid payload.

```php
'Gift message is optional when not a gift' => [
    (new RequestDataProviderItem)
        ->attribute('gift_message')
        ->absent()
        ->with(['is_gift' => false])
        ->assertNotError('The gift message field is required.'),
],
```

## Nested lists

Address one element by index. `data_set()` builds the surrounding structure:

```php
'Quantity must be at least 1' => [
    (new RequestDataProviderItem)
        ->attribute('items.0.quantity')
        ->value(0)
        ->assertError('The quantity field must be at least 1.'),
],
'No more than 100 items' => [
    (new RequestDataProviderItem)
        ->attribute('items')
        ->array(101)
        ->assertError('The items field must not have more than 100 items.'),
],
```

## Messages that catch people out

- `assertError()` matches by substring, so a fragment like `'required'` passes against the wrong message. Write the whole sentence.
- `Rule::requiredIf()` fails with the plain `required` message ("The gift message field is required."), not the `required_if` wording.
- A nested field with no `attributes()` name reports its full path: "The items.0.quantity field must be at least 1."
- `exists` and `Enum` both report "The selected {attribute} is invalid."
