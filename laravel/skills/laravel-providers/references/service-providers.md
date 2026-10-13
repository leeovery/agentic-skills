# Service providers: dates in factories and local-only tools

## Fake dates as `CarbonImmutable`

`fake()->dateTimeBetween()` returns a mutable `\DateTime`, which a `CarbonImmutable`-typed property rejects. Register a Faker provider that converts the value.

`database/faker/CarbonImmutableProvider.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Faker;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Faker\Provider\Base;
use Faker\Provider\DateTime;

class CarbonImmutableProvider extends Base
{
    public function dateTimeBetween(
        DateTimeInterface|string $startDate = '-30 years',
        DateTimeInterface|string $endDate = 'now',
        ?string $timezone = null,
    ): CarbonImmutable {
        return CarbonImmutable::createFromMutable(
            DateTime::dateTimeBetween($startDate, $endDate, $timezone)
        );
    }
}
```

Autoload the namespace in `composer.json`, next to the factories:

```json
"autoload": {
    "psr-4": {
        "Database\\Faker\\": "database/faker/"
    }
}
```

Register it in `bootDates()`:

```php
<?php

declare(strict_types=1);

namespace App\Providers;

use Carbon\CarbonImmutable;
use Database\Faker\CarbonImmutableProvider;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->bootDates();
    }

    private function bootDates(): void
    {
        Date::use(CarbonImmutable::class);

        if (function_exists('fake')) {
            fake()->addProvider(new CarbonImmutableProvider(fake()));
        }
    }
}
```

The guard is needed. Laravel defines `fake()` only when Faker is installed, and a production install with `--no-dev` leaves it out.

## Local-only dev tools

A tool such as Telescope runs in local only. Keep it out of package discovery in `composer.json`:

```json
"extra": {
    "laravel": {
        "dont-discover": ["laravel/telescope"]
    }
}
```

Leave it and the app's own `TelescopeServiceProvider` out of `bootstrap/providers.php`, and register both from `AppServiceProvider::register()`:

```php
<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Telescope\TelescopeServiceProvider as TelescopePackageServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerTelescope();
    }

    private function registerTelescope(): void
    {
        if ($this->app->environment('local')) {
            $this->app->register(TelescopePackageServiceProvider::class);
            $this->app->register(TelescopeServiceProvider::class);
        }
    }
}
```

Schedule `telescope:prune` with `->environments(...)` set to wherever Telescope runs.
