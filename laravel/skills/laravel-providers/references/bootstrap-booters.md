# Booters

A booter replaces one closure in `bootstrap/app.php`. It's a plain class in `app/Booters/` (or the project's support namespace) with a single `__invoke()` that takes what the `with*` method passes it.

| `bootstrap/app.php` | Booter | `__invoke` receives |
|---|---|---|
| `->withMiddleware(new MiddlewareBooter)` | `MiddlewareBooter` | `Illuminate\Foundation\Configuration\Middleware` |
| `->withSchedule(new ScheduleBooter)` | `ScheduleBooter` | `Illuminate\Console\Scheduling\Schedule` |
| `->withExceptions(new ExceptionBooter)` | `ExceptionBooter` | `Illuminate\Foundation\Configuration\Exceptions` |

Route registration stays in `withRouting()`. See [laravel-routing](../../laravel-routing/SKILL.md).

## MiddlewareBooter

```php
<?php

declare(strict_types=1);

namespace App\Booters;

use App\Http\Middleware\EnsureClientVersion;
use App\Http\Middleware\LogApiRequest;
use App\Http\Middleware\VerifyWebhookSignature;
use Illuminate\Foundation\Configuration\Middleware;

class MiddlewareBooter
{
    public function __invoke(Middleware $middleware): void
    {
        $middleware
            ->trustProxies('*')
            ->throttleApi(redis: true)
            ->api(append: [
                LogApiRequest::class,
            ])
            ->group('api_v1', [
                EnsureClientVersion::class,
            ])
            ->alias([
                'webhook-signed' => VerifyWebhookSignature::class,
            ]);
    }
}
```

- Add to the stock `web` and `api` groups with `append:`. Don't redefine them.
- A route surface that needs more than the stock group gets its own named group, `->group('api_v1', [...])`, stacked after the stock one where [laravel-routing](../../laravel-routing/SKILL.md) registers the surface: `Route::middleware(['api', 'api_v1'])`. It lists only what it adds; bindings and the API throttle come from `api`.
- `trustProxies('*')` belongs here when the app runs behind a load balancer. `throttleApi()` throttles the `api` group with the limiter named `api` (`RateLimiterName::Api`, defined in `AppServiceProvider`); pass `redis: true` when Redis backs the cache. A surface that needs its own limit adds `ThrottleRequests::using(RateLimiterName::Partner->value)` to its group instead.
- Don't re-add what Laravel already runs: `HandleCors` is global middleware, and the `api` group has no CSRF check to exclude routes from.

## ScheduleBooter

```php
<?php

declare(strict_types=1);

namespace App\Booters;

use App\Actions\Invoice\SendInvoiceRemindersAction;
use App\Actions\Order\ExpireStaleOrdersAction;
use App\Models\Order;
use Illuminate\Console\Scheduling\Schedule;

class ScheduleBooter
{
    public function __invoke(Schedule $schedule): void
    {
        $schedule
            ->call(ExpireStaleOrdersAction::class)
            ->name('Expire stale orders')
            ->everyFiveMinutes();

        $schedule
            ->call(SendInvoiceRemindersAction::class)
            ->name('Send invoice reminders')
            ->dailyAt('09:30');

        $schedule
            ->command('model:prune', ['--model' => [Order::class]])
            ->daily();

        $this->preventOverlaps($schedule);
    }

    private function preventOverlaps(Schedule $schedule): void
    {
        foreach ($schedule->events() as $event) {
            $event->withoutOverlapping();
        }
    }
}
```

- The schedule calls an action, or a command that calls one. It never contains logic.
- Name every `->call()` before anything that needs the name. `CallbackEvent::withoutOverlapping()` and `onOneServer()` throw a `LogicException` without one. Follow the project's naming style.
- Schedule the housekeeping commands of the packages you install: `model:prune` for prunable models, `sanctum:prune-expired`, `horizon:snapshot` for Horizon metrics, and `telescope:prune` wherever Telescope runs.
- `preventOverlaps()` applies only when a task can outlast its interval. Drop it when none can.
- When the booter grows, split it into private methods by concern. Follow the project's existing shape.

## ExceptionBooter

```php
<?php

declare(strict_types=1);

namespace App\Booters;

use Illuminate\Foundation\Configuration\Exceptions;
use Sentry\Laravel\Integration;

class ExceptionBooter
{
    public function __invoke(Exceptions $exceptions): void
    {
        Integration::handles($exceptions);
    }
}
```

The error tracker's hook goes here, Sentry in this case. How exceptions report and render is [laravel-exceptions](../../laravel-exceptions/SKILL.md)' concern.
