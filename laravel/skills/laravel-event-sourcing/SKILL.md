---
name: laravel-event-sourcing
description: An event-sourced model is a guarded aggregate whose events are its state transitions; projectors keep read models, reactors run side effects. Use when the project uses spatie/laravel-event-sourcing and you write an aggregate, operation, event, projector or reactor.
---

# Laravel Event Sourcing

An event-sourced model's truth is its aggregate (spatie/laravel-event-sourcing 7.x). The aggregate is the model's state machine: an operation checks it is legal from the current state, records an event, and the event moves the state. Projectors turn events into the Eloquent read models the rest of the app queries; reactors do whatever else should happen. This skill holds only the decisions made so far; where it is silent, follow the project's own conventions.

## Rules

- **The aggregate is the state machine.** Its position is a private backed-enum `$state` that starts at an `Uninitialised` case. `Uninitialised` is write-side only: the genesis event's `apply()` sets the first real state, and only that ever reaches a read model.
- **Only `apply{Event}()` sets `$state`.** Every state the aggregate reaches is then one an event put it in. Each fold takes its event as its one typed parameter: Spatie finds the method by that type, so a fold without it never runs.
- **Each public operation declares where it's legal from** with a repeatable `#[OperationAllowedFrom]`. It takes a single case, a list (any one of them), or a keyed map that adds a second dimension, `['state' => OrderState::Paid, 'paymentState' => PaymentState::Captured]` (every key must hold). Repeat the attribute to allow either of two whole clauses. The rule sits on the operation, and every caller meets it.
- **An operation's first line is `$this->ensure()`, called directly.** The trait reads the attributes of the method that called it, off the call stack, so a wrapper method or closure makes it read the wrong method. It throws `IllegalTransition` before anything is recorded.
- **Capability questions go on the enum.** `isTerminal()` on `OrderState`, never a list of cases checked in the aggregate or a caller ([laravel-enums](../laravel-enums/SKILL.md)).
- **The command decides, the event records, `apply()` only folds.** Never derive a decision inside `apply()`: replay runs today's code over old events, so a changed rule would rewrite history. Record the decided fact as its own event: `PaymentAuthorised`, not a flag worked out inside `apply(OrderPlaced)`.
- **Events carry plain values, never models or DTOs.** They extend `ShouldBeStored`, a class in 7.x. Spatie stores a model by reference, so replay rebuilds it from today's row; a DTO ties a permanent event to an input shape that will change. The default serializer rebuilds:
  - scalars;
  - `CarbonImmutable` dates, immutable on replay because [laravel-providers](../laravel-providers/SKILL.md) calls `Date::use(CarbonImmutable::class)`;
  - a plain `readonly` payload class for a structured fact, and a list of them typed by a `@var Line[]` docblock;
  - backed enums only once Symfony's `BackedEnumNormalizer` is in `event_normalizers`, ahead of `ObjectNormalizer`. The default chain stores an enum but can't rebuild it.
- **Actions don't guard.** Permission sits on the route (`->can()`, [laravel-routing](../laravel-routing/SKILL.md)), legality in the aggregate. The action retrieves the aggregate, calls the operation and persists: `OrderAggregate::retrieve($order->uuid)->cancel($reason)->persist();`. This is the exception to [laravel-actions](../laravel-actions/SKILL.md)' guard-first rule: a `guard()` in the action would be a second copy of the aggregate's rule, free to drift from it.
- **Projectors keep the read models.** The model's `state` column casts to the same enum the aggregate holds.
- **Reactors carry the side effects.** Spatie replays projectors, never reactors, so an email or a job happens once, when the event is first stored.
- **The aggregate's uuid is the model's public id.** The `uuid` column from [laravel-models](../laravel-models/SKILL.md)' `HasUuid` concern doubles as the aggregate uuid: one id, plus the primary key.
- **Event-sourced models don't use spatie/laravel-model-states.** The aggregate already is their state machine. Plain models in the same app still may ([laravel-state-machines](../laravel-state-machines/SKILL.md)).

