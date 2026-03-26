# Service Providers - Complete Guide

Service providers are the central place for application bootstrapping and configuration.

**Related guides:**
- [route-binding.md](../../laravel-routing/references/route-binding.md) - Route model binding configuration
- [package-extraction.md](../../laravel-packages/references/package-extraction.md) - Creating service providers for packages

## Philosophy

- **Named methods** keep `boot()` and `register()` trim and readable
- **Single responsibility** - each method handles one concern
- **All models unguarded** via `Model::unguard()`
- **Organized** - easy to find and modify specific configurations

## AppServiceProvider Structure

**Use named private methods** to organize your service provider:

```php
<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerModelFactoryResolver();
    }

    public function boot(): void
    {
        $this->bootRouteModelBindings();
        $this->bootMorphMaps();
        $this->bootModelConfig();
        $this->bootRateLimiters();
        $this->bootDates();
        $this->bootGateChecks();
    }

    // Private methods below...
}
```

## Model Configuration

**Always unguard models globally:**

```php
private function bootModelConfig(): void
{
    Model::unguard();
    Model::automaticallyEagerLoadRelationships();
    Model::preventLazyLoading($this->app->isLocal());
    Model::handleLazyLoadingViolationUsing(function ($model, $relation): void {
        $class = $model::class;
        info("Lazy loaded [{$relation}] on [{$class}]");
    });
}
```

**Why this configuration?**
- `Model::unguard()` - No need for `$fillable`/`$guarded` arrays
- `automaticallyEagerLoadRelationships()` - Auto-eager load when accessed
- `preventLazyLoading()` - Catch N+1 queries in development
- `handleLazyLoadingViolationUsing()` - Log violations instead of throwing

## Factory Resolver for Data Classes

**Enable factory support for Spatie Data classes:**

```php
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

private function registerModelFactoryResolver(): void
{
    Factory::guessFactoryNamesUsing(function (string $modelName) {
        if (str($modelName)->endsWith('Data')) {
            return 'Database\Factories\Data\\'.Str::afterLast($modelName, '\\').'Factory';
        }

        return 'Database\Factories\\'.Str::afterLast($modelName, '\\').'Factory';
    });
}
```

**This allows:**

```php
// Create Data objects in tests using factories
$data = OrderData::factory()->create();

// Works alongside model factories
$order = Order::factory()->create();
```

**Directory structure:**

```
database/
└── factories/
    ├── Data/
    │   ├── OrderDataFactory.php
    │   └── CustomerDataFactory.php
    └── OrderFactory.php
```

**See [DTOs](../../laravel-dtos/SKILL.md) for complete factory examples.**

## Route Model Bindings

**Define custom route model bindings:**

```php
use Illuminate\Support\Facades\Route;

private function bootRouteModelBindings(): void
{
    // Simple binding
    Route::bind('order', fn (string $value) => Order::findOrFail($value));

    // Using query objects for complex logic
    Route::bind(
        'order',
        fn (string|int $value) => new OrderShowQuery($value)->firstOrFail()
    );

    // With additional constraints
    Route::bind('activeOrder', function (string $value) {
        return Order::query()
            ->where('id', $value)
            ->whereNotNull('completed_at')
            ->firstOrFail();
    });
}
```

**Usage in routes:**

```php
Route::get('/orders/{order}', [OrderController::class, 'show']);
```

### Advanced: Conditional Route Binding

For complex scenarios where different routes need different resolution strategies for the same parameter, use the **ConditionalRouteBinder** pattern.

**Quick example:**

```php
use Fabric\Support\ConditionalRouteBinder;

private function bootRouteModelBindings(): void
{
    ConditionalRouteBinder::registerMacro();

    Route::bindUsing('order')
        ->forRoute('orders.show', fn (string $value) => Order::findOrFail($value))
        ->forRoute('orders.edit', fn (string $value) => Order::where('id', $value)->where('editable', true)->firstOrFail())
        ->forRoute('admin.*', fn (string $value) => Order::withTrashed()->findOrFail($value))
        ->otherwise(fn (string $value) => Order::findOrFail($value));
}
```

