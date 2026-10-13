---
name: laravel-state-machines
description: A status whose transitions have rules or side effects is a spatie/laravel-model-states state with a class per transition and updateTo/is helpers on the model. Use when adding or changing a model's status, its transitions or state-dependent behaviour.
---

# Laravel State Machines

A status earns a state machine when its transitions have rules (only some moves are allowed) or side effects (timestamps, events, other writes). It is a `state` column cast to a spatie/laravel-model-states base state: the base state declares every allowed move, each move with work attached has its own transition class, and the model is the only thing that starts a transition. A status that is a plain value, set freely with nothing reacting, is a backed enum instead (see [laravel-enums](../laravel-enums/SKILL.md)).

## Rules

- **Transitions are declared on the base state.** `#[AllowTransition(from, to, transition)]` and `#[DefaultState]` on the abstract `{Model}State`. That attribute list is the whole graph; nothing else decides which moves exist.
- **States are `{Model}{State}` beside the base state.** `OrderPaid` in `app/States/Order/`. Spatie discovers states by scanning the base state's directory, so they can't live elsewhere.
- **Every state sets `public static string $name`.** The column stores `paid`, not the class name, so renaming or moving a class never breaks stored rows.
- **Every state lists the transitions into and out of it in a `@see` docblock.** Otherwise they're named only in the base state's attributes; the docblock takes a reader from a state straight to that code.
- **One transition class per move, named `{From}To{To}`.** `PendingToPaid` in `app/States/{Model}/Transitions/`. Two sources into one target are two classes, never one `TransitionToCancelled` covering both: each move's side effects stay readable on their own.
- **A transition takes the model in its constructor and `handle()` returns it.** Spatie builds the class with the model first, calls `handle()`, and reads the state off the model it returns; returning a state breaks the transition. `handle()` assigns the new state, writes any fields the move owns, saves, dispatches, and returns the model.
- **A transition others react to dispatches an event.** Named `{Model}{State}` in `app/Events/`, imported with an alias (`as OrderPaidEvent`) because it shares the state's short name. The transition is the only place it fires.
- **The model is the only caller of `transitionTo()`.** Each reachable state gets `updateTo{State}(): self|Model`, returning what `transitionTo()` returns (the package types it as `Model`). Actions call `$order->updateToPaid()`; nothing else touches `transitionTo()`.
- **State checks are `is{State}()` on the model.** `return $this->state->equals(OrderPaid::class);`. Callers ask `$order->isPaid()`, never compare classes or strings.
- **Behaviour that varies by state is a capability on the state.** Declare `abstract public function is{Capability}(): bool` on the base state, implement it in every state, and delegate from the model: `isEditable()` returns `$this->state->isEditable()`. A new state then can't forget to answer, and the model never holds a list of states.
- **Guards live in the action and throw a domain exception.** `throw_unless($order->state->canTransitionTo(OrderCancelled::class), OrderException::cannotCancel())` in the action's `guard()`, before any work. [laravel-actions](../laravel-actions/SKILL.md) owns guards.
- **Not `final`.** States and transitions are plain classes.

## Example

```php
<?php

declare(strict_types=1);

namespace App\States\Order;

use App\States\Order\Transitions\PaidToCancelled;
use App\States\Order\Transitions\PaidToShipped;
use App\States\Order\Transitions\PendingToCancelled;
use App\States\Order\Transitions\PendingToPaid;
use Spatie\ModelStates\Attributes\AllowTransition;
use Spatie\ModelStates\Attributes\DefaultState;
use Spatie\ModelStates\State;

#[
    AllowTransition(OrderPending::class, OrderPaid::class, PendingToPaid::class),
    AllowTransition(OrderPending::class, OrderCancelled::class, PendingToCancelled::class),
    AllowTransition(OrderPaid::class, OrderShipped::class, PaidToShipped::class),
    AllowTransition(OrderPaid::class, OrderCancelled::class, PaidToCancelled::class),
    DefaultState(OrderPending::class),
]
abstract class OrderState extends State
{
    abstract public function isEditable(): bool;
}
```

