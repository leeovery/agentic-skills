# Responses

The three pieces behind every JSON response: the base controller's helpers, an API resource, and the date transformer resources share. Copy them into a project that doesn't have them; where the project already has its own, use those.

## The base controller

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResponseHelpers;

abstract class Controller
{
    use ResponseHelpers;
}
```

No `AuthorizesRequests` or `ValidatesRequests`: authorization is on the route and validation in the form request, so a controller has no way to do either.

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\JsonResponse;

trait ResponseHelpers
{
    protected function json(mixed $content, int $status = 200): JsonResponse
    {
        return response()->json($content, $status);
    }

    protected function created(mixed $content = null, ?string $location = null): JsonResponse
    {
        return $this->withLocation($this->json($content, 201), $location);
    }

    protected function accepted(mixed $content = null, ?string $location = null): JsonResponse
    {
        return $this->withLocation($this->json($content, 202), $location);
    }

    protected function noContent(): JsonResponse
    {
        return $this->json(null, 204);
    }

    private function withLocation(JsonResponse $response, ?string $location): JsonResponse
    {
        if ($location !== null) {
            $response->header('Location', $location);
        }

        return $response;
    }
}
```

`created()` is for a payload that isn't a resource. A resource returned from `store` is already 201, because Laravel checks the model's `wasRecentlyCreated`. Errors aren't returned from here: a failed business rule is a domain exception, rendered by the exception handler ([laravel-exceptions](../../laravel-exceptions/SKILL.md)).

## An API resource

```php
<?php

declare(strict_types=1);

namespace App\Http\Api\V1\Resources;

use App\Http\Api\V1\Resources\Transformers\DateTransformer;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'reference' => $this->reference,
            'state' => $this->state,
            'priority' => $this->priority,
            'isGift' => $this->is_gift,
            'labels' => $this->labels ?? [],
            'customer' => CustomerResource::make($this->whenLoaded('customer')),
            'lines' => OrderLineResource::collection($this->whenLoaded('lines')),
            'linesCount' => $this->whenCounted('lines'),
            'dispatchedAt' => DateTransformer::transform($this->dispatched_at),
            'createdAt' => DateTransformer::transform($this->created_at),
            'updatedAt' => DateTransformer::transform($this->updated_at),
        ];
    }
}
```

- **Backed enums and model states serialise to their value** on their own: `'state' => $this->state` emits `"paid"`.
- **A list field is always a list.** A nullable JSON column goes out as `[]` when empty, never `null`, so clients iterate without a guard.
- **A relation that wasn't loaded is left out**, not `null`. `whenLoaded()` drops the key, which tells the client the relation wasn't asked for rather than that it's empty.

## The date transformer

```php
<?php

declare(strict_types=1);

namespace App\Http\Api\V1\Resources\Transformers;

use Carbon\CarbonImmutable;

class DateTransformer
{
    /**
     * @return array{iso8601: ?string, timestamp: ?int}
     */
    public static function transform(?CarbonImmutable $date): array
    {
        return [
            'iso8601' => $date?->toIso8601String(),
            'timestamp' => $date?->getTimestamp(),
        ];
    }
}
```

A null date keeps the shape with null values, so a client reads `createdAt.iso8601` without first checking the key exists. It lives with the surface's resources: how a surface writes dates is part of its contract, and a V2 can change it without touching V1.