**See [route-binding.md](../../laravel-routing/references/route-binding.md) for:**
- Complete ConditionalRouteBinder class implementation
- Multiple usage examples (admin routes, eager loading, multi-tenancy)
- Query object integration
- Testing strategies
- When to use conditional vs simple binding

## Morph Maps (Required)

**Always enforce polymorphic relation mappings in all projects:**

```php
use Illuminate\Database\Eloquent\Relations\Relation;

private function bootMorphMaps(): void
{
    Relation::enforceMorphMap([
        'order' => Order::class,
        'customer' => Customer::class,
        'product' => Product::class,
        'invoice' => Invoice::class,
    ]);
}
```

**Why enforce morph maps?**
- **Required for all projects** - Use `enforceMorphMap()` to ensure consistency
- Database stores `'order'` instead of `'App\Models\Order'`
- Cleaner database records
- Easier to refactor namespaces
- Smaller database footprint
- Prevents accidental use of full class names in polymorphic relations

## Rate Limiters

```php
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

private function bootRateLimiters(): void
{
    RateLimiter::for('login', function (Request $request) {
        if (app()->isLocal()) {
            return Limit::none();
        }

        $throttleKey = Str::transliterate(
            Str::lower($request->input(Fortify::username())).'|'.$request->ip()
        );

        return Limit::perMinute(5)->by($throttleKey);
    });

    RateLimiter::for('api', function (Request $request) {
        if (app()->isLocal()) {
            return Limit::none();
        }

        return Limit::perMinute(120)
            ->by($request->user()?->id ?: $request->ip());
    });
}
```

## Date Configuration

**Use CarbonImmutable for safer date handling:**

```php
use Carbon\CarbonImmutable;
use Database\Faker\CarbonImmutableProvider;
use Illuminate\Support\Facades\Date;

private function bootDates(): void
{
    Date::use(CarbonImmutable::class);

    // Add custom providers to Faker for testing
    if (function_exists('fake')) {
        fake()->addProvider(new CarbonImmutableProvider(fake()));
    }
}
```

**CarbonImmutableProvider class** (`database/Faker/CarbonImmutableProvider.php`):

```php
<?php

declare(strict_types=1);

namespace Database\Faker;

use Carbon\CarbonImmutable;
use Faker\Provider\Base;
use Faker\Provider\DateTime;

class CarbonImmutableProvider extends Base
{
    public function dateTimeBetween(
        $startDate = '-30 years',
        $endDate = 'now',
        $timezone = null
    ): CarbonImmutable {
        return CarbonImmutable::createFromMutable(
            DateTime::dateTimeBetween($startDate, $endDate, $timezone)
        );
    }
}
```

**Usage in factories:**

```php
OrderData::factory()->create([
    'placed_at' => fake()->dateTimeBetween('-7 days', 'now'),
]);
```

**Why CarbonImmutable?**
- Prevents accidental mutations
- Safer in multi-threaded contexts
- Explicit when you need to modify dates

```php
// With CarbonImmutable
$date = now();
$tomorrow = $date->addDay(); // Returns NEW instance
// $date is unchanged

// With Carbon (mutable)
$date = now();
$tomorrow = $date->addDay(); // MODIFIES $date
// $date is now tomorrow!
```

## Gate Checks

**Define global authorization logic:**

```php
use Illuminate\Support\Facades\Gate;

private function bootGateChecks(): void
{
    Gate::before(function (User $user, string $ability) {
        // Super admins bypass all gates
        if ($user->isSuperAdmin()) {
            return true;
        }

        // Suspended users can't do anything
        if ($user->isSuspended()) {
            return false;
        }
    });
}
```

## Debug Configuration (Development Only)

**Configure debugging tools:**

