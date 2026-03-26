# Routing & Permissions

Routes define the HTTP interface. Authorization happens at the route level using `->can()`.

**Related guides:**
- [Controllers](../../laravel-controllers/SKILL.md) - Controllers handle routes
- [Policies](../../laravel-policies/SKILL.md) - Policies define authorization logic
- [structure.md](../../laravel-architecture/references/structure.md) - Web vs API routing structure
- [bootstrap-booters.md](../../laravel-providers/references/bootstrap-booters.md) - Route configuration in bootstrap

## Why Route-Level Authorization

**Use `->can()` on routes, not in controllers or form requests.**

### Benefits:

1. **Correct HTTP status codes** - Authorization before model resolution ensures proper `403` instead of `404`
2. **No ambiguity** - Consistent authorization location for all routes
3. **Clear separation** - Authorization separate from validation

## Web Layer Routes

For your application's web layer (Blade, Inertia, or private API for separate frontend):

```php
<?php

declare(strict_types=1);

use App\Http\Web\Controllers\OrderController;
use App\Models\Order;
use Illuminate\Support\Facades\Route;

// routes/web.php - No version prefix
Route::middleware('auth:sanctum')->group(function () {

    Route::get('/orders', [OrderController::class, 'index'])
        ->can('viewAny', Order::class)
        ->name('orders.index');

    Route::get('/orders/{order:uuid}', [OrderController::class, 'show'])
        ->can('view', 'order')
        ->name('orders.show');

    Route::post('/orders', [OrderController::class, 'store'])
        ->can('create', Order::class)
        ->name('orders.store');

    Route::patch('/orders/{order:uuid}', [OrderController::class, 'update'])
        ->can('update', 'order')
        ->name('orders.update');

    Route::delete('/orders/{order:uuid}', [OrderController::class, 'destroy'])
        ->can('delete', 'order')
        ->name('orders.destroy');

    Route::post('/orders/{order:uuid}/cancel', CancelOrderController::class)
        ->can('cancel', 'order')
        ->name('orders.cancel');

});
```

## Public API Routes

For external/third-party consumption:

```php
<?php

declare(strict_types=1);

use App\Http\Api\V1\Controllers\OrderController;
use App\Models\Order;
use Illuminate\Support\Facades\Route;

// routes/api/v1.php - Prefix/name applied in bootstrap
Route::middleware('auth:sanctum')->group(function () {

    Route::get('/orders', [OrderController::class, 'index'])
        ->can('viewAny', Order::class)
        ->name('orders.index');

    Route::get('/orders/{order:uuid}', [OrderController::class, 'show'])
        ->can('view', 'order')
        ->name('orders.show');

    Route::post('/orders', [OrderController::class, 'store'])
        ->can('create', Order::class)
        ->name('orders.store');

});
```

## Key Patterns

### Class-Level Permissions

```php
->can('viewAny', Order::class)
->can('create', Order::class)
```

### Instance-Level Permissions

```php
->can('view', 'order')        // Uses route binding
->can('update', 'order')
->can('delete', 'order')
```

### Route Model Binding with Custom Keys

```php
Route::get('/orders/{order:uuid}', [OrderController::class, 'show']);
Route::get('/orders/{order:ulid}', [OrderController::class, 'show']);
Route::get('/orders/{order:order_number}', [OrderController::class, 'show']);
```

### Named Routes

**Every route must have a name:**

```php
->name('orders.index')
->name('orders.show')
->name('api.v1.orders.index')
```

### Invokable Controller Routes

```php
Route::post('/orders/{order}/ship', ShipOrderController::class)
    ->can('ship', 'order')
    ->name('orders.ship');

Route::post('/orders/{order}/refund', RefundOrderController::class)
    ->can('refund', 'order')
    ->name('orders.refund');
```

## API Versioning

Configure in `bootstrap/app.php`:

```php
Route::prefix('v1')->name('api.v1.')->group(base_path('routes/api/v1.php'));
Route::prefix('v2')->name('api.v2.')->group(base_path('routes/api/v2.php'));
```

See [bootstrap-booters.md](../../laravel-providers/references/bootstrap-booters.md) for bootstrap configuration.

## Web vs API Differences

**Web Layer (`routes/web.php`):**
- No version prefix
- Named `orders.index`
- Serves your own application
- Can change freely

**Public API (`routes/api/v*.php`):**
- Version prefix `/api/v1`
- Named `api.v1.orders.index`
- For external consumers
- Must remain stable

## Summary

**Route-level authorization:**
- Use `->can()` on all routes
- Class-level for index/create
- Instance-level for show/update/delete
- Named routes always
- Custom binding keys when needed

See [Policies](../../laravel-policies/SKILL.md) for authorization logic.