Copy [references/ProtectsInvariants.php](references/ProtectsInvariants.php), [references/OperationAllowedFrom.php](references/OperationAllowedFrom.php) and [references/IllegalTransition.php](references/IllegalTransition.php) when the project doesn't have the trait, attribute and exception yet.

## Example

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum OrderState: string
{
    case Uninitialised = 'uninitialised';
    case Placed = 'placed';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return $this === self::Cancelled;
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Aggregates;

use App\Aggregates\Concerns\ProtectsInvariants;
use App\Attributes\OperationAllowedFrom;
use App\Enums\OrderState;
use App\Events\Order\OrderCancelled;
use App\Events\Order\OrderPlaced;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

class OrderAggregate extends AggregateRoot
{
    use ProtectsInvariants;

    private OrderState $state = OrderState::Uninitialised;

    #[OperationAllowedFrom(OrderState::Uninitialised)]
    public function place(int $total): self
    {
        $this->ensure();

        return $this->recordThat(new OrderPlaced(total: $total));
    }

    #[OperationAllowedFrom([OrderState::Placed, OrderState::Paid])]
    public function cancel(string $reason): self
    {
        $this->ensure();

        return $this->recordThat(new OrderCancelled(reason: $reason));
    }

    protected function applyOrderPlaced(OrderPlaced $event): void
    {
        $this->state = OrderState::Placed;
    }

    protected function applyOrderCancelled(OrderCancelled $event): void
    {
        $this->state = OrderState::Cancelled;
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Events\Order;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class OrderPlaced extends ShouldBeStored
{
    public function __construct(
        public int $total,
    ) {}
}
```

`OrderCancelled` has the same shape, carrying `public string $reason`. The projector writes the read model from both:

```php
<?php

declare(strict_types=1);

namespace App\Projectors;

use App\Enums\OrderState;
use App\Events\Order\OrderCancelled;
use App\Events\Order\OrderPlaced;
use App\Models\Order;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

class OrderProjector extends Projector
{
    public function onOrderPlaced(OrderPlaced $event): void
    {
        Order::query()->create([
            'uuid' => $event->aggregateRootUuid(),
            'total' => $event->total,
            'state' => OrderState::Placed,
            'placed_at' => $event->createdAt(),
        ]);
    }

    public function onOrderCancelled(OrderCancelled $event): void
    {
        Order::query()->where('uuid', $event->aggregateRootUuid())->update([
            'state' => OrderState::Cancelled,
            'cancellation_reason' => $event->reason,
            'cancelled_at' => $event->createdAt(),
        ]);
    }
}
```

`CancelOrderAction` is the one line in the rules above. A reactor looks like a projector, extending `Reactor`; [laravel-workflows](../laravel-workflows/SKILL.md) shows one starting a workflow.

## Anti-patterns

- ❌ `$this->state = …` anywhere but an `apply{Event}()`.
- ❌ A `match` or `if` in `apply()` that works out the state from the event's data.
- ❌ `ensure()` called from a helper such as `$this->guard()`, or from a closure.
- ❌ A model, DTO or laravel-data object as an event property.
- ❌ A `guard()` or `canTransitionTo()` in an action over an event-sourced model.
- ❌ A side effect (an email, a job, an HTTP call) in a projector or an action.
- ❌ `now()` in a projector. It runs again on replay and stamps every row with the rebuild time; use `$event->createdAt()`.
- ❌ `HasStates` or a state-class cast on an event-sourced model.

## Related

- [laravel-actions](../laravel-actions/SKILL.md) — actions, and the guard rule this skill excepts
- [laravel-state-machines](../laravel-state-machines/SKILL.md) — states for models that aren't event-sourced
- [laravel-models](../laravel-models/SKILL.md) — the read models, casts and the `HasUuid` concern
- [laravel-enums](../laravel-enums/SKILL.md) — the state enum and its capability methods
- [laravel-routing](../laravel-routing/SKILL.md) — permission on the route with `->can()`
- [laravel-workflows](../laravel-workflows/SKILL.md) — long-running processes over event-sourced models
