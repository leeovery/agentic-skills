---
name: laravel-services
description: Third-party integrations are services in app/Services/{Name}, built from a manager, drivers behind a contract, Saloon for HTTP, a facade and a deferred provider. Use when integrating an external API, adding a driver, connector or request, or writing a facade-backed service.
---

# Laravel Services

A service wraps something outside the domain, usually a third-party API, behind a facade the rest of the app calls: `Shipping::getShipment($reference)`. An integration is a Laravel `Manager` that picks one of several drivers implementing the same contract. The real driver talks to the API through Saloon; a `NullDriver` stands in where the API shouldn't be called. Everything the service needs lives in its own folder, so it reads, swaps and extracts as one unit. A service holds no business rules: the action that calls it decides what happens.

```
app/Services/Shipping/
├── ShippingManager.php
├── Connector/ShippingConnector.php
├── Contracts/ShippingDriver.php
├── Data/ShipmentData.php                 # DTOs only this service uses
├── Drivers/
│   ├── ShippingV1Driver.php              # the API, through Saloon
│   └── NullDriver.php
├── Enums/ShipmentStatus.php              # enums only this service uses
├── Exceptions/ShippingException.php
├── Facades/Shipping.php
├── Requests/V1/GetShipmentRequest.php
└── Transformers/ShipmentDataTransformer.php   # payload → DTO
app/Providers/ShippingServiceProvider.php
config/shipping.php
tests/Fixtures/Saloon/shipping/
```

## Rules

- **An integration is a manager with drivers.** It always has at least two, the real one and the `NullDriver`, so it always gets a manager. An internal service with one implementation, such as a generator, is a single class in `app/Services/{Name}/` behind a facade, with no manager.
- **Drivers implement the service's contract.** `Contracts/{Name}Driver`. Methods take and return typed values and DTOs, never a Saloon `Response` or a raw payload array, so callers can't tell one driver from another.
- **One driver per API version.** `ShippingV1Driver`, config key `shipping_v1`, requests in `Requests/V1/`. A new API version is a new driver beside the old one, switched by config.
- **Config in `config/{name}.php`.** A `default` read from `{NAME}_DRIVER` and one `drivers` entry per driver. The manager passes the values into the driver's constructor as named arguments; a driver never calls `config()`, so a test can build it directly.
- **Every integration has a `NullDriver`.** It returns empty but valid results and does nothing else. Local work that shouldn't reach the API sets `SHIPPING_DRIVER=null`. `env()` reads that value as PHP `null`, so `getDefaultDriver()` falls back to `'null'`. It isn't the test driver: see Testing.
- **One deferred provider per service, and it only binds.** Bind the manager, alias it to `'{name}-manager'`, and list both in `provides()`. Its `boot()` stays empty: a deferred provider boots only when the manager is first resolved, so the service's rate limiter goes in `AppServiceProvider::bootRateLimiters()` with the rest ([laravel-providers](../laravel-providers/SKILL.md)).
- **The facade resolves the alias and mixes in the contract.** `getFacadeAccessor()` returns `'shipping-manager'`, and `@mixin ShippingDriver` gives every driver method to the IDE with no `@method` list to keep in step. Actions call it, `Shipping::cancelShipment(...)`, or inject the service where that reads better ([laravel-actions](../laravel-actions/SKILL.md)).
- **Saloon for HTTP.** One connector per API in `Connector/`, one request class per endpoint in `Requests/`. Never `Http::` or a hand-built Guzzle client in a driver.
- **The connector throws on failure.** `use AlwaysThrowOnErrors`, so every 4xx and 5xx raises Saloon's `RequestException`. Never check `$response->failed()` by hand.
- **Auth through an authenticator.** `defaultAuth()` returns Saloon's `TokenAuthenticator` or `HeaderAuthenticator`. No `Authorization` header in `defaultHeaders()`, and no `Content-Type` on the connector: `HasJsonBody` sets it on the requests that send JSON.
- **The request maps its response.** `createDtoFromResponse()` hands the payload to the service's transformer, and the driver returns `$response->dtoOrFail()`. How the transformer is written is [laravel-dtos](../laravel-dtos/SKILL.md)' call.

## Example

