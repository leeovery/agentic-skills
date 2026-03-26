# Route Model Binding - Complete Guide

Laravel's route model binding automatically resolves model instances from route parameters. This guide covers both simple and advanced conditional binding strategies.

## Philosophy

- **Simple by default** - Use standard binding for straightforward cases
- **Conditional when needed** - Different routes may need different resolution logic
- **Type-safe** - Always return proper model instances or fail explicitly
- **Clear intent** - Route-specific logic should be obvious

## Simple Route Model Binding

### Basic Binding

**In AppServiceProvider:**

```php
use Illuminate\Support\Facades\Route;

private function bootRouteModelBindings(): void
{
    Route::bind('order', fn (string $value) => Order::findOrFail($value));
}
```

**In routes:**

```php
Route::get('/orders/{order}', [OrderController::class, 'show']);
```

**In controller:**

```php
public function show(Order $order)
{
    // $order is already resolved
    return view('orders.show', compact('order'));
}
```

### Using Query Objects

**For complex resolution logic:**

```php
use App\Http\Web\Queries\OrderShowQuery;

private function bootRouteModelBindings(): void
{
    Route::bind(
        'order',
        fn (string|int $value) => new OrderShowQuery($value)->firstOrFail()
    );
}
```

**Query object example:**

```php
<?php

namespace App\Http\Web\Queries;

use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;

class OrderShowQuery
{
    public function __construct(private string|int $id) {}

    public function firstOrFail(): Order
    {
        return Order::query()
            ->with(['items', 'customer'])
            ->where('id', $this->id)
            ->firstOrFail();
    }
}
```

### With Additional Constraints

```php
private function bootRouteModelBindings(): void
{
    Route::bind('activeOrder', function (string $value) {
        return Order::query()
            ->where('id', $value)
            ->whereNotNull('completed_at')
            ->firstOrFail();
    });
}
```

## Conditional Route Model Binding

**Use when different routes need different resolution strategies for the same parameter.**

### ConditionalRouteBinder Class

**Location:** `app/Support/ConditionalRouteBinder.php` or `packages/fabric/support/src/ConditionalRouteBinder.php`

```php
<?php

namespace Fabric\Support;

use Exception;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;

class ConditionalRouteBinder
{
    private string $bindingKey;
    private array $strategies = [];
    private mixed $otherwise = null;
    private bool $registered = false;

    private function __construct(string $bindingKey)
    {
        $this->bindingKey = $bindingKey;
    }

    public static function registerMacro(): void
    {
        RouteFacade::macro('bindUsing', fn(string $bind) => ConditionalRouteBinder::create($bind));
    }

    public static function create(string $bindingKey): self
    {
        return (new static($bindingKey))->autoRegister();
    }

    private function autoRegister(): self
    {
        if ($this->registered) {
            return $this;
        }

        $this->registered = true;
        $this->register();

        return $this;
    }

    private function register(): void
    {
        RouteFacade::bind($this->bindingKey, function (string|int $value, Route $route) {
            $strategy = collect($this->strategies)
                ->first(fn(array $strategy) => $strategy[0]($route, $value));

            if ($strategy) {
                return $strategy[1]($value, $route);
            }

            if ($this->otherwise) {
                return ($this->otherwise)($value, $route);
            }

            throw new Exception("No resolver matched for route binding '{$this->bindingKey}'");
        });
    }

    public function forRoute(string|array $routePattern, callable $resolver): self
    {
        return $this->when(
            fn(Route $route) => collect($routePattern)
                ->flatten()
                ->first(fn(string $pattern) => $this->matchesRoutePattern($route, $pattern)),
            $resolver
        );
    }

    public function when(callable $condition, callable $resolver): self
    {
        $this->strategies[] = [$condition, $resolver];

        return $this;
    }

    private function matchesRoutePattern(Route $route, string $pattern): bool
    {
        $routeName = $route->getName();

        if (blank($routeName)) {
            return false;
        }

        if ($routeName === $pattern) {
            return true;
        }

        return Str::is($pattern, $routeName);
    }

    public function otherwise(callable $resolver): self
    {
        $this->otherwise = $resolver;

        return $this;
    }
}
```

### Setup in AppServiceProvider

```php
use Fabric\Support\ConditionalRouteBinder;

private function bootRouteModelBindings(): void
{
    // Register the macro once
    ConditionalRouteBinder::registerMacro();

    // Define conditional bindings
    Route::bindUsing('order')
        ->forRoute('orders.show', fn (string $value) => Order::findOrFail($value))
        ->forRoute('orders.edit', fn (string $value) => Order::where('id', $value)->where('editable', true)->firstOrFail())
        ->forRoute('admin.orders.*', fn (string $value) => Order::withTrashed()->findOrFail($value))
        ->otherwise(fn (string $value) => Order::findOrFail($value));
}
```

### Usage Examples

#### Example 1: Admin vs Public Routes

```php
Route::bindUsing('user')
    ->forRoute('admin.*', function (string $value) {
        // Admin sees all users including soft-deleted
        return User::withTrashed()->findOrFail($value);
    })
    ->otherwise(function (string $value) {
        // Public only sees active users
        return User::where('id', $value)
            ->where('is_active', true)
            ->firstOrFail();
    });
```

