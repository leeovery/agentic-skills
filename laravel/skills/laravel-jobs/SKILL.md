---
name: laravel-jobs
description: Jobs and queued listeners are thin wrappers that call one action; events carry the model they announce. Use when writing or changing a job, event, listener or queue name, or deciding whether work runs on the queue or as a reaction to an event.
---

# Laravel Jobs

Jobs, events and listeners move work off the request; none of them does the work. A job is the queue's way to call one action later. An event announces that something happened, and each listener reacts by calling one action. Queue a job when the caller knows the work must happen and only wants it off the request; dispatch an event when other parts of the app react to a change and the code that made it shouldn't know who they are.

## Rules

- **A job calls one action.** The constructor takes what the action needs, `handle()` injects the action and calls it. Nothing else: no queries, no branching, no `$order->update(...)`. The operation then runs the same whether it's queued or called directly. [laravel-actions](../laravel-actions/SKILL.md) owns the logic.
- **Named after the action it wraps.** `ExpireOrderJob` calls `ExpireOrderAction`, in `app/Jobs/`.
- **A job takes the model.** Constructor-promoted `public Order $order`. `SerializesModels` stores the id and re-fetches the row when the job runs, so the action sees current data. Never an `int $orderId` with a lookup in `handle()`, even when the row may be gone by then (see below).
- **Queue options only when the job needs them.** No `$tries`, `$timeout`, `backoff()` or `failed()` by default; the worker's settings hold until a job proves otherwise. Never `$tries` with `retryUntil()`: Laravel ignores `$tries` once `retryUntil()` is set.
- **Queue names are cases of `App\Enums\Queue`.** A small enum, one case per queue a worker serves (`Default`, `Processing`). A job on a named queue sets it in its constructor, `$this->onQueue(Queue::Processing)`; Laravel 12 takes the case directly. Horizon's supervisors list them from the enum too: `'queue' => [Queue::Default->value, Queue::Processing->value]`.
- **On Laravel 13, queue options are attributes.** `#[Queue]`, `#[UniqueFor]`, `#[Tries]`, `#[Backoff]`, `#[Timeout]` from `Illuminate\Queue\Attributes`; alias the first `as OnQueue` beside the `Queue` enum: `#[OnQueue(Queue::Processing)]`.
- **Actions dispatch jobs.** After their writes: `ExpireOrderJob::dispatch($order)`, or `dispatchIf($condition, …)`. A controller calls the action; the action decides what to queue.
- **Events are `{Subject}{PastTense}` in `app/Events/`.** `OrderPaid`, `InvoiceSent`; no `Event` suffix. An event for a state change is fired by its transition and nowhere else ([laravel-state-machines](../laravel-state-machines/SKILL.md)); where a file also imports the state, alias the event `as OrderPaidEvent`. Any other event is dispatched by the action that made the change.
- **An event carries the model, nothing derived.** `public function __construct(public Order $order) {}`. Listeners read what they need from it, so a new listener never means changing the event. Stored events in an event-sourced app are different: they carry plain values ([laravel-event-sourcing](../laravel-event-sourcing/SKILL.md)).
- **Listeners are queued and call one action.** `implements ShouldQueue`, the action constructor-injected, `handle()` passes it the event's model. A slow or failing listener then can't fail the request that fired the event.
- **Listeners are named for what they do.** After the action they call, without the suffix: `SendOrderReceipt` calls `SendOrderReceiptAction`. Never `SendOrderReceiptListener`.
- **Listeners are discovered, never registered.** Laravel maps each listener in `app/Listeners` to the event its `handle()` type-hints. A union type (`handle(OrderPaid|OrderRefunded $event)`) shares one listener across events that carry the same model; discovery registers it for each.
- **Not `final`.** Jobs, events and listeners are plain classes.

## Example

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Order\ExpireOrderAction;
use App\Enums\Queue;
use App\Models\Order;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExpireOrderJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public Order $order)
    {
        $this->onQueue(Queue::Processing);
    }

    public function handle(ExpireOrderAction $expireOrderAction): void
    {
        $expireOrderAction($this->order);
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderPaid
{
    use Dispatchable, SerializesModels;

    public function __construct(public Order $order) {}
}
```

```php
<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Order\SendOrderReceiptAction;
use App\Events\OrderPaid;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendOrderReceipt implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(
        private readonly SendOrderReceiptAction $sendOrderReceiptAction,
    ) {}

    public function handle(OrderPaid $event): void
    {
        ($this->sendOrderReceiptAction)($event->order);
    }
}
```

## Reach for these when the situation calls for it

**The same record mustn't be processed twice at once.** Add `ShouldBeUnique` to the job, key it on the model, and let the lock expire:

```php
class ExpireOrderJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Releases the lock if the job gets stuck on the queue. */
    public int $uniqueFor = 60 * 10;

    public function uniqueId(): int
    {
        return $this->order->id;
    }
}
```

**The job's side effect mustn't repeat on redelivery.** `ShouldBeUnique` keeps a second copy off the queue; it doesn't stop a retried or redelivered job doing its work again. Key the side effect inside the action with [leeovery/laravel-idempotency](https://github.com/leeovery/laravel-idempotency), as [laravel-workflows](../laravel-workflows/SKILL.md) shows.

**The job calls a rate-limited downstream.** Return `RateLimited` from `middleware()` (`RateLimitedWithRedis` when the app runs Redis), with the limiter defined where [laravel-providers](../laravel-providers/SKILL.md) puts them. Bound retries with `retryUntil()`, not `$tries`: every release by the limiter counts as an attempt. The `DateTimeImmutable` return type holds because laravel-providers makes `now()` immutable.

**Retrying can't help.** A job with `retryUntil()` retries every exception until the deadline. For exceptions that won't clear, `FailOnException` (Laravel 12.19+) fails the job at once.

```php
class ChargeOrderJob implements ShouldQueue
{
    use Queueable;

