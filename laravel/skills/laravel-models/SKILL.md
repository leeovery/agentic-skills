---
name: laravel-models
description: Eloquent model patterns and database layer. Use when creating or modifying models, relationships, casts, or observers.
---

# Laravel Models

Models represent database tables and domain entities.

**Related guides:**
- [Query Builders](../laravel-query-builders/SKILL.md) - Custom query builders (not scopes)
- [Actions](../laravel-actions/SKILL.md) - Actions contain business logic
- [DTOs](../laravel-dtos/SKILL.md) - Casting model JSON columns to DTOs

## Philosophy

Models should:
- Use **custom query builders** (not local scopes) - see [Query Builders](../laravel-query-builders/SKILL.md)
- Define relationships
- Define casts
- Contain simple accessors/mutators
- **NOT contain business logic** (that belongs in Actions)
- **Prefer PHP attributes** over properties/methods where available (Laravel 12+ for `#[UseEloquentBuilder]`, Laravel 13+ for `#[Table]`, `#[ObservedBy]`, `#[UsePolicy]`, `#[UseFactory]`, etc.)

## Basic Model Structure

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Builders\OrderBuilder;
use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[UseEloquentBuilder(OrderBuilder::class)]
class Order extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'total' => 'integer',
        ];
    }

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
```

## Casts

**Define casts for type safety:**

```php
protected function casts(): array
{
    return [
        'status' => OrderStatus::class,         // Enum
        'total' => 'integer',                   // Integer
        'is_paid' => 'boolean',                 // Boolean
        'metadata' => OrderMetadataData::class, // DTO
        'completed_at' => 'datetime',           // Carbon
        'tags' => 'array',                      // JSON array
    ];
}
```


## Model Methods

**Simple helper methods** are acceptable:

```php
class Order extends Model
{
    public function isPending(): bool
    {
        return $this->status === OrderStatus::Pending;
    }

    public function isCompleted(): bool
    {
        return $this->status === OrderStatus::Completed;
    }

    public function canBeCancelled(): bool
    {
        return $this->isPending() || $this->status === OrderStatus::Processing;
    }
}
```

**But NOT business logic:**

```php
// ❌ Bad - business logic in model
class Order extends Model
{
    public function cancel(): void
    {
        DB::transaction(function () {
            $this->update(['status' => OrderStatus::Cancelled]);
            $this->refundPayment();
            $this->notifyCustomer();
        });
    }
}

// ✅ Good - business logic in action
class CancelOrderAction
{
    public function __invoke(Order $order): Order
    {
        return DB::transaction(function () use ($order) {
            $order->update(['status' => OrderStatus::Cancelled]);
            resolve(RefundPaymentAction::class)($order);
            resolve(NotifyCustomerAction::class)($order);
            return $order;
        });
    }
}
```

## Model Observers

**For model lifecycle hooks:**

```php
<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Order;
use Illuminate\Support\Str;

class OrderObserver
{
    public function creating(Order $order): void
    {
        if (! $order->uuid) {
            $order->uuid = Str::uuid();
        }
    }

    public function created(Order $order): void
    {
        // Dispatch event, queue job, etc.
    }

    public function updating(Order $order): void
    {
        // Before update
    }

    public function updated(Order $order): void
    {
        // After update
    }

    public function deleted(Order $order): void
    {
        // After delete
    }
}
```

**Register in AppServiceProvider:**

```php
use App\Models\Order;
use App\Observers\OrderObserver;

public function boot(): void
{
    Order::observe(OrderObserver::class);
}
```

## Model Concerns (Traits)

**Extract reusable behavior:**

**[View full implementation →](references/HasUuid.php)**

**Use in models:**

```php
class Order extends Model
{
    use HasUuid;
}
```

## Route Model Binding

### Implicit Binding

```php
// Route
Route::get('/orders/{order}', [OrderController::class, 'show']);

// Controller - automatically receives Order model
public function show(Order $order) { }
```

### Custom Key

```php
Route::get('/orders/{order:uuid}', [OrderController::class, 'show']);
```

### Custom Resolution

```php
public function resolveRouteBinding($value, $field = null)
{
    return $this->where($field ?? 'id', $value)
        ->where('is_active', true)
        ->firstOrFail();
}
```

## Mass Assignment Protection

**All models should be unguarded by default.**

### AppServiceProvider Setup

In your `AppServiceProvider::boot()` method, call `Model::unguard()`:

```php
<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Model::unguard();
    }
}
```

### Model Configuration

**Do NOT use `$fillable` or `$guarded` properties** on your models:

```php
// ✅ Good - no fillable/guarded
class Order extends Model
{
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
        ];
    }
}

// ❌ Bad - don't use fillable
class Order extends Model
{
    protected $fillable = ['name', 'email'];
}

// ❌ Bad - don't use guarded
class Order extends Model
{
    protected $guarded = [];
}
```

### Why Unguard?

- **Simplicity**: No need to maintain fillable/guarded arrays
- **Flexibility**: All attributes can be mass-assigned
- **Trust**: With proper validation in Form Requests and Actions, mass assignment protection is redundant
- **Cleaner Models**: Less boilerplate code

**Important:** Always validate input in Form Requests before passing to Actions/Models.

## Model Organization

```
app/Models/
├── Order.php
├── User.php
├── Concerns/
│   ├── HasUuid.php
│   ├── BelongsToTenant.php
│   └── Searchable.php
└── Contracts/
    └── Searchable.php
```

## Testing Models

```php
it('can mass assign attributes', function () {
    $order = Order::create([
        'user_id' => 1,
        'status' => 'pending',
        'total' => 1000,
        'notes' => 'Test order',
    ]);

    expect($order->user_id)->toBe(1)
        ->and($order->total)->toBe(1000);
});

it('casts status to enum', function () {
    $order = Order::factory()->create(['status' => 'pending']);

    expect($order->status)->toBeInstanceOf(OrderStatus::class);
});

it('has user relationship', function () {
    $order = Order::factory()->create();

    expect($order->user)->toBeInstanceOf(User::class);
});
```