**Routes:**

```php
// Public routes
Route::get('/users/{user}', [UserController::class, 'show'])->name('users.show');

// Admin routes
Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('/users/{user}', [AdminUserController::class, 'show'])->name('users.show');
});
```

#### Example 2: Different Eager Loading

```php
Route::bindUsing('order')
    ->forRoute('orders.show', function (string $value) {
        // Show page needs items and customer
        return Order::with(['items', 'customer'])->findOrFail($value);
    })
    ->forRoute('orders.edit', function (string $value) {
        // Edit page needs items with products
        return Order::with(['items.product', 'customer'])->findOrFail($value);
    })
    ->forRoute('orders.invoice', function (string $value) {
        // Invoice needs everything
        return Order::with(['items.product', 'customer', 'payments'])->findOrFail($value);
    })
    ->otherwise(fn (string $value) => Order::findOrFail($value));
```

#### Example 3: Using Query Objects

```php
use App\Http\Web\Queries\OrderShowQuery;
use App\Http\Web\Queries\OrderEditQuery;
use App\Http\Admin\Queries\AdminOrderShowQuery;

Route::bindUsing('order')
    ->forRoute('orders.show', fn (string $value) => new OrderShowQuery($value)->firstOrFail())
    ->forRoute('orders.edit', fn (string $value) => new OrderEditQuery($value)->firstOrFail())
    ->forRoute('admin.orders.*', fn (string $value) => new AdminOrderShowQuery($value)->firstOrFail())
    ->otherwise(fn (string $value) => Order::findOrFail($value));
```

#### Example 4: Multi-Tenancy

```php
Route::bindUsing('customer')
    ->forRoute('tenant.*', function (string $value) {
        // Tenant routes scope to current tenant
        return Customer::where('id', $value)
            ->where('tenant_id', tenant()->id)
            ->firstOrFail();
    })
    ->forRoute('admin.*', function (string $value) {
        // Admin sees all customers across tenants
        return Customer::with('tenant')->findOrFail($value);
    })
    ->otherwise(fn (string $value) => Customer::findOrFail($value));
```

#### Example 5: Custom Conditions

**Using `when()` for more complex logic:**

```php
Route::bindUsing('document')
    ->when(
        fn(Route $route) => $route->parameter('status') === 'draft',
        fn(string $value) => Document::where('id', $value)->where('status', 'draft')->firstOrFail()
    )
    ->when(
        fn(Route $route) => $route->hasParameter('archived'),
        fn(string $value) => Document::onlyTrashed()->findOrFail($value)
    )
    ->otherwise(fn(string $value) => Document::findOrFail($value));
```

## When to Use Conditional Binding

**Use conditional binding when:**
- Admin routes need soft-deleted records
- Different routes need different eager loading strategies
- Multi-tenant applications need tenant scoping
- Public vs authenticated routes have different access rules
- API versioning requires different model resolution
- Routes have varying authorization requirements

**Don't use conditional binding when:**
- All routes use the same resolution logic (use simple binding)
- Logic can be handled in controller/action (keep it simple)
- You're just adding one constraint (use simple binding with constraint)

## Pattern: Route Naming for Conditional Binding

**Name your routes consistently to leverage pattern matching:**

```php
// ✅ Good - clear patterns
Route::name('admin.')->group(function () {
    Route::get('/orders/{order}', ...)->name('orders.show'); // admin.orders.show
});

Route::name('api.v2.')->group(function () {
    Route::get('/orders/{order}', ...)->name('orders.show'); // api.v2.orders.show
});

// Then use patterns in binding
Route::bindUsing('order')
    ->forRoute('admin.*', fn(...) => /* admin logic */)
    ->forRoute('api.v2.*', fn(...) => /* v2 logic */)
    ->otherwise(fn(...) => /* default */);
```

## Testing Route Bindings

```php
it('resolves order for public routes', function () {
    $order = Order::factory()->create();

    $response = $this->get(route('orders.show', $order));

    $response->assertOk();
});

it('resolves soft-deleted order for admin routes', function () {
    $order = Order::factory()->create();
    $order->delete();

    $response = $this->actingAs($admin)
        ->get(route('admin.orders.show', $order));

    $response->assertOk();
});

it('throws 404 for soft-deleted order on public routes', function () {
    $order = Order::factory()->create();
    $order->delete();

    $response = $this->get(route('orders.show', $order));

    $response->assertNotFound();
});
```

## Summary

**Route model binding should:**
- Use simple binding for straightforward cases
- Use conditional binding when resolution varies by route
- Use query objects for complex logic
- Fail explicitly with `firstOrFail()`
- Have clear route naming patterns

**Route model binding should NOT:**
- Contain business logic (use Actions)
- Modify data (read-only)
- Have complex authorization (use Policies)
- Replace proper eager loading strategies

**See also:**
- [service-providers.md](../../laravel-providers/references/service-providers.md) - AppServiceProvider setup
- [Controllers](../../laravel-controllers/SKILL.md) - Using bound models in controllers