```php
<?php

declare(strict_types=1);

namespace App\Services\Shipping;

use App\Services\Shipping\Drivers\NullDriver;
use App\Services\Shipping\Drivers\ShippingV1Driver;
use Illuminate\Support\Manager;

class ShippingManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return $this->config->get('shipping.default') ?? 'null';
    }

    public function createShippingV1Driver(): ShippingV1Driver
    {
        return new ShippingV1Driver(
            baseUrl: $this->config->get('shipping.drivers.shipping_v1.base_url'),
            token: $this->config->get('shipping.drivers.shipping_v1.token'),
        );
    }

    public function createNullDriver(): NullDriver
    {
        return new NullDriver;
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Shipping\ShippingManager;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;

class ShippingServiceProvider extends ServiceProvider implements DeferrableProvider
{
    public function register(): void
    {
        $this->app->bind(ShippingManager::class);
        $this->app->alias(ShippingManager::class, 'shipping-manager');
    }

    public function provides(): array
    {
        return [
            ShippingManager::class,
            'shipping-manager',
        ];
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Services\Shipping\Facades;

use App\Services\Shipping\Contracts\ShippingDriver;
use Illuminate\Support\Facades\Facade;

/**
 * @mixin ShippingDriver
 */
class Shipping extends Facade
{
    public static function getFacadeAccessor(): string
    {
        return 'shipping-manager';
    }
}
```

The provider is listed in `bootstrap/providers.php`. Read [references/saloon-driver.md](references/saloon-driver.md) when writing a driver, its config, connector and requests, or its tests.

An internal single-class service gets the same facade with its class as the accessor, written fully qualified because the facade shares its short name: `\App\Services\PasswordGenerator\PasswordGenerator::class`. The container builds it, so it needs no provider. When its constructor needs config, bind it in its own deferred provider and alias it like a manager.

## Testing

| What's under test | How |
|---|---|
| A driver | Build it with the testing config, then `Saloon::fake([GetShipmentRequest::class => MockResponse::fixture('shipping/get_shipment')])` |
| A failure | `MockResponse::make(body: '', status: 404)` and `->throws(NotFoundException::class)` |
| Code that calls the service | `Shipping::shouldReceive('cancelShipment')->once()` |

- **Fixtures are real responses.** `MockResponse::fixture()` records the response when its file is missing; record each one once against the API and commit it under `tests/Fixtures/Saloon/{name}/`. Call `MockConfig::throwOnMissingFixtures()` in the driver test so a missing fixture fails instead of reaching the API.
- **No stray requests.** Call `Saloon\Config::preventStrayRequests()` once, at the top of `tests/Pest.php`, so a Saloon request with no fake throws. It registers a global check each time it's called, so calling it per test in `setUp()` piles them up. `Http::preventStrayRequests()` doesn't catch it, even through Saloon's Laravel HTTP sender, which builds its request outside the factory's stray-request check.
- **`.env-testing` keeps the real driver**, with placeholder credentials, because the driver tests build it from that config. Nothing else reaches it: callers mock the facade.

More in [laravel-testing](../laravel-testing/SKILL.md).

## Reach for these when the situation calls for it

**Callers that handle the failure.** When an action catches a failed call to fall back, mark a record failed or retry later, the driver catches `RequestException|FatalRequestException` and throws the service's own exception with the original as `previous`. Callers then catch one type and the cause still reaches the log. `FatalRequestException`, a request that got no response at all, is not a `RequestException`.

**An API that issues short-lived tokens.** Fetch the token in the connector's `boot()` and keep it and its expiry on the connector. Return early for the token request itself, which boots too. The driver holds one connector for its lifetime, so the token is reused until it expires.

**A slow endpoint.** `use HasTimeout` on the connector, set `$connectTimeout` and `$requestTimeout` (seconds), and add a comment saying why those numbers.

**Transient failures.** Set `$tries` and `$retryInterval` (milliseconds) on the connector or the request. `sendAndRetry()` is deprecated in Saloon v3. Leave retries off when the caller is a queued job that already retries, or each job attempt multiplies them.

**A response that rarely changes.** Cache it in the `Fetch…Action` that calls the service, never in the driver, so the cache key and lifetime sit with the code that decides them.

## Anti-patterns

- ❌ `if ($response->failed()) { throw … }` in a driver.
- ❌ `'Authorization' => "Bearer {$this->token}"` or `'Content-Type' => 'application/json'` in a connector's `defaultHeaders()`.
- ❌ A driver method returning a Saloon `Response`, `$response->json()` or a payload array.
- ❌ `config('shipping.drivers.shipping_v1.token')` inside a driver or connector.
- ❌ A rate limiter, gate, listener or macro in a per-service provider's `boot()`.
- ❌ `SHIPPING_DRIVER=null` in `.env-testing`, or a driver test with no fake that calls the real API.
- ❌ Hand-kept `@method static` lines on an app facade.
- ❌ `$connector->sendAndRetry(...)`.

## Related

- [laravel-actions](../laravel-actions/SKILL.md): the actions that call services
- [laravel-dtos](../laravel-dtos/SKILL.md): the service's `Data/` and `Transformers/`
- [laravel-providers](../laravel-providers/SKILL.md): deferred providers, `bootstrap/providers.php` and rate limiters
- [laravel-exceptions](../laravel-exceptions/SKILL.md): the service's exception factories
- [laravel-architecture](../laravel-architecture/SKILL.md): where `Services/` sits in the app
- [laravel-testing](../laravel-testing/SKILL.md): testing style
