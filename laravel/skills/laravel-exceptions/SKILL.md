---
name: laravel-exceptions
description: Domain exceptions are one class per entity with named static factories; those that can reach a client carry their own status code and reporting rule. Use when throwing from an action guard, adding an exception factory, or deciding how an exception renders and reports.
---

# Laravel Exceptions

A broken business rule is a domain exception, thrown by the action that owns the rule. Each entity has one exception class whose static factories name every way its operations can fail. An exception that can surface from a request implements Symfony's `HttpExceptionInterface` through the `Httpable` concern, so it carries its own status code and decides its own reporting, and the exception handler needs no wiring for it.

## Rules

- **One class per entity, a named factory per failure.** `{Entity}Exception` in `app/Exceptions/`. Factories read as the failure: `cannot{Verb}()` for a business rule (`cannotCancel()`), `failedTo{Verb}()` for an operation that went wrong, `{thing}NotFound()` for something missing that isn't a model. Callers never `new` the exception or pass it a message.
- **Guards throw the factory.** `throw_unless($condition, OrderException::cannotCancel())` in the action's `guard()`. [laravel-actions](../laravel-actions/SKILL.md) owns guards.
- **Exceptions that can surface from a request implement `HttpExceptionInterface` and use `Httpable`.** Laravel then renders them with their own status, headers and message.
- **Every HTTP factory passes its status code.** Pick the code that fits the failure; there's no fixed table. The concern falls back to 500 for a missing code, which turns an expected failure into a server error.
- **Write the message for the client.** Laravel sends an `HttpExceptionInterface` exception's message to the client even with `APP_DEBUG` off, 5xx included; every other exception becomes `Server Error`. Give a sentence the caller can act on, with public identifiers only: a fixed sentence, or `sprintf` with an `(ulid: %s)` suffix. Never a path, a query, a provider's raw error or the cause's message.
- **Chain the cause.** A factory that wraps a caught exception takes `Throwable $previous` and passes it as the third constructor argument. The report keeps the original trace; the client sees only your message.
- **Client errors aren't reported during a request; server errors always are.** A 4xx during a request is expected: the client has been told and nothing is broken. A 5xx means something is broken whoever called. In a job or scheduled task there's no client, so every failure needs attention. `Httpable::report()` does this, and it must return `true` to skip reporting: Laravel treats any return value except `false` as "handled" and skips its own reporting, the error tracker's hook included.
- **Control-flow exceptions aren't HTTP.** An exception thrown and caught inside a job to choose a path extends `Exception` with no interface and no concern. It never reaches a client.
- **Let the framework map its own failures.** A missing model is `findOrFail()` or route binding (404), a denied policy is `AuthorizationException` (403), invalid input is a Form Request (422). Don't write factories that duplicate them.
- **Nothing per exception in the `ExceptionBooter`.** It holds the error tracker's hook; the interface and the concern do the mapping, so no `render()` or `report()` callbacks for domain exceptions. [laravel-providers](../laravel-providers/SKILL.md) owns booters.

## Example

```php
<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Exceptions\Concerns\Httpable;
use App\Models\Order;
use Exception;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class OrderException extends Exception implements HttpExceptionInterface
{
    use Httpable;

    public static function cannotCancel(): self
    {
        return new self('This order can no longer be cancelled.', 409);
    }

    public static function cannotShipWithoutAddress(Order $order): self
    {
        return new self(
            sprintf('This order has no delivery address and cannot be shipped (ulid: %s).', $order->ulid),
            422,
        );
    }

    public static function failedToRefund(Order $order, Throwable $previous): self
    {
        return new self(
            sprintf('The refund could not be processed. Please try again later (ulid: %s).', $order->ulid),
            502,
            $previous,
        );
    }
}
```

## The `Httpable` concern

One per project, in `app/Exceptions/Concerns/`. If the project already has one, use it, but check its `report()`: one that returns `runningInConsole()` is inverted, reporting every client error during requests and silencing every failure in queues.

```php
<?php

declare(strict_types=1);

namespace App\Exceptions\Concerns;

use Illuminate\Contracts\Foundation\Application;

trait Httpable
{
    public function report(Application $app): bool
    {
        // Any return except false tells Laravel the exception is handled and skips default reporting.
        return $this->getStatusCode() < 500 && ! $app->runningInConsole();
    }

    public function getStatusCode(): int
    {
        return $this->code ?: 500;
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return [];
    }
}
```

The handler resolves `report()` through the container, so it can take the `Application`. Use `?:` for the status fallback, not `??`: `Exception::$code` is an `int` that defaults to `0`, never `null`, and Symfony rejects status 0.

## Reach for these when the situation calls for it

**An action rejects a specific input field.** When a rule only the action can check (stock, a combination of records) belongs to one field, subclass `ValidationException` with factories built on `withMessages()`. It renders as a 422 with field errors, exactly like a Form Request failure, and isn't reported.

```php
class OrderValidationException extends ValidationException
{
    public static function productUnavailable(Product $product): static
    {
        return static::withMessages([
            'items' => sprintf('%s is no longer available.', $product->name),
        ]);
    }
}
```

## Testing

Assert the class and the factory's message, so changing the text doesn't break the test:

```php
test('will not cancel a shipped order', function (): void {
    $order = Order::factory()->shipped()->create();

    resolve(CancelOrderAction::class)($order);
})->throws(OrderException::class, OrderException::cannotCancel()->getMessage());
```

In an HTTP test, assert the status and the same message: `->assertConflict()->assertJsonPath('message', OrderException::cannotCancel()->getMessage())`. More in [laravel-testing](../laravel-testing/SKILL.md).

## Anti-patterns

- ❌ `return $this->code ?? 500;`: the fallback never fires.
- ❌ `report()` returning `$app->runningInConsole()`.
- ❌ `abort(409, '...')` or `abort_if()` in an action. It ties the action to HTTP and loses the named failure.
- ❌ `throw new OrderException('...')` at the call site, or a factory that takes the message as an argument.
- ❌ A message carrying a file path, an internal id, SQL or `$previous->getMessage()`.
- ❌ An `OrderException::notFound()` doing what `findOrFail()` already does.
- ❌ `$exceptions->render(fn (OrderException $e) => ...)` in the `ExceptionBooter`.
- ❌ `HttpExceptionInterface` on an exception that never leaves a job.

## Related

- [laravel-actions](../laravel-actions/SKILL.md): the guards that throw these exceptions
- [laravel-state-machines](../laravel-state-machines/SKILL.md): state preconditions behind `cannot{Verb}()`
- [laravel-validation](../laravel-validation/SKILL.md): input validation in Form Requests
- [laravel-providers](../laravel-providers/SKILL.md): the `ExceptionBooter` and the error tracker's hook
- [laravel-testing](../laravel-testing/SKILL.md): testing style