```php
private function bootDebug(): void
{
    if (! $this->app->isLocal() || $this->app->runningInConsole()) {
        return;
    }

    // Ray debugging
    ray()->showQueries()->orange();
    ray()->label('Slow query')->red()->showSlowQueries(200);
    ray()->label('Duplicate query')->red()->showDuplicateQueries();
}
```

## Complete Example

```php
<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerModelFactoryResolver();
    }

    public function boot(): void
    {
        $this->bootRouteModelBindings();
        $this->bootMorphMaps();
        $this->bootModelConfig();
        $this->bootRateLimiters();
        $this->bootDates();
        $this->bootGateChecks();
    }

    private function registerModelFactoryResolver(): void
    {
        Factory::guessFactoryNamesUsing(function (string $modelName) {
            if (str($modelName)->endsWith('Data')) {
                return 'Database\Factories\Data\\'.Str::afterLast($modelName, '\\').'Factory';
            }

            return 'Database\Factories\\'.Str::afterLast($modelName, '\\').'Factory';
        });
    }

    private function bootRouteModelBindings(): void
    {
        Route::bind('order', fn (string $value) => Order::findOrFail($value));
    }

    private function bootMorphMaps(): void
    {
        Relation::enforceMorphMap([
            'order' => Order::class,
            'customer' => Customer::class,
            'product' => Product::class,
        ]);
    }

    private function bootModelConfig(): void
    {
        Model::unguard();
        Model::automaticallyEagerLoadRelationships();
        Model::preventLazyLoading($this->app->isLocal());
        Model::handleLazyLoadingViolationUsing(function ($model, $relation): void {
            $class = $model::class;
            info("Lazy loaded [{$relation}] on [{$class}]");
        });
    }

    private function bootRateLimiters(): void
    {
        RateLimiter::for('login', function (Request $request) {
            if (app()->isLocal()) {
                return Limit::none();
            }

            $throttleKey = Str::transliterate(
                Str::lower($request->input(Fortify::username())).'|'.$request->ip()
            );

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('api', function (Request $request) {
            if (app()->isLocal()) {
                return Limit::none();
            }

            return Limit::perMinute(120)
                ->by($request->user()?->id ?: $request->ip());
        });
    }

    private function bootDates(): void
    {
        Date::use(CarbonImmutable::class);
    }

    private function bootGateChecks(): void
    {
        Gate::before(function (User $user, string $ability) {
            if ($user->isSuperAdmin()) {
                return true;
            }
        });
    }
}
```

## Organization Tips

**Group related configurations:**

```php
// ✅ Good - organized by concern
private function bootModelConfig(): void { }
private function bootMorphMaps(): void { }
private function bootRouteModelBindings(): void { }

// ❌ Bad - mixed concerns
private function bootModels(): void
{
    Model::unguard();
    Route::bind('order', ...);
    RateLimiter::for('api', ...);
}
```

**Keep methods focused:**

```php
// ✅ Good - single responsibility
private function bootApiRateLimiter(): void { }
private function bootLoginRateLimiter(): void { }

// ❌ Bad - doing too much
private function bootRateLimiters(): void
{
    // 50 lines of different rate limiters...
}
```

**Use descriptive names:**

```php
// ✅ Good
private function registerModelFactoryResolver(): void { }
private function bootDates(): void { }

// ❌ Bad
private function setup(): void { }
private function configure(): void { }
```

## Summary

**Service Providers should:**
- Use named methods for organization
- Call `Model::unguard()` in boot method
- Configure factory resolver for Data classes
- Define morph maps for polymorphic relations
- Keep boot() and register() methods trim
- Group related configurations together

**Service Providers should NOT:**
- Have long boot() or register() methods
- Mix unrelated configurations in one method
- Contain business logic (use Actions)
- Be overly complex (split into multiple providers if needed)

**See also:**
- [Models](../../laravel-models/SKILL.md) - Model unguard pattern
- [DTOs](../../laravel-dtos/SKILL.md) - Data factory setup
