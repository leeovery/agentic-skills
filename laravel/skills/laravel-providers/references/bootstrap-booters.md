# Bootstrap & Booters

Application bootstrapping uses **invokable booter classes** instead of service providers.

**Related guides:**
- [routing-permissions.md](../../laravel-routing/references/routing-permissions.md) - Route configuration
- [structure.md](../../laravel-architecture/references/structure.md) - Project organization

## Philosophy

Booters provide:
- Clean, organized configuration
- Testable bootstrap logic
- Single responsibility classes
- Clear separation of concerns

## Bootstrap File

`bootstrap/app.php`:

```php
<?php

declare(strict_types=1);

use App\Booters\ExceptionBooter;
use App\Booters\MiddlewareBooter;
use App\Booters\ScheduleBooter;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(function () {
        // Web layer (private API or Blade)
        Route::middleware('api')
            ->group(base_path('routes/web.php'));

        // Public API v1
        Route::middleware(['api', 'throttle:api'])
            ->prefix('api/v1')
            ->name('api.v1.')
            ->group(base_path('routes/api/v1.php'));
    })
    ->withMiddleware(new MiddlewareBooter)
    ->withSchedule(new ScheduleBooter)
    ->withExceptions(new ExceptionBooter)
    ->create();
```

## Middleware Booter

`app/Booters/MiddlewareBooter.php`:

```php
<?php

declare(strict_types=1);

namespace App\Booters;

use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;

class MiddlewareBooter
{
    public function __invoke(Middleware $middleware): void
    {
        $middleware
            ->trustProxies('*')
            ->api(append: [
                HandleCors::class,
            ])
            ->throttleApi(redis: true)
            ->validateCsrfTokens(except: [
                'api/*',
            ]);
    }
}
```

## Schedule Booter

`app/Booters/ScheduleBooter.php`:

```php
<?php

declare(strict_types=1);

namespace App\Booters;

use App\Actions\Order\ProcessPendingOrdersAction;
use App\Actions\System\CleanupExpiredSessionsAction;
use Illuminate\Console\Scheduling\Schedule;

class ScheduleBooter
{
    public function __invoke(Schedule $schedule): void
    {
        $schedule
            ->call(ProcessPendingOrdersAction::class)
            ->name('Process pending orders')
            ->everyMinute();

        $schedule
            ->call(CleanupExpiredSessionsAction::class)
            ->name('Cleanup expired sessions')
            ->daily();

        $schedule
            ->command('model:prune')
            ->daily();

        $this->configureAllEvents($schedule);
    }

    private function configureAllEvents(Schedule $schedule): void
    {
        foreach ($schedule->events() as $event) {
            $event->withoutOverlapping();
        }
    }
}
```

## Exception Booter

`app/Booters/ExceptionBooter.php`:

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

        $exceptions->report(function (Throwable $e) {
            // Custom reporting logic
        });
    }
}
```

## Key Patterns

### 1. Invokable Classes

All booters implement `__invoke()`:

```php
public function __invoke(Middleware $middleware): void
{
    // Configuration logic
}
```

### 2. Single Responsibility

Each booter handles one concern:
- **MiddlewareBooter** - Middleware configuration
- **ScheduleBooter** - Task scheduling
- **ExceptionBooter** - Exception handling

### 3. Call Actions Directly

Schedule booter can call actions:

```php
$schedule->call(ProcessPendingOrdersAction::class);
```

### 4. Configuration Methods

Booters can have private helper methods:

```php
private function configureAllEvents(Schedule $schedule): void
{
    foreach ($schedule->events() as $event) {
        $event->withoutOverlapping();
    }
}
```

## Directory Structure

```
app/Booters/
├── ExceptionBooter.php
├── MiddlewareBooter.php
└── ScheduleBooter.php
```

## Benefits

1. **Organized** - Each concern in its own class
2. **Testable** - Booters easy to test in isolation
3. **Clean** - Minimal bootstrap file
4. **Type-safe** - Full type hints
5. **Discoverable** - Clear where config lives

## Summary

**Booters replace service providers** for:
- Middleware configuration
- Task scheduling
- Exception handling

Keep `bootstrap/app.php` minimal and delegate to booters.
