---
name: laravel-providers
description: App-wide setup lives in named provider methods and invokable booters wired from bootstrap/app.php. Use when changing service providers, model defaults, morph maps, rate limiters, middleware, scheduled tasks, helpers.php or env templates.
---

# Laravel Providers

Application setup has two homes. `AppServiceProvider` sets the app-wide defaults: how models behave, which date class the app uses, the morph map, rate limiters and local debug tooling. Booters, small invokable classes in `app/Booters/`, replace the closures Laravel puts in `bootstrap/app.php` for middleware, the schedule and exceptions. Both stay readable because each concern sits in its own named method or class.

## Rules

- **Named methods.** `register()` and `boot()` only call private `register{Thing}()` and `boot{Thing}()` methods, one per concern: `bootMorphMaps()`, `bootModelConfig()`, `bootDates()`, `bootRateLimiters()`, `bootDebug()`. Then `boot()` reads as a table of contents.
- **Models are unguarded.** Call `Model::unguard()` once, and never put `$fillable` or `$guarded` on a model. Mass-assignment safety comes from typed input (Form Request, then DTO), never from raw request arrays.
- **Lazy loading is prevented locally.** Call `Model::preventLazyLoading($this->app->isLocal())` and report violations to Ray (or the log, where the project doesn't use Ray) rather than throwing, so you see an N+1 while you work without the page breaking. Production pays nothing.
- **The morph map is enforced.** Use `Relation::enforceMorphMap()` and key every entry by the model's PascalCase class basename: `'Order' => Order::class`. An unmapped model then throws instead of storing its class name, and a namespace move can't orphan stored rows. A model goes in as soon as it joins any polymorphic relation, including the ones packages create: Sanctum's `tokenable`, notifications, activity or media logs.
- **Dates are immutable.** Call `Date::use(CarbonImmutable::class)`, so `now()`, `today()` and every date cast return `CarbonImmutable`. As a result, type-hint dates as `CarbonInterface` or `CarbonImmutable`, never `Carbon`, which throws a `TypeError` on the first cast value it receives. Create dates with `now()`, `today()` or `Date::parse()`. `Carbon::parse()` returns a mutable instance.
- **Rate limiters live in `AppServiceProvider::bootRateLimiters()`.** Every limiter goes there, including a service's own, so they're all registered on every request and in every worker.
- **Macros live in one `MacroServiceProvider`.** One place to find every method the app adds to framework classes.
- **Debug tooling is local only.** Ray query watchers and dev-only providers are guarded by `$this->app->isLocal()` or an environment check.
- **`bootstrap/app.php` wires booters, not closures.** `withMiddleware`, `withSchedule` and `withExceptions` each take an invokable booter. Route surfaces are registered through `withRouting()`, which [laravel-routing](../laravel-routing/SKILL.md) owns.
- **Name every scheduled callback.** Every `->call()` gets a `->name()`. `withoutOverlapping()` and `onOneServer()` throw on an unnamed callback, and the name is what `schedule:list` shows. Follow the project's naming style, whether class names or descriptions.

## Example

```php
<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\RateLimiterName;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->bootMorphMaps();
        $this->bootModelConfig();
        $this->bootDates();
        $this->bootRateLimiters();
        $this->bootDebug();
    }

    private function bootMorphMaps(): void
    {
        Relation::enforceMorphMap([
            'Customer' => Customer::class,
            'Order' => Order::class,
            'User' => User::class,
        ]);
    }

    private function bootModelConfig(): void
    {
        Model::unguard();
        Model::preventLazyLoading($this->app->isLocal());
        Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation): void {
            $class = $model::class;
            ray("[LAZY LOAD] Loaded [{$relation}] on model [{$class}] with ID [{$model->getKey()}].")->green();
            ray()->backtrace()->hide()->green();
        });
    }

    private function bootDates(): void
    {
        Date::use(CarbonImmutable::class);
    }

    private function bootRateLimiters(): void
    {
        RateLimiter::for(RateLimiterName::Api, function (Request $request): Limit {
            if (environment('local')) {
                return Limit::none();
            }

            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });
    }

    private function bootDebug(): void
    {
        if ($this->app->isLocal() && ! $this->app->runningInConsole()) {
            ray()->label('Slow query detected')->orange()->showSlowQueries(100);
            ray()->label('Duplicate query detected')->orange()->showDuplicateQueries();
        }
    }
}
```

## Booters

```php
return Application::configure(basePath: dirname(__DIR__))
    ->withMiddleware(new MiddlewareBooter)
    ->withSchedule(new ScheduleBooter)
    ->withExceptions(new ExceptionBooter)
    ->create();
```

Each booter is a class in `app/Booters/` with one `__invoke(Middleware|Schedule|Exceptions $x): void`, plus private methods when it grows. The scheduler calls actions directly: `$schedule->call(ExpireStaleOrdersAction::class)`. Read [references/bootstrap-booters.md](references/bootstrap-booters.md) when writing or changing a booter.

## helpers.php

```php
<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

function environment(string ...$environments): bool|string
{
    if (count($environments) === 0) {
        return app()->environment();
    }

    return app()->environment($environments);
}

function user(?string $guard = null): Authenticatable|User|null
{
    return auth($guard)->user();
}
```

- **Loaded by composer** through `autoload.files`, from `app/helpers.php` or wherever the project already keeps it.
- **No `function_exists` guards.** The file loads once. A guard would hide a clash with a package function of the same name instead of failing loudly.
- **Small, typed, snake_case, no domain logic.** Logic that changes state belongs in an action. Unit-test each helper.
- **`environment('local', 'dev')`** is how app code checks the environment. It returns a bool when given names, and the current name when called without arguments.
- **`user()` takes an optional guard.** Its return union lists the app's authenticatable models, so callers get their methods. Call it from the HTTP layer (controllers, form requests, middleware), never inside an action: the action takes the actor as a parameter.

## Environment files

| Committed template | Copied to (git-ignored) | By |
|---|---|---|
| `.env-local` | `.env` | developer setup |
| `.env-testing` | `.env.testing` | developer setup; Laravel loads it when `APP_ENV=testing` |
| `.env-testing` | `.env` | CI |
| `.env-{environment}` | `.env` | the build, when a deploy builds from the repo |

Templates are dash-named and committed. The files Laravel reads are dot-named, and `.gitignore` lists `.env` and `.env.*`, which never matches a template. There is no `.env.example`: the templates hold working values, so setup is `cp .env-local .env`.

## Reach for these when the situation calls for it

**Templates that hold real secrets.** Encrypt them with git-crypt through `.gitattributes`. Use one key for the local and testing templates (developers and CI) and a second for the deployed environments (`filter=git-crypt-remote`, created with `git-crypt init -k remote`), so access is split.

**Fake dates passed straight into a `CarbonImmutable` parameter**, such as a value object built with `new` in a factory or test. `fake()->dateTimeBetween()` returns a mutable `DateTime`; register a Faker provider that returns `CarbonImmutable`. (laravel-data and Eloquent casts convert a `DateTime` themselves, so DTO and model factories don't need it.) Read [references/service-providers.md](references/service-providers.md).

**A dev-only tool such as Telescope.** Keep it out of package discovery and `bootstrap/providers.php`, and register it from `register()` in local only. See [references/service-providers.md](references/service-providers.md).

**Scheduled tasks that can outlast their interval.** Apply `withoutOverlapping()`, or loop `$schedule->events()` at the end of the ScheduleBooter to apply it to all of them. Every callback must already be named.

**A service with its own manager and facade.** It gets its own provider. [laravel-services](../laravel-services/SKILL.md) owns that recipe.

**Configuring a scoped instance from `boot()`**, such as a custom driver added to a service's manager with `extend()`, or settings a package's manager takes through a `for()` method. A queue worker forgets scoped instances before every job, so a manager configured once at boot is gone before the first job runs, and the one the container builds next has none of it. Register through `$this->callAfterResolving(SmsManager::class, fn (SmsManager $manager) => $manager->extend('log', fn () => new LogSmsDriver))`: it configures every instance the container builds, and the current one if it already exists. To test it, run the worker's reset first, `app()->forgetScopedInstances()` then `Facade::clearResolvedInstances()`, and fake anything scoped after it, because the reset drops those fakes too.

## Anti-patterns

- ❌ A rate limiter, gate, route, listener or macro registered in a deferred provider's `boot()`. During a request that `boot()` runs only once one of its `provides()` services is resolved, so a gate check, a macro call or a sync-dispatched job's `RateLimited` middleware can run before its registration exists. Artisan processes load every deferred provider when they boot, queue workers and the test suite included, so neither catches it. Deferred providers only bind.
- ❌ A scoped instance configured once in `boot()`, such as `$this->app->make(SmsManager::class)->extend(...)`. The worker drops it before the first job.
- ❌ `Relation::morphMap()`, or keys like `'order'` or a full class name.
- ❌ A `Carbon` type hint, or `Carbon::parse()` feeding a `CarbonImmutable` property.
- ❌ `$fillable` or `$guarded` on a model.
- ❌ `Model::automaticallyEagerLoadRelationships()`. It hides the N+1 queries that `preventLazyLoading` exists to surface.
- ❌ Middleware, schedule or exception closures inline in `bootstrap/app.php`.
- ❌ `user()` or `auth()->user()` inside an action.
- ❌ `if (! function_exists('user'))` around an app helper.
- ❌ A `.env.example`, or a committed `.env` or `.env.testing`.

## Related

- [laravel-routing](../laravel-routing/SKILL.md): route surfaces, `withRouting()` and route binding
- [laravel-models](../laravel-models/SKILL.md): model structure, casts and polymorphic relations
- [laravel-dtos](../laravel-dtos/SKILL.md): DTO test factories, including the factory-name resolver registered in `AppServiceProvider::register()`
- [laravel-services](../laravel-services/SKILL.md): per-service providers, managers and facades
- [laravel-actions](../laravel-actions/SKILL.md): what the scheduler calls
- [laravel-exceptions](../laravel-exceptions/SKILL.md): exception reporting and rendering
