---
name: laravel-query-builders
description: Reusable query constraints live on a custom Eloquent builder per model, never in local scopes, and every query starts with Model::query(). Use when writing Eloquent queries, adding a query constraint or scope, defining an eager-load set, or registering a model's builder.
---

# Laravel Query Builders

When a model needs query constraints that more than one caller uses, they go on a custom builder for that model: `OrderBuilder extends Builder`, one method per constraint, each returning `self` so they chain. Local scopes are never used. Every query is started with `Model::query()`, so the chain is typed as the model's builder from its first call.

## Rules

- **Start every query with `Model::query()`.** `Order::query()->wherePaid()->get()`, never `Order::wherePaid()` or `Order::where(...)`. Every chain then reads the same way and starts from a real method that returns the model's builder, rather than a static call forwarded through `__callStatic` and `__call`.
- **A builder, never a local scope.** No `scopeWherePaid()`. A builder method is a plain, typed method that autocompletes and can call the other constraints on the builder.
- **Create a builder when a model needs a reusable constraint.** Not for every model, and never empty "for later". A one-off `where()` stays inline in the action or query class that uses it.
- **One builder per model, named `{Model}Builder`.** It lives in `Builders/` beside the other classes of its kind (see [laravel-architecture](../laravel-architecture/SKILL.md)).
- **Register it with `#[UseEloquentBuilder(OrderBuilder::class)]` on the model** (Laravel 12.19+). Below 12.19, override `newEloquentBuilder()`, as shown after the example.
- **Name by what the method does.** `where*` adds a constraint: `wherePaid()`, `whereCustomer()`, `whereHasBackorderedLines()`. `with*` loads relations or adds a computed column: `withRelations()`, `withLowestBid()`.
- **Methods compose.** A constraint built from others calls them (`whereOverdue()` calls `wherePaid()`), so each rule is written once.
- **Type parameters as the domain types they are.** Models, enums, contracts. A related model goes through `whereBelongsTo($customer)`; never accept `Customer|int` and branch on `instanceof`.
- **Dates are `CarbonInterface`, never `Carbon`.** The app runs on `CarbonImmutable` (see [laravel-providers](../laravel-providers/SKILL.md)), so a `Carbon` parameter throws a `TypeError` when passed `now()`.
- **Type every query closure.** A `whereHas()` closure receives the related model's builder, `function (OrderLineBuilder $query): void`; a nested `where()` closure receives this one, `function (OrderBuilder $query): void`. Eager-load constraints receive the relation, which forwards to the builder: type them as the union, `HasMany|OrderLineBuilder`. The type is what makes the builder's methods autocomplete inside the closure.
- **`withRelations()` is the model's eager-load set.** When a model is usually loaded with the same relations, they're listed once, in `withRelations()`, and reused by query classes, route binding and other builders' eager-load constraints. One name on every builder; never `withRelated()`, `withAll()` or `withFullRelations()`.

## Example

```php
<?php

declare(strict_types=1);

namespace App\Builders;

use App\Models\Customer;
use App\States\Order\OrderPaid;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @method static OrderBuilder whereState(string $column, string|array $state)
 * @method static OrderBuilder whereNotState(string $column, string|array $state)
 */
class OrderBuilder extends Builder
{
    public function whereOverdue(CarbonInterface $asOf): self
    {
        return $this
            ->wherePaid()
            ->where('dispatch_by', '<', $asOf);
    }

    public function wherePaid(): self
    {
        return $this->whereState('state', OrderPaid::class);
    }

    public function whereCustomer(Customer $customer): self
    {
        return $this->whereBelongsTo($customer);
    }

    public function whereHasBackorderedLines(): self
    {
        return $this->whereHas('lines', function (OrderLineBuilder $query): void {
            $query->whereBackordered();
        });
    }

    public function withRelations(): self
    {
        return $this->with([
            'customer',
            'lines' => function (HasMany|OrderLineBuilder $query): void {
                $query->withRelations();
            },
        ]);
    }
}
```

The model registers it:

```php
use App\Builders\OrderBuilder;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;

#[UseEloquentBuilder(OrderBuilder::class)]
class Order extends Model
{
    // ...
}
```

Below Laravel 12.19, override the method instead. `$query` stays untyped: the parent's parameter is untyped, and PHP rejects a narrower type in an override.

```php
class Order extends Model
{
    public function newEloquentBuilder($query): OrderBuilder
    {
        return new OrderBuilder($query);
    }
}
```

A caller reads as a sentence:

```php
Order::query()
    ->whereCustomer($customer)
    ->whereOverdue(now())
    ->withRelations()
    ->get();
```

## Reach for these when the situation calls for it

**A package adds scopes to the model**, such as spatie/laravel-model-states' `whereState()`. Declare each with `@method static OrderBuilder` on the builder, as in the example, so chains through them stay typed.

**Several models share a constraint through a model concern and contract**, such as ownership. Put the constraint in a builder trait, `Builders/Concerns/IsOwnableScopes`, used by each of those models' builders. It reads the column from the model rather than hard-coding it: `$this->where($this->getModel()->getOwnedByField(), $owner->getKey())`, taking the contract (`Ownable $owner`) as its parameter.

**Every query on a model must carry a constraint.** Add a global scope registered with `#[ScopedBy(...)]`, and have its `apply()` call the builder's method rather than repeat the constraint. "No local scopes" doesn't rule out global ones.

**The project's PHPStan level reports missing generic types.** Add `/** @extends Builder<Order> */` to the builder. Otherwise leave it off; the `self` return types already carry the type.

**An index listing filtered, sorted and paginated from request input.** That's a query class built on the builder, not a builder method; see [laravel-controllers](../laravel-controllers/SKILL.md).

## Anti-patterns

- ❌ `public function scopePaid(Builder $query)`: a local scope.
- ❌ `Order::where('state', ...)` or `Order::wherePaid()`: no `query()`.
- ❌ An empty builder created in case it's needed later.
- ❌ `wherePlacedBetween(Carbon $from, Carbon $to)`.
- ❌ `whereCustomer(Customer|int $customer)` with an `instanceof` branch.
- ❌ `whereHas('lines', function ($query) { ... })`: an untyped closure.
- ❌ The same `->with([...])` list repeated across query classes instead of one `withRelations()`.
- ❌ Request input, pagination or sorting parameters read inside a builder method.

## Related

- [laravel-models](../laravel-models/SKILL.md): the model a builder belongs to, and its relationships
- [laravel-controllers](../laravel-controllers/SKILL.md): query classes for index listings
- [laravel-state-machines](../laravel-state-machines/SKILL.md): the states that `whereState()` constrains
- [laravel-providers](../laravel-providers/SKILL.md): `CarbonImmutable` dates, and the lazy-loading reporting an incomplete `withRelations()` sets off
