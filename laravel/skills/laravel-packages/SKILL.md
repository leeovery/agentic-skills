---
name: laravel-packages
description: A package holds self-contained infrastructure that knows nothing of the app installing it, built from a package-tools provider, a manager with drivers, a facade that ships a fake, and Testbench tests. Use when extracting code into a composer package or writing or changing one.
---

# Laravel Packages

A package is an infrastructure capability any app could install: sending SMS through a gateway, rate-limiting outbound calls, a client for a third-party API. It is built like an in-app service ([laravel-services](../laravel-services/SKILL.md)): a manager picks a driver behind a contract, a `NullDriver` stands in for the real one, and callers go through a facade. Three things differ. It registers through `spatie/laravel-package-tools`, it ships a fake so the apps that install it can test against it, and it is tested on its own with Orchestra Testbench, never through an app.

```
laravel-sms/
├── config/sms.php
├── src/
│   ├── Contracts/SmsDriver.php
│   ├── Drivers/{HttpDriver,NullDriver}.php
│   ├── Exceptions/SmsException.php
│   ├── Facades/Sms.php
│   ├── SmsFake.php
│   ├── SmsManager.php
│   └── SmsServiceProvider.php
└── tests/                    # Drivers/, Facades/, ArchTest.php, Pest.php, TestCase.php
```

## Rules

- **Extract infrastructure, never domain.** A capability with no knowledge of any app's entities or rules is a package as soon as another app could use it, even if only one does today. Domain logic stays in the app: it changes with the app, and a package's API is versioned.
- **Nothing from `App\`.** A package installs into apps it has never seen, and an `App\` class exists in only one of them. When it needs an app type, it takes the class name from its config or declares a contract the app binds.
- **Start from `spatie/package-skeleton-laravel`.** Keep its Testbench composer scripts (`post-autoload-dump` runs `clear` and `prepare`). Replace its Pint and PHPStan setup with [laravel-quality](../laravel-quality/SKILL.md)'s tooling, which applies to packages unchanged, strict types included.
- **Named `{vendor}/laravel-{name}`, namespaced `{Vendor}\{Name}`.** `acme/laravel-sms`, `Acme\Sms`. The provider declares `->name('laravel-sms')`; package-tools strips `laravel-`, so the config file, publish tags (`sms-config`) and install command (`sms:install`) all use the short name.
- **Lean requirements.** Require the `illuminate/*` components the code uses, `spatie/laravel-package-tools`, and what the capability itself needs. Never `laravel/framework` or an app-level library such as `spatie/laravel-data`: every requirement is forced on every app that installs the package, at a version it has to agree with. Config and results are plain classes.
- **The provider extends `PackageServiceProvider`.** `configurePackage()` declares what ships: `hasConfigFile()`, and `hasMigrations()`, `hasRoute()`, `hasCommands()` or `hasInstallCommand()` when it has them. Never hand-written `mergeConfigFrom()` or `publishes()`.
- **Bindings in `packageRegistered()`, scoped and aliased.** `scoped()` the manager and alias it to `'{name}-manager'`. A queue worker forgets scoped instances before every job, so neither the manager nor the drivers it caches carry state from one job into the next.
- **Discovered, never root-aliased.** `extra.laravel.providers` lists the provider; no `extra.laravel.aliases`, because callers import the facade class.
- **The manager falls back to the `NullDriver`.** `getDefaultDriver()` returns `$this->config->get('sms.default') ?? 'null'`. `env()` turns `SMS_DRIVER=null` into PHP `null`, so without the fallback the setting meant to switch the package off fails instead.
- **Manager, drivers, config and facade as laravel-services writes them.** A contract, a `NullDriver`, constructor-injected driver config, a facade whose accessor is the alias string and which `@mixin`s the contract rather than listing `@method` lines. One difference: a driver calls its API through Laravel's HTTP client (`illuminate/http`), not Saloon, which would be one more requirement forced on every app.
- **Ship a fake.** The facade's static `fake()` swaps in a `{Name}Fake` that implements the contract and Laravel's `Fake` marker (which `Facade::isFake()` checks), records each call and offers `assert…()` methods. The apps that install the package then test their own code with `Sms::assertSent(...)` instead of mocking the package's internals, and those tests survive its next release. PHPUnit stays in `require-dev`: only tests load the fake.

## Example

```php
<?php

declare(strict_types=1);

namespace Acme\Sms;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * @codeCoverageIgnore
 */
class SmsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-sms')
            ->hasConfigFile();
    }

    public function packageRegistered(): void
    {
        $this->app->scoped(SmsManager::class);
        $this->app->alias(SmsManager::class, 'sms-manager');
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Acme\Sms\Facades;

use Acme\Sms\Contracts\SmsDriver;
use Acme\Sms\SmsFake;
use Illuminate\Support\Facades\Facade;

/**
 * @mixin SmsDriver
 * @mixin SmsFake
 */
class Sms extends Facade
{
    public static function fake(): SmsFake
    {
        static::swap($fake = new SmsFake);

        return $fake;
    }

    public static function getFacadeAccessor(): string
    {
        return 'sms-manager';
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Acme\Sms;

use Acme\Sms\Contracts\SmsDriver;
use Illuminate\Support\Testing\Fakes\Fake;
use PHPUnit\Framework\Assert;

class SmsFake implements SmsDriver, Fake
{
    /** @var list<array{to: string, message: string}> */
    private array $sent = [];

    public function send(string $to, string $message): void
    {
        $this->sent[] = ['to' => $to, 'message' => $message];
    }

    public function assertSent(string $to, ?string $message = null): void
    {
        Assert::assertTrue(
            collect($this->sent)->contains(
                fn (array $sms): bool => $sms['to'] === $to && ($message === null || $sms['message'] === $message),
            ),
            "No matching SMS was sent to [{$to}].",
        );
    }
}
```

## Testing

Tests run from the package's own directory against its own `vendor/`, never through an app, so a test that reaches an `App\` class fails. `Http::preventStrayRequests()` in `setUp()` makes a driver test with no `Http::fake()` fail instead of calling the real API.

```php
<?php

declare(strict_types=1);

namespace Acme\Sms\Tests;

use Acme\Sms\SmsServiceProvider;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    protected function getPackageProviders($app): array
    {
        return [SmsServiceProvider::class];
    }
}
```

- **`$app` stays untyped** in `getPackageProviders()` and `defineEnvironment()`: Testbench declares it untyped, and narrowing a parameter in an override is a fatal error. Config the whole suite needs goes in `defineEnvironment()`; config one test needs is set in that test.
- **`tests/Pest.php` is `uses(TestCase::class)->in(__DIR__);`**, the tests mirror `src/`, and `tests/ArchTest.php` holds laravel-quality's debug-call test without its `ignoring('App\Providers')`. Drivers are built directly in their tests, as in laravel-services; the facade's test covers `fake()` and its assertions. Style follows [laravel-testing](../laravel-testing/SKILL.md).

## Reach for these when the situation calls for it

**The package owns tables.** `hasMigrations('create_sms_messages_table')` reads `database/migrations/create_sms_messages_table.php` and publishes it under the `sms-migrations` tag. Add `runsMigrations()` when apps never need to edit the tables, so installing is enough. Name those files `.php`, not `.php.stub`: the migrator only loads paths ending in `.php`, and silently skips a stub.

**The package works on an app's model.** Put the class in config, `'models' => ['message' => SmsMessage::class]`, defaulting to the package's own, and resolve it from config wherever it's used. The app swaps in its subclass without the package knowing it.

**Setup needs more than publishing config.** `hasInstallCommand(fn (InstallCommand $command): InstallCommand => $command->publishConfigFile()->publishMigrations()->askToRunMigrations())` gives apps one `sms:install` command.

**The first app that needs the package is being built alongside it.** Keep the package in the app's `packages/laravel-sms/`, add a composer `path` repository pointing at it, and require it at `@dev`. Composer symlinks it, so edits land in the app at once. Add its `src` and `tests` to the app's Rector paths; it keeps its own `vendor/` for its tests.

## Anti-patterns

- ❌ `use App\Models\User;` anywhere in a package.
- ❌ `expect('Acme\Sms')->not->toUse('App')` as the guard: no `App\` classes are autoloaded in the package, so it passes even when `src/` imports one.
- ❌ `"laravel/framework"` or `"spatie/laravel-data"` in a package's `require`.
- ❌ `mergeConfigFrom()` and `publishes()` written by hand in a package provider.
- ❌ `return $this->config->get('sms.default');` with no `'null'` fallback.
- ❌ A fake that doesn't implement the contract, or a package with no fake, leaving apps to mock its manager.

## Related

- [laravel-services](../laravel-services/SKILL.md): the manager, drivers, config and facade a package reuses
- [laravel-quality](../laravel-quality/SKILL.md): strict types, Pint, Rector, type coverage and the arch test
- [laravel-architecture](../laravel-architecture/SKILL.md): what stays in the app
- [laravel-exceptions](../laravel-exceptions/SKILL.md): the package's exception factories
- [laravel-testing](../laravel-testing/SKILL.md): test style
