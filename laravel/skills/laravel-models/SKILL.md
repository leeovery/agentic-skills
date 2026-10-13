---
name: laravel-models
description: Models hold relationships, casts, accessors and helpers over their own data; collaborators are registered by attribute, public ids live in a separate ulid or uuid column. Use when creating or changing an Eloquent model, its relationships, casts, accessors or public id.
---

# Laravel Models

A model describes a table: its relationships, how each column casts, values derived from its own attributes, and small helpers over that data. It never runs an operation; that is an action. Its builder, factory, policy and collection are registered by attribute above the class, so everything wired to a model is visible in one place. The primary key is Laravel's auto-increment `id`. An entity the outside world addresses also gets a separate `ulid` or `uuid` column, and that is the only id it exposes.

## Rules

- **Models describe data; actions act on it.** A model method may read its attributes (`isOverdue()`) or change them in memory and leave the save to the caller (`appendToLog()`). Anything that saves, touches another model or calls a service is an action. The one exception is the `updateTo{State}()` and `is{State}()` helpers, which [laravel-state-machines](../laravel-state-machines/SKILL.md) defines.
- **Register collaborators with attributes.** `#[UseEloquentBuilder]` (Laravel 12.19+), `#[UseFactory]`, `#[UsePolicy]` (12.18+) and `#[CollectedBy]` go on the class, so its wiring reads at a glance instead of hiding in method overrides and naming conventions. Below 12.19, override `public function newEloquentBuilder($query): OrderBuilder` instead; below 12.18, the policy is found by naming convention.
- **On Laravel 13, attributes replace model properties too.** `#[Table]`, `#[Hidden]`, `#[Appends]`, `#[Touches]` rather than `$table`, `$hidden`, `$appends`, `$touches`. Never the mass-assignment ones: models are unguarded globally (see [laravel-providers](../laravel-providers/SKILL.md)).
- **Query constraints live in the model's builder.** Never local scopes, `#[Scope]` included. [laravel-query-builders](../laravel-query-builders/SKILL.md) owns builders.
- **Relationship return types are what the method returns.** `BelongsTo`, `HasMany`, `BelongsToMany`. Never a union with the related model or its builder: the method never returns either.
- **Casts go in the `casts()` method.** It can build casts with calls such as `AsEnumCollection::of(Tag::class)`, which a `$casts` property can't, so every model uses the one form.
- **Cast every column to its richest type.** Enums, states, DTOs and value objects, per the table below. Code then reads typed objects, never raw strings and arrays. `'array'` is only for free-form JSON with no fixed shape.
- **Accessors are public, get-only `Attribute` methods.** `new Attribute(get: fn (): string => ...)`, typically projecting a field of a cast DTO or combining columns. A derived value has nothing to write back; writes go through actions.
- **A model in a polymorphic relation joins the enforced morph map**, which [laravel-providers](../laravel-providers/SKILL.md) owns.

## Example

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Builders\OrderBuilder;
use App\Data\OrderMetaData;
use App\Enums\OrderChannel;
use App\Models\Concerns\HasUlid;
use App\Policies\OrderPolicy;
use App\States\Order\OrderState;
use App\Values\EmailAddress;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\ModelStates\HasStates;