```php
<?php

declare(strict_types=1);

namespace App\States\Order;

use App\States\Order\Transitions\PaidToCancelled;
use App\States\Order\Transitions\PaidToShipped;
use App\States\Order\Transitions\PendingToPaid;

/**
 * @see PendingToPaid
 * @see PaidToShipped
 * @see PaidToCancelled
 */
class OrderPaid extends OrderState
{
    public static string $name = 'paid';

    public function isEditable(): bool
    {
        return false;
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\States\Order\Transitions;

use App\Events\OrderPaid as OrderPaidEvent;
use App\Models\Order;
use App\States\Order\OrderPaid;
use Spatie\ModelStates\Transition;

class PendingToPaid extends Transition
{
    public function __construct(public Order $order) {}

    public function handle(): Order
    {
        $this->order->state = new OrderPaid($this->order);
        $this->order->save();

        OrderPaidEvent::dispatch($this->order);

        return $this->order;
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\States\Order\OrderPaid;
use App\States\Order\OrderState;
use Illuminate\Database\Eloquent\Model;
use Spatie\ModelStates\HasStates;

class Order extends Model
{
    use HasStates;

    public function updateToPaid(): self|Model
    {
        return $this->state->transitionTo(OrderPaid::class);
    }

    public function isPaid(): bool
    {
        return $this->state->equals(OrderPaid::class);
    }

    public function isEditable(): bool
    {
        return $this->state->isEditable();
    }

    protected function casts(): array
    {
        return [
            'state' => OrderState::class,
        ];
    }
}
```

## Reach for these when the situation calls for it

**A transition needs data from the caller.** Pass it through: `updateToCancelled(?string $reason = null)` calls `transitionTo(OrderCancelled::class, $reason)`, and the transition's constructor takes `(Order $order, ?string $reason = null)`. Keep the extra arguments optional: `canTransitionTo()` builds the transition class with only the arguments it's given, so a required one makes the guard throw.

**You need to know when the model entered a state.** The transition sets `{state}_at` (`$this->order->paid_at = now();`) before saving, and the model casts the column as a datetime.

**A move has no side effects and nothing reacts to it.** Leave out the transition class: `AllowTransition(OrderPaid::class, OrderPending::class)` uses Spatie's default transition, which sets the state and saves. It dispatches no event of yours, so add a class the moment something needs to react.

**Code filters models by state.** A `where{State}()` method on the model's builder wraps `whereState('state', OrderPaid::class)`; see [laravel-query-builders](../laravel-query-builders/SKILL.md).

**A transition runs inside the action's transaction.** Its event should wait for the commit; use the Laravel built-ins in [laravel-actions](../laravel-actions/SKILL.md).

A state driven by the outcome of a multi-step process is transitioned from that workflow's outcome callbacks, through the same `updateTo{State}()` helpers.

## Testing

Test each transition class directly: build the model in the source state, run `handle()`, and assert the new state and the event. Give the model's factory one state method per model state (`Order::factory()->pending()`).

```php
use App\Events\OrderPaid as OrderPaidEvent;

test('will transition a pending order to paid', function (): void {
    Event::fake([OrderPaidEvent::class]);

    $order = Order::factory()->pending()->create();

    new PendingToPaid($order)->handle();

    expect($order->refresh()->state)->toBeInstanceOf(OrderPaid::class);

    Event::assertDispatched(fn (OrderPaidEvent $event): bool => $event->order->is($order));
});
```

More in [laravel-testing](../laravel-testing/SKILL.md).

## Anti-patterns

- ❌ `$order->update(['state' => 'paid'])` or `$order->state = new OrderPaid($order)` outside a transition. It skips the allowed-transition check and every side effect.
- ❌ `$order->state->transitionTo(...)` in an action, controller or job. Call `updateTo{State}()`.
- ❌ `markAs{State}()` or `canBe{State}()` model helpers. Transitions are `updateTo{State}()`; the guard asks `canTransitionTo()` in the action.
- ❌ Business rules in `Transition::canTransition()`. It throws Spatie's generic `CouldNotPerformTransition`, not your domain exception, and hides the rule from the action.
- ❌ Listening for Spatie's `StateChanged`. It fires for every transition of every model; dispatch a named event from the transition.
- ❌ `in_array($order->state::class, [OrderPending::class, OrderPaid::class])` in the model. Add a capability to the state.
- ❌ A status enum for a lifecycle whose moves have rules, checked by hand in every action.

## Related

- [laravel-actions](../laravel-actions/SKILL.md) — guards, transactions and the after-commit built-ins
- [laravel-enums](../laravel-enums/SKILL.md) — statuses without transition rules
- [laravel-models](../laravel-models/SKILL.md) — casts and model structure
- [laravel-query-builders](../laravel-query-builders/SKILL.md) — `where{State}()` scopes
- [laravel-jobs](../laravel-jobs/SKILL.md) — events and the listeners that react to them
- [laravel-testing](../laravel-testing/SKILL.md) — test style and factories
