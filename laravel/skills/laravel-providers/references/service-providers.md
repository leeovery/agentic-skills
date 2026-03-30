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
        $this->bootMorphMaps();
        $this->bootModelConfig();
        $this->bootDebug();
        $this->bootDates();
        $this->bootRateLimiters();
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
        ray("[LAZY LOAD] Loaded [{$relation}] on model [{$class}] with ID [{$model->id}].")
            ->green();
        ray()->backtrace()->hide()->green();
    });
}
```

**Why this configuration:**
- `Model::unguard()` - No need for `$fillable`/`$guarded` arrays
- `automaticallyEagerLoadRelationships()` - Auto-eager load when accessed
- `preventLazyLoading()` - Catch N+1 queries in development
- `handleLazyLoadingViolationUsing()` - Surface violations in Ray instead of throwing

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

See [route-binding.md](../../laravel-routing/references/route-binding.md) for route model binding configuration including the ConditionalRouteBinder pattern.

## Morph Maps (Required)

**Always enforce polymorphic relation mappings in all projects:**

```php
use Illuminate\Database\Eloquent\Relations\Relation;

private function bootMorphMaps(): void
{
    Relation::enforceMorphMap([
        'User' => User::class,
        'Order' => Order::class,
        'Customer' => Customer::class,
        'Product' => Product::class,
    ]);
}
```

**Why enforce morph maps?**
- **Required for all projects** - Use `enforceMorphMap()` to ensure consistency
- Database stores `'Order'` instead of `'App\Models\Order'`
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

## Debug Configuration (Development Only)

**Configure debugging tools:**

```php
private function bootDebug(): void
{
    if ($this->app->isLocal() && ! $this->app->runningInConsole()) {
        ray()->label('Slow query detected')->orange()->showSlowQueries(100);
        ray()->label('Duplicate query detected')->orange()->showDuplicateQueries();
    }
}
```

**Optional — show all queries:**
```php
ray()->showQueries()->orange();
```

## Complete Example

```php
<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Faker\CarbonImmutableProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->bootMorphMaps();
        $this->bootModelConfig();
        $this->bootDebug();
        $this->bootDates();
        $this->bootRateLimiters();
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

    private function bootMorphMaps(): void
    {
        Relation::enforceMorphMap([
            'User' => User::class,
            'Order' => Order::class,
        ]);
    }

    private function bootModelConfig(): void
    {
        Model::unguard();
        Model::preventLazyLoading($this->app->isLocal());
        Model::handleLazyLoadingViolationUsing(function ($model, $relation): void {
            $class = $model::class;
            ray("[LAZY LOAD] Loaded [{$relation}] on model [{$class}] with ID [{$model->id}].")
                ->green();
            ray()->backtrace()->hide()->green();
        });
    }

    private function bootDebug(): void
    {
        if ($this->app->isLocal() && ! $this->app->runningInConsole()) {
            ray()->label('Slow query detected')->orange()->showSlowQueries(100);
            ray()->label('Duplicate query detected')->orange()->showDuplicateQueries();
        }
    }

    private function bootDates(): void
    {
        Date::use(CarbonImmutable::class);
        if (function_exists('fake')) {
            fake()->addProvider(new CarbonImmutableProvider(fake()));
        }
    }

    private function bootRateLimiters(): void
    {
        RateLimiter::for('login', function (Request $request) {
            if (environment('local', 'dev')) {
                return Limit::none();
            }

            $throttleKey = Str::transliterate(
                Str::lower($request->input(Fortify::username())).'|'.$request->ip()
            );

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('api', function (Request $request) {
            if (environment('local', 'dev')) {
                return Limit::none();
            }

            return Limit::perMinute(120)
                ->by($request->user()?->id ?: $request->ip());
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
private function bootDebug(): void { }

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