#[UseEloquentBuilder(OrderBuilder::class)]
#[UseFactory(OrderFactory::class)]
#[UsePolicy(OrderPolicy::class)]
class Order extends Model
{
    use HasFactory, HasStates, HasUlid;

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }

    public function giftMessage(): Attribute
    {
        return new Attribute(get: fn (): ?string => $this->meta->giftMessage);
    }

    public function isOverdue(): bool
    {
        return $this->due_at?->isPast() ?? false;
    }

    protected function casts(): array
    {
        return [
            'state' => OrderState::class,
            'channel' => OrderChannel::class,
            'meta' => OrderMetaData::class,
            'contact_email' => EmailAddress::class,
            'due_at' => 'datetime',
        ];
    }
}
```

## Casts

| Column holds | Cast | Owner of the type |
|---|---|---|
| One of a fixed set of values | `'channel' => OrderChannel::class` | [laravel-enums](../laravel-enums/SKILL.md) |
| A status with transitions | `'state' => OrderState::class` | [laravel-state-machines](../laravel-state-machines/SKILL.md) |
| JSON with a known shape | `'meta' => OrderMetaData::class`; a laravel-data `Data` class casts itself | [laravel-dtos](../laravel-dtos/SKILL.md) |
| A JSON list of DTOs | `'lines' => DataCollection::class.':'.OrderLineData::class` | [laravel-dtos](../laravel-dtos/SKILL.md) |
| A JSON list of enum values | `'tags' => AsEnumCollection::of(Tag::class)` | [laravel-enums](../laravel-enums/SKILL.md) |
| A domain value | `'contact_email' => EmailAddress::class` | [laravel-value-objects](../laravel-value-objects/SKILL.md) |
| A date | `'due_at' => 'datetime'`, which returns `CarbonImmutable` ([laravel-providers](../laravel-providers/SKILL.md)) | |

A value object is cast by naming the value object itself. It implements `Castable`, and its `castUsing()` returns a cast class in `app/Casts/`. The cast's `set()` accepts only the value object or `null`, so a raw string can't skip the value object's own validation:

```php
class EmailAddressCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?EmailAddress
    {
        return $value === null ? null : new EmailAddress($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! $value instanceof EmailAddress) {
            throw new InvalidArgumentException("[{$key}] must be set with an EmailAddress.");
        }

        return $value->value;
    }
}
```

## Public ids

The primary key stays the auto-increment `id`; foreign keys and joins use it. A model addressed from outside the app (in a URL, an API response, another system) also gets a unique `ulid` or `uuid` column. Pick one per project and use it everywhere.

```php
Schema::create('orders', function (Blueprint $table): void {
    $table->id();
    $table->ulid()->unique();
    $table->foreignId('customer_id')->constrained();
    // ...
});
```

A model concern wraps Laravel's trait and points it at that column, so the primary key stays an integer:

```php
trait HasUlid
{
    use HasUlids;

    public function uniqueIds(): array
    {
        return ['ulid'];
    }
}
```

The UUID version has the same shape over `HasUuids` (Laravel 12 generates v7, time-ordered): copy [references/HasUuid.php](references/HasUuid.php) when the project uses UUIDs. Both live in `app/Models/Concerns/`. Routes bind on the column by name, `{order:ulid}` ([laravel-routing](../laravel-routing/SKILL.md)); the trait then returns a 404 for a malformed id before querying.

## Reach for these when the situation calls for it

**The same operation keeps running over a model's results.** Give the model its own collection, `class OrderCollection extends Collection` in `app/Collections/`, with the method on it, registered with `#[CollectedBy(OrderCollection::class)]`.

**A column derived only from the model's own attributes**, such as a search blob built from its other columns. Compute it in `booted()` with `static::saving(...)`, so it can never go stale. Anything that reaches beyond the model belongs in an action.

## Anti-patterns

- ❌ `public function lines(): HasMany|OrderLine`.
- ❌ `scopePaid()` or `#[Scope]` on a model.
- ❌ `protected $casts = [...]`.
- ❌ `'meta' => 'array'` for JSON whose shape is known.
- ❌ `$fillable`, `$guarded`, or Laravel 13's `#[Fillable]`, `#[Guarded]`, `#[Unguarded]`.
- ❌ `use HasUlids;` directly on a model: with no `uniqueIds()` it makes the primary key the ULID.
- ❌ A `creating` hook that fills a uuid by hand.
- ❌ The auto-increment `id` in a URL or API response for a public entity.
- ❌ `getRouteKeyName()` overridden to the ulid: the route names its binding column.
- ❌ A model method that saves, changes another model or calls a service.

## Related

- [laravel-query-builders](../laravel-query-builders/SKILL.md): the model's builder and every query constraint
- [laravel-state-machines](../laravel-state-machines/SKILL.md): state casts and the `updateTo{State}()`/`is{State}()` helpers
- [laravel-providers](../laravel-providers/SKILL.md): unguarded models, lazy-loading prevention, the morph map, immutable dates
- [laravel-dtos](../laravel-dtos/SKILL.md), [laravel-enums](../laravel-enums/SKILL.md), [laravel-value-objects](../laravel-value-objects/SKILL.md): the types columns cast to
- [laravel-routing](../laravel-routing/SKILL.md): binding routes to public ids
- [laravel-actions](../laravel-actions/SKILL.md): where changes to models happen
- [laravel-policies](../laravel-policies/SKILL.md): the policy `#[UsePolicy]` registers
