# Test setup

What a project needs before its first test. A project that already has `tests/Pest.php` and `tests/TestCase.php` keeps them; add to them rather than replacing them.

```bash
composer require --dev pestphp/pest pestphp/pest-plugin-laravel plannr/laravel-fast-refresh-database
```

`tests/Pest.php`:

```php
<?php

declare(strict_types=1);

use Plannr\Laravel\FastRefreshDatabase\Traits\FastRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, FastRefreshDatabase::class)->in('Feature', 'Unit', 'Architecture');
```

Below the `uses()` call go the project's own test helpers, as plain functions: one per way a test signs in, when the app has more than one kind of user or guard, and any custom expectation added with `expect()->extend()`. Every test file then shares them, and the next agent finds them in one place.

`tests/TestCase.php`:

```php
<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Saloon\Config as SaloonConfig;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        SaloonConfig::preventStrayRequests();
    }
}
```

Leave out the Saloon line until the app has a Saloon connector. Add `protected bool $seed = true;` when the app needs reference data to run.

`phpunit.xml` sets the environment and nothing else, so every test setting lives in one place:

```xml
<php>
    <env name="APP_ENV" value="testing"/>
</php>
```

Laravel then loads `.env.testing`, copied from the committed `.env-testing` template ([laravel-providers](../../laravel-providers/SKILL.md)). That template points `DB_DATABASE` at a test database on the same engine as production, sets `QUEUE_CONNECTION=sync`, and keeps each service on its real driver with placeholder credentials ([laravel-services](../../laravel-services/SKILL.md)).