    public function middleware(): array
    {
        return [
            new RateLimited(RateLimiterName::Payments),
            new FailOnException([PaymentDeclinedException::class]),
        ];
    }

    public function retryUntil(): DateTimeImmutable
    {
        return now()->addHour();
    }
}
```

**The record may be gone when the job runs.** A soft-deleted model still restores, because the restore query skips global scopes; only a hard delete loses it. Restoring a missing model throws and fails the job. When that means the work is already done or no longer matters, as when an earlier run purged the row, mark the job `#[DeleteWhenMissingModels]` (Laravel 11.3+; `public bool $deleteWhenMissingModels = true;` below that) and Laravel deletes the job quietly. Keep passing the model.

```php
#[DeleteWhenMissingModels]
class PurgeOrderFilesJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public Order $order) {}

    public function handle(PurgeOrderFilesAction $purgeOrderFilesAction): void
    {
        $purgeOrderFilesAction($this->order);
    }
}
```

**The action relies on context a request would set.** A job runs without the request. The acting user is an argument to the action; anything a package reads ambiently (an activity-log causer, say) goes into the job and is restored in `handle()` before the action is called. The current tenant is the exception: stancl's queue bootstrapper carries it ([laravel-multi-tenancy](../laravel-multi-tenancy/SKILL.md)).

**The frontend needs to hear about an event.** Implement `ShouldBroadcast` and add `InteractsWithSockets`. `broadcastAs()` returns a stable dotted name (`order.paid`) and `broadcastWith()` sends the public id only (`['orderId' => $this->order->ulid]`), so the model's attributes never reach the socket.

**Events or jobs dispatched inside a transaction.** Make them wait for the commit with the built-ins listed in [laravel-actions](../laravel-actions/SKILL.md).

**An operation over many records.** One sweep action dispatches one job per record; see [laravel-actions](../laravel-actions/SKILL.md).

**Steps that each need their own retries, or a process whose progress matters.** Chain the jobs, one action each, with `Bus::chain([...])->catch(...)->dispatch()`: a step runs only once the one before it succeeds, and each retries on its own terms. When the steps can run side by side and something must happen once all are done, batch them: `Bus::batch([...])->then(...)->catch(...)->dispatch()`, with the jobs using `Batchable` and the `job_batches` table in place; `Bus::findBatch($id)->progress()` reports how far along it is. Between them these cover most multi-step processes. A process that waits on outside signals or long timers is a workflow instead ([laravel-workflows](../laravel-workflows/SKILL.md)).

## Testing

Run the job through the container with its action mocked; a listener the same way, via `resolve(SendOrderReceipt::class)->handle(new OrderPaid($order))`.

```php
use function Pest\Laravel\mock;

test('will expire the order', function (): void {
    $order = Order::factory()->create();

    mock(ExpireOrderAction::class)
        ->shouldReceive('__invoke')
        ->once()
        ->withArgs(fn (Order $_order): bool => $_order->is($order));

    ExpireOrderJob::dispatchSync($order);
});
```

The code that dispatches asserts with `Queue::fake()` or `Event::fake()`. More in [laravel-testing](../laravel-testing/SKILL.md).

## Anti-patterns

- ❌ A query, condition or write in a job's or listener's `handle()` beyond calling the action.
- ❌ `int $orderId` plus a lookup in `handle()`. Pass the model, with `#[DeleteWhenMissingModels]` when the row may be gone.
- ❌ `$tries`, `$timeout`, `backoff()` and `failed()` copied onto every job, or `$tries` beside `retryUntil()`.
- ❌ `onQueue('processing')`. Use the `Queue` enum.
- ❌ A sync listener whose only work is dispatching a job. Queue the listener.
- ❌ `SendOrderReceiptListener`, or an `Event::listen()` call or `$listen` array registering it.
- ❌ A state's event dispatched outside its transition.
- ❌ `final class ExpireOrderJob`.

## Related

- [laravel-actions](../laravel-actions/SKILL.md) — the work jobs and listeners call, sweeps, and after-commit dispatch
- [laravel-state-machines](../laravel-state-machines/SKILL.md) — transitions that fire events
- [laravel-enums](../laravel-enums/SKILL.md) — enums for fixed identifiers
- [laravel-providers](../laravel-providers/SKILL.md) — where rate limiters are defined
- [laravel-testing](../laravel-testing/SKILL.md) — test style and fakes
- [laravel-event-sourcing](../laravel-event-sourcing/SKILL.md) — stored events, which carry plain values
- [laravel-workflows](../laravel-workflows/SKILL.md) — processes that wait on signals or long timers
- [leeovery/laravel-idempotency](https://github.com/leeovery/laravel-idempotency) — idempotency keys for side effects that must not repeat
