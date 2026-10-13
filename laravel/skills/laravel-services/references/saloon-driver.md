# A Saloon driver, end to end

The HTTP side of the `Shipping` service in [SKILL.md](../SKILL.md): its config, contract, connector, requests, the real and null drivers, and the driver test. `ShipmentData` and `ShipmentDataTransformer` are written the way [laravel-dtos](../../laravel-dtos/SKILL.md) describes.

## Config

`config/shipping.php`. The manager in SKILL.md reads it.

```php
<?php

declare(strict_types=1);

return [
    'default' => env('SHIPPING_DRIVER', 'shipping_v1'),
    'drivers' => [
        'shipping_v1' => [
            'driver' => 'shipping_v1',
            'base_url' => env('SHIPPING_V1_BASE_URL'),
            'token' => env('SHIPPING_V1_TOKEN'),
        ],
        'null' => [
            'driver' => 'null',
        ],
    ],
];
```

## Contract

```php
<?php

declare(strict_types=1);

namespace App\Services\Shipping\Contracts;

use App\Services\Shipping\Data\ShipmentData;

interface ShippingDriver
{
    public function getShipment(string $reference): ShipmentData;

    public function cancelShipment(string $reference, string $reason): void;
}
```

A failed call throws, so a method with nothing to hand back returns `void`, not `bool`.

## Connector

```php
<?php

declare(strict_types=1);

namespace App\Services\Shipping\Connector;

use Saloon\Http\Auth\TokenAuthenticator;
use Saloon\Http\Connector;
use Saloon\Traits\Plugins\AlwaysThrowOnErrors;

class ShippingConnector extends Connector
{
    use AlwaysThrowOnErrors;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
    ) {}

    public function resolveBaseUrl(): string
    {
        return $this->baseUrl;
    }

    protected function defaultAuth(): TokenAuthenticator
    {
        return new TokenAuthenticator($this->token);
    }
}
```

An API that wants its key in a named header takes `new HeaderAuthenticator($this->token, 'X-Api-Key')` instead. Both are in `Saloon\Http\Auth`.

## Requests

```php
<?php

declare(strict_types=1);

namespace App\Services\Shipping\Requests\V1;

use App\Services\Shipping\Data\ShipmentData;
use App\Services\Shipping\Transformers\ShipmentDataTransformer;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Http\Response;

class GetShipmentRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        private readonly string $reference,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/shipments/{$this->reference}";
    }

    public function createDtoFromResponse(Response $response): ShipmentData
    {
        return ShipmentDataTransformer::fromPayload($response->json());
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Services\Shipping\Requests\V1;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

class CancelShipmentRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $reference,
        private readonly string $reason,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/shipments/{$this->reference}/cancel";
    }

    protected function defaultBody(): array
    {
        return [
            'reason' => $this->reason,
        ];
    }
}
```

`HasJsonBody` sets `Content-Type: application/json` on the request that uses it, which is why the connector sets no headers: a form or multipart request on the same connector needs a different one.

## Drivers

```php
<?php

declare(strict_types=1);

namespace App\Services\Shipping\Drivers;

use App\Services\Shipping\Connector\ShippingConnector;
use App\Services\Shipping\Contracts\ShippingDriver;
use App\Services\Shipping\Data\ShipmentData;
use App\Services\Shipping\Requests\V1\CancelShipmentRequest;
use App\Services\Shipping\Requests\V1\GetShipmentRequest;

class ShippingV1Driver implements ShippingDriver
{
    private readonly ShippingConnector $connector;

    public function __construct(string $baseUrl, string $token)
    {
        $this->connector = new ShippingConnector($baseUrl, $token);
    }

    public function getShipment(string $reference): ShipmentData
    {
        return $this->connector
            ->send(new GetShipmentRequest($reference))
            ->dtoOrFail();
    }

    public function cancelShipment(string $reference, string $reason): void
    {
        $this->connector->send(new CancelShipmentRequest($reference, $reason));
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Services\Shipping\Drivers;

use App\Services\Shipping\Contracts\ShippingDriver;
use App\Services\Shipping\Data\ShipmentData;
use App\Services\Shipping\Enums\ShipmentStatus;

class NullDriver implements ShippingDriver
{
    public function getShipment(string $reference): ShipmentData
    {
        return new ShipmentData(
            reference: $reference,
            trackingNumber: null,
            status: ShipmentStatus::Unknown,
            deliveredAt: null,
        );
    }

    public function cancelShipment(string $reference, string $reason): void {}
}
```

## Driver test

The driver is built straight from the testing config, so `.env-testing` keeps `SHIPPING_DRIVER=shipping_v1` and gives it placeholder credentials. The fixture lives at `tests/Fixtures/Saloon/shipping/get_shipment.json`.

```php
<?php

declare(strict_types=1);

use App\Services\Shipping\Drivers\ShippingV1Driver;
use App\Services\Shipping\Enums\ShipmentStatus;
use App\Services\Shipping\Requests\V1\GetShipmentRequest;
use Saloon\Exceptions\Request\Statuses\NotFoundException;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;
use Saloon\MockConfig;

beforeEach(function (): void {
    MockConfig::throwOnMissingFixtures();
});

test('will map a shipment', function (): void {
    Saloon::fake([
        GetShipmentRequest::class => MockResponse::fixture('shipping/get_shipment'),
    ]);

    $driver = new ShippingV1Driver(
        baseUrl: config('shipping.drivers.shipping_v1.base_url'),
        token: config('shipping.drivers.shipping_v1.token'),
    );

    expect($driver->getShipment('SHP-1001'))
        ->reference->toBe('SHP-1001')
        ->status->toBe(ShipmentStatus::Delivered);
});

test('will throw when the shipment does not exist', function (): void {
    Saloon::fake([
        GetShipmentRequest::class => MockResponse::make(body: '', status: 404),
    ]);

    $driver = new ShippingV1Driver(
        baseUrl: config('shipping.drivers.shipping_v1.base_url'),
        token: config('shipping.drivers.shipping_v1.token'),
    );

    $driver->getShipment('SHP-404');
})->throws(NotFoundException::class);
```

`AlwaysThrowOnErrors` raises Saloon's status exception for each failure (`NotFoundException`, `ServerException`, …), all of them `RequestException`s.
