---
name: laravel-actions
description: Every domain operation is an invokable action class; controllers, jobs, listeners, commands and the scheduler only call one. Use when writing or changing business logic, deciding where logic lives, composing actions, or calling an action from an entry point.
---

# Laravel Actions

An action is one domain operation in one class with one public method, `__invoke()`. All business logic lives in actions. Everything that triggers an operation — a controller, job, listener, command or the scheduler — gathers its input and calls one action, nothing more.

## Rules

- **One operation per class.** Named `{Verb}{Subject}Action` (`CancelOrderAction`, `GetInvoiceTotalsAction`), under `app/Actions/{Entity or Subsystem}/`. Never group by verb.
- **Typed input.** Take the models the operation acts on, plus a DTO, enum or value object for everything else. Never an array of request data or a loose run of primitives.
- **The acting user is an argument.** When the operation depends on who's doing it, the caller passes the user in. Never call `user()` or `auth()` inside an action: it would then only work inside a request.
- **Return what the caller needs.** The model it changed, the thing it created, or `void`. Return the model you hold; don't re-query it with `fresh()`.
- **Guard first.** Business-rule preconditions go in a private `guard()` called before any work, using `throw_unless`/`throw_if` with a domain exception factory. A state precondition asks the state: `$order->state->canTransitionTo(OrderCancelled::class)`, or a model `is…()` helper. On an event-sourced model the aggregate guards instead, and the action doesn't ([laravel-event-sourcing](../laravel-event-sourcing/SKILL.md)).
- **Transaction when there's more than one write.** Wrap in `DB::transaction` when the action makes several writes, or a write plus something that must roll back with it. A single write needs none.
- **Nothing external inside the transaction.** HTTP calls, file storage and payments run before or after the closure, never inside it; a rollback can't undo them.
- **Reads can be actions too.** A read that computes, caches, transforms or calls a service is a `Get…Action` or `Fetch…Action`. A filterable, paginated listing is a query class instead (see [laravel-controllers](../laravel-controllers/SKILL.md)).
- **Context in mutable private properties.** When several private methods need the same input, store it in `private Order $order;`, never `readonly`: the same instance is invoked again and again, for example once per item in a loop.
- **`resolve()`, never `app()`.**

## Example

```php
<?php

declare(strict_types=1);

namespace App\Actions\Order;

use App\Exceptions\OrderException;
use App\Models\Order;
use App\States\Order\OrderCancelled;
use Illuminate\Support\Facades\DB;

class CancelOrderAction
{
    public function __construct(
        private readonly ReleaseStockAction $releaseStockAction,
    ) {}

    public function __invoke(Order $order): Order
    {
        $this->guard($order);

        return DB::transaction(function () use ($order): Order {
            $order->updateToCancelled();

            ($this->releaseStockAction)($order);

            return $order;
        });
    }

    private function guard(Order $order): void
    {
        throw_unless(
            $order->state->canTransitionTo(OrderCancelled::class),
            OrderException::cannotCancel(),
        );
    }
}
```

## Calling actions

| Caller | How |
|---|---|
| Controller | Method injection: `CancelOrderAction $cancelOrderAction` |
| Job | Injection into `handle()` |
| Listener | Constructor injection |
| Command | Injection into `handle()` |
| Scheduler | `$schedule->call(ExpireStaleOrdersAction::class)` |
| Another action | See composing actions |

Name the variable after the class (`$cancelOrderAction`), never `$action`.

## Composing actions

Actions call other actions. How they get them depends on the call:

- **Inject** a collaborator the action calls every time, as in the example.
- **`resolve(ReleaseStockAction::class)($order)` inline** for a call that's conditional, made per item, or a fallback in a `catch`. Injecting something used one time in ten adds noise to the constructor.

Services are usually called through their facade (`Payments::refund($order)`), which reads cleanly and lets a test mock it with `shouldReceive`. Injecting the service is fine when it reads better.

Composing actions runs synchronously in one process, and many multi-step processes need nothing more. When a step should retry on its own or the process must report progress, a chain or batch of jobs, each calling its action, usually covers it ([laravel-jobs](../laravel-jobs/SKILL.md)). A process that waits on outside signals or long timers is a workflow whose steps call the actions too ([laravel-workflows](../laravel-workflows/SKILL.md)).

## Reach for these when the situation calls for it

**Concurrent writes to the same record.** Re-fetch it with a lock inside the transaction and change the locked copy:

```php
return DB::transaction(function () use ($order): Order {
    $order = Order::query()->lockForUpdate()->findOrFail($order->id);
    // mutate $order
});
```

**An operation over many records.** A plural sweep action iterates with `lazyById()` and dispatches one job per record. The per-record action keeps its own guard, because the record may have changed by the time its job runs.

```php
class ExpireStaleOrdersAction
{
    public function __invoke(): void
    {
        Order::query()
            ->whereStale()
            ->lazyById()
            ->each(function (Order $order): void {
                ExpireOrderJob::dispatch($order);
            });
    }
}
```

**Events or jobs dispatched inside a transaction.** Make them wait for the commit so a rollback can't leave them behind: events implement `ShouldDispatchAfterCommit`, listeners `ShouldHandleEventsAfterCommit`, and queued jobs dispatch with `->afterCommit()`.

## Testing

Call the action directly, and mock the actions it composes:

```php
use function Pest\Laravel\mock;

test('will release stock when an order is cancelled', function (): void {
    $order = Order::factory()->paid()->create();

    mock(ReleaseStockAction::class)
        ->shouldReceive('__invoke')
        ->once()
        ->withArgs(fn (Order $_order): bool => $_order->is($order));

    resolve(CancelOrderAction::class)($order);

    expect($order->state)->toBeInstanceOf(OrderCancelled::class);
});
```

More in [laravel-testing](../laravel-testing/SKILL.md).

## Anti-patterns

- ❌ Domain logic in a controller, job, listener, command or model — even a lone `$order->update(...)`.
- ❌ `readonly` properties for invocation context.
- ❌ An HTTP call, file write or payment inside `DB::transaction`.
- ❌ A guard that throws a string: `throw_if($order->isPaid(), 'Order already paid')`. Use an exception factory.
- ❌ `return $order->fresh();`
- ❌ `app(CancelOrderAction::class)`.
- ❌ An action that takes `array $data` from a request.

## Related

- [laravel-dtos](../laravel-dtos/SKILL.md) — the data actions take
- [laravel-state-machines](../laravel-state-machines/SKILL.md) — `updateTo{State}()` and transition rules
- [laravel-exceptions](../laravel-exceptions/SKILL.md) — the exception factories guards throw
- [laravel-jobs](../laravel-jobs/SKILL.md) — jobs that wrap an action
- [laravel-testing](../laravel-testing/SKILL.md) — testing actions
- [laravel-event-sourcing](../laravel-event-sourcing/SKILL.md) — where the guard lives on an event-sourced model
- [laravel-workflows](../laravel-workflows/SKILL.md) — long-running processes whose steps call actions
