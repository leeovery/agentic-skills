---
name: laravel-value-objects
description: Domain values are immutable value objects in app/Values, validated on construction, compared by value and cast onto models through Castable. Use when creating or changing a value object, its model cast, an action's result object, or money handling.
---

# Laravel Value Objects

A value object is a domain value that has rules: a coordinate, a date range, an email address. It is defined by what it holds, not by an identity, and it can't exist in an invalid state, so code that receives one never checks it again. Behaviour that belongs to the value lives on it. A DTO only carries data between layers ([laravel-dtos](../laravel-dtos/SKILL.md)); once a value has rules or behaviour of its own, it's a value object.

## Rules

- **Lives in `app/Values/`, named for the value.** `Coordinate`, `DateRange`, never `CoordinateValue` or `CoordinateVO`. A project on the domain layout keeps its `ValueObjects/` (see [laravel-architecture](../laravel-architecture/SKILL.md)).
- **Immutable.** A `readonly class` with its parts as public promoted properties, and no setters. An operation that would change it returns a new instance. A value handed to several objects can't then change under one of them.
- **Valid or it doesn't exist.** The public constructor validates its input and throws `InvalidArgumentException`, or the project's domain exception when a caller is expected to handle it. No separate `validate()` or `isValid()` for a caller to forget.
- **Named constructors for other inputs.** `fromString()`, `fromArray()` parse their input, then call `new self(...)`, so validation stays in the constructor. The constructor stays public: it is the plain, validated way in.
- **Equal by value.** An `equals(self $other): bool` that compares the parts. Equality is a domain decision (case, precision, normalisation), and `==` on objects hides it.
- **Behaviour belongs on the value.** Distance between coordinates, whether a range contains a date, formatting the value itself. Anything that needs the database or a service stays in an action.
- **Interfaces only when they're used.** `Stringable` when it's printed, `JsonSerializable` when it's serialised, `Arrayable` when it becomes an array. A `__toString()` that a named constructor can parse back makes the pair round-trip.

## Example

```php
<?php

declare(strict_types=1);

namespace App\Values;

use App\Casts\CoordinateCast;
use Illuminate\Contracts\Database\Eloquent\Castable;
use InvalidArgumentException;
use Stringable;

readonly class Coordinate implements Castable, Stringable
{
    private const float EARTH_RADIUS_KM = 6371.0;

    public function __construct(
        public float $latitude,
        public float $longitude,
    ) {
        throw_unless(
            $latitude >= -90 && $latitude <= 90,
            InvalidArgumentException::class,
            "Latitude must be between -90 and 90, {$latitude} given.",
        );

        throw_unless(
            $longitude >= -180 && $longitude <= 180,
            InvalidArgumentException::class,
            "Longitude must be between -180 and 180, {$longitude} given.",
        );
    }

    public static function fromString(string $value): self
    {
        throw_unless(
            preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*$/', $value, $matches),
            InvalidArgumentException::class,
            "\"{$value}\" is not a latitude,longitude pair.",
        );

        return new self((float) $matches[1], (float) $matches[2]);
    }

    public static function castUsing(array $arguments): string
    {
        return CoordinateCast::class;
    }

    public function equals(self $other): bool
    {
        return $this->latitude === $other->latitude
            && $this->longitude === $other->longitude;
    }

    public function distanceInKmTo(self $other): float
    {
        $latitudeDelta = deg2rad($other->latitude - $this->latitude);
        $longitudeDelta = deg2rad($other->longitude - $this->longitude);

        $haversine = sin($latitudeDelta / 2) ** 2
            + cos(deg2rad($this->latitude)) * cos(deg2rad($other->latitude)) * sin($longitudeDelta / 2) ** 2;

        return self::EARTH_RADIUS_KM * 2 * asin(sqrt($haversine));
    }

    public function __toString(): string
    {
        return "{$this->latitude},{$this->longitude}";
    }
}
```

## Casting onto a model

A value object stored on a model implements `Castable`, and its `castUsing()` names a dedicated cast in `app/Casts/`. The model then casts to the value itself: `'location' => Coordinate::class`. The cast's `set()` accepts only that value object and throws for anything else, so a raw array or string can't be written past it. A value spread over several columns is read from `$attributes` and written back as an array of columns:

```php
<?php

declare(strict_types=1);

namespace App\Casts;

use App\Values\Coordinate;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * @implements CastsAttributes<Coordinate, Coordinate>
 */
class CoordinateCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Coordinate
    {
        if (is_null($attributes['latitude'] ?? null) || is_null($attributes['longitude'] ?? null)) {
            return null;
        }

        return new Coordinate((float) $attributes['latitude'], (float) $attributes['longitude']);
    }

    /**
     * @return array{latitude: ?float, longitude: ?float}
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if (is_null($value)) {
            return ['latitude' => null, 'longitude' => null];
        }

        if (! $value instanceof Coordinate) {
            throw new InvalidArgumentException("The {$key} attribute must be a ".Coordinate::class.'.');
        }

        return ['latitude' => $value->latitude, 'longitude' => $value->longitude];
    }
}
```

## Reach for these when the situation calls for it

**The domain handles money.** Use moneyphp/money's `Money` as the value, build it through one `MoneyFactory`, and store it with a `MoneyCast`. Read [money.md](references/money.md) before writing any code that stores, calculates or displays an amount of money.

**An action can end in more than one expected way, and its caller acts on which.** Return a result object: an outcome enum plus an optional message, with a named constructor per outcome. The caller matches on the outcome. Errors still throw; a result is for outcomes the caller expects, such as "nothing to do".

```php
readonly class ImportResult
{
    public function __construct(
        public ImportOutcome $outcome,
        public ?string $message = null,
    ) {}

    public static function skipped(string $reason): self
    {
        return new self(ImportOutcome::Skipped, $reason);
    }
}
```

Name the enum for the outcome (`ImportOutcome`), not the result, so the two never need an import alias.

## Testing

Unit-test each constructor rule, each named constructor and each behaviour; every rule is a test that fails when the rule goes:

```php
test('will reject a latitude outside -90 to 90', function (): void {
    new Coordinate(90.5, 0.0);
})->throws(InvalidArgumentException::class);
```

More in [laravel-testing](../laravel-testing/SKILL.md).

## Anti-patterns

- ❌ A setter, or a property that isn't `readonly`.
- ❌ A constructor that accepts anything, with validation left to the caller.
- ❌ A private constructor that forces every caller through static factories.
- ❌ `__call()` forwarding to a wrapped library. The value's API should be readable in its class.
- ❌ A value object that queries the database or calls a service.
- ❌ A cast whose `set()` accepts a raw array or string as well as the value object.
- ❌ An array or a loose pair of primitives (`$latitude`, `$longitude`) passed around where a value object exists.

## Related

- [laravel-models](../laravel-models/SKILL.md) — model casts beyond value objects
- [laravel-dtos](../laravel-dtos/SKILL.md) — data that crosses layers, which may hold value objects
- [laravel-enums](../laravel-enums/SKILL.md) — fixed sets of values, and the outcome enum of a result object
- [laravel-actions](../laravel-actions/SKILL.md) — the actions that take value objects and return result objects
- [laravel-exceptions](../laravel-exceptions/SKILL.md) — domain exceptions a constructor can throw
- [laravel-testing](../laravel-testing/SKILL.md) — test style
