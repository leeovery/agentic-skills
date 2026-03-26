# Jobs & Listeners

Jobs and Listeners are **thin delegation layers** with zero domain logic.

**Related guides:**
- [Actions](../../laravel-actions/SKILL.md) - Actions contain the work
- [dto-factories.md](../../laravel-dtos/references/dto-factories.md) - Factories transform event/job data to DTOs
- [Enums](../../laravel-enums/SKILL.md) - Queue enums
- [Testing](../../laravel-testing/SKILL.md) - Testing jobs and listeners (faking queues, testing delegation)

## Philosophy

Jobs and Listeners:
1. Set up context (queue configuration, error handling)
2. **Delegate to actions** to perform the work
3. Contain **no domain logic**

## Job Structure

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Order\ProcessOrderAction;
use App\Enums\Queue;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessOrderJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $timeout = 120;
    public int $uniqueFor = 60 * 10;  // 10 minutes
    public bool $failOnTimeout = true;
    public bool $deleteWhenMissingModels = true;

    public function __construct(public Order $order)
    {
        $this->onQueue(Queue::Processing->value);
    }

    public function handle(ProcessOrderAction $action): void
    {
        $action($this->order);
    }

    public function uniqueId(): int
    {
        return $this->order->id;
    }

    public function retryUntil(): CarbonImmutable
    {
        return now()->addHour();
    }

    public function backoff(): array
    {
        return [10, 60, 120, 300];  // 10s, 1m, 2m, 5m
    }
}
```

## Key Job Patterns

### 1. Thin Delegation

**Always delegate to actions:**

```php
public function handle(SendOrderConfirmationAction $action): void
{
    $action($this->order);
}
```

### 2. Queue Configuration

```php
public int $tries = 5;
public int $timeout = 120;
public int $uniqueFor = 60 * 10;
public bool $failOnTimeout = true;
public bool $deleteWhenMissingModels = true;

public function __construct(public Order $order)
{
    $this->onQueue(Queue::Emails->value);
}
```

### 3. Retry Configuration

```php
public function backoff(): array
{
    return [10, 60, 120, 300];  // Progressive backoff: 10s, 1m, 2m, 5m
}

public function retryUntil(): CarbonImmutable
{
    return now()->addMinutes(30);
}
```

### 4. Unique Jobs

Prevent duplicate jobs:

```php
class ProcessOrderJob implements ShouldBeUnique, ShouldQueue
{
    public int $uniqueFor = 60 * 30;  // 30 minutes

    public function uniqueId(): int
    {
        return $this->order->id;
    }
}
```

### 5. Job Middleware

```php
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;

public function middleware(): array
{
    return [
        new RateLimited('orders'),
        new WithoutOverlapping($this->order->id),
    ];
}
```

### 6. Serialization Without Relations

Avoid serializing related models:

```php
use Illuminate\Queue\Attributes\WithoutRelations;

public function __construct(
    #[WithoutRelations] public Order $order
) {
    $this->onQueue(Queue::Processing->value);
}
```

### 7. Error Handling

```php
use Exception;
use Illuminate\Support\Facades\Log;

public function handle(ProcessOrderAction $action): void
{
    try {
        $action($this->order);
    } catch (Exception $exception) {
        Log::error("Failed to process order {$this->order->id}", [
            'exception' => $exception->getMessage(),
            'attempt' => $this->attempts(),
        ]);

        $this->fail($exception);
    }
}

public function failed(?Throwable $exception): void
{
    Log::error("Order processing permanently failed", [
        'order_id' => $this->order->id,
        'attempts' => $this->attempts(),
        'exception' => $exception?->getMessage(),
    ]);

    // Additional failure handling (e.g., send notification)
}
```

## Queue Enums

Create an enum for queue names:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum Queue: string
{
    case Default = 'default';
    case Processing = 'processing';
    case Emails = 'emails';
    case Notifications = 'notifications';
}
```

**Usage:**

```php
$this->onQueue(Queue::Emails->value);
```

See [Enums](../../laravel-enums/SKILL.md) for more on enum patterns.

## Listener Structure

```php
<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Order\SendOrderConfirmationAction;
use App\Events\OrderCreated;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendOrderConfirmationListener implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(
        private readonly SendOrderConfirmationAction $sendConfirmation
    ) {}

    public function handle(OrderCreated $event): void
    {
        ($this->sendConfirmation)($event->order);
    }

    public function shouldQueue(OrderCreated $event): bool
    {
        return $event->order->customer_email !== null;
    }
}
```

## Listener Patterns

### 1. Delegation to Actions

```php
public function handle(OrderCreated $event): void
{
    resolve(SendOrderConfirmationAction::class)($event->order);
}
```

### 2. Conditional Queueing

```php
public function shouldQueue(OrderCreated $event): bool
{
    return $event->order->requiresProcessing();
}
```

### 3. Multiple Listeners per Event

```php
// EventServiceProvider or auto-discovered
OrderCreated::class => [
    SendOrderConfirmationListener::class,
    UpdateInventoryListener::class,
    NotifyWarehouseListener::class,
    LogOrderCreatedListener::class,
],
```

## Summary

**Jobs and Listeners:**
- Are thin delegation layers
- Configure queue behavior
- Handle retries and failures
- **Never contain domain logic**
- Always delegate to actions

See [Actions](../../laravel-actions/SKILL.md) for where domain logic belongs.
