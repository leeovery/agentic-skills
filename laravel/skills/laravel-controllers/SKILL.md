---
name: laravel-controllers
description: Controllers gather input, call one action and return an API resource; listings are query classes; JSON shapes are mapped explicitly. Use when writing or changing a controller, an index or listing endpoint, an API resource, or a JSON response.
---

# Laravel Controllers

A controller is the HTTP edge of one operation. It takes the validated request and the bound models, builds the DTO, calls one action and returns a response. It holds no business rules, no writes and no authorization, so the same operation can run from a job or a command, and the controller stays a few lines long. A listing is a query class; a response is an API resource that maps every field by hand. Everything here is JSON.

## Rules

- **Gather, call one action, respond.** No writes, no business rules, no branching on domain state. Even a lone `$order->update(...)` belongs in an action ([laravel-actions](../laravel-actions/SKILL.md)).
- **Resource methods on a singular `{Model}Controller`.** `OrderController` holds `index`, `show`, `store`, `update` and `destroy`, nothing else. A nested resource is `{Parent}{Child}Controller` (`OrderLineController`).
- **Any other operation is an invokable controller.** A state transition or command is `{Verb}{Model}Controller` with `__invoke()` (`CancelOrderController`); a read-only view that isn't `show` is `{Model}{Noun}Controller` (`OrderHistoryController`). A custom method on a resource controller hides an operation among five others.
- **No return types on controller methods.** The body says what comes back (a resource, a collection, a helper response) and the feature test pins the status and shape; a declared type adds nothing. This is the one exception to [laravel-quality](../laravel-quality/SKILL.md)'s return-type rule.
- **Inject the action into the method, named after its class.** `CancelOrderAction $cancelOrderAction`, never `$action`. Parameters run form request, bound models, then the action.
- **Build the DTO with the surface's transformer.** `CreateOrderDataTransformer::fromRequest($request)` goes straight into the action. A request array never leaves the controller ([laravel-dtos](../laravel-dtos/SKILL.md)).
- **The acting user is `user()`, passed in.** When the operation depends on who's acting, the controller passes `user()` to the action ([laravel-providers](../laravel-providers/SKILL.md) defines the helper).
- **No authorization in the controller.** The route carries `->can()` ([laravel-routing](../laravel-routing/SKILL.md) owns that rule). Never `$this->authorize()` or a `Gate` check; the base controller doesn't use `AuthorizesRequests`, so the call doesn't exist.
- **Validation is the form request's.** Type-hint it; never `$request->validate()` in the method ([laravel-validation](../laravel-validation/SKILL.md)).
- **A read with no rules needs no action.** `show` returns the bound model's resource, loading what it renders with `loadMissing()`. `index` is a query class. A read that computes, caches or calls a service is a `Get…Action`.
- **Extend the app's base controller.** `App\Http\Controllers\Controller` uses the response helpers trait and nothing else.

## Example

```php
<?php

declare(strict_types=1);

namespace App\Http\Api\V1\Controllers;

use App\Actions\Order\CreateOrderAction;
use App\Actions\Order\DeleteOrderAction;
use App\Actions\Order\UpdateOrderAction;
use App\Data\Transformers\Api\V1\CreateOrderDataTransformer;
use App\Data\Transformers\Api\V1\UpdateOrderDataTransformer;
use App\Http\Api\V1\Queries\OrderIndexQuery;
use App\Http\Api\V1\Requests\CreateOrderRequest;
use App\Http\Api\V1\Requests\UpdateOrderRequest;
use App\Http\Api\V1\Resources\OrderResource;
use App\Http\Controllers\Controller;
use App\Models\Order;

class OrderController extends Controller
{
    public function index(OrderIndexQuery $query)
    {
        return OrderResource::collection($query->jsonPaginate());
    }

    public function store(CreateOrderRequest $request, CreateOrderAction $createOrderAction)
    {
        $order = $createOrderAction(user(), CreateOrderDataTransformer::fromRequest($request));

        return OrderResource::make($order);
    }

    public function show(Order $order)
    {
        return OrderResource::make($order->loadMissing(['customer', 'lines']));
    }

    public function update(UpdateOrderRequest $request, Order $order, UpdateOrderAction $updateOrderAction)
    {
        $order = $updateOrderAction($order, UpdateOrderDataTransformer::fromRequest($request));

        return OrderResource::make($order);
    }

    public function destroy(Order $order, DeleteOrderAction $deleteOrderAction)
    {
        $deleteOrderAction($order);

        return $this->noContent();
    }
}
```

A transition gets its own invokable controller, `CancelOrderController`:

```php
public function __invoke(Order $order, CancelOrderAction $cancelOrderAction)
{
    return OrderResource::make($cancelOrderAction($order));
}
```

## Responses

| Endpoint | Returns |
|---|---|
| `show`, `update`, a transition | `OrderResource::make($order)`, 200 |
| `store` | `OrderResource::make($order)`: 201 on its own, because the model `wasRecentlyCreated` |
| `index` | `OrderResource::collection($query->jsonPaginate())` |
| `destroy` | `$this->noContent()` |
| Work handed to a queue | `$this->accepted()` |
| A payload that isn't a model | `$this->json([...])`; `$this->created()` when it made something |

- **One resource per model per surface**, in `Http/{Surface}/Resources/`. A response shape is part of a surface's contract; sharing it across surfaces means changing one changes both.
- **Map every field by hand.** Never `parent::toArray()`: a new column would ship to clients unseen. A `/** @mixin Order */` docblock types `$this`.
- **The public id, never the primary key.** `'ulid' => $this->ulid` ([laravel-models](../laravel-models/SKILL.md) owns public ids).
- **camelCase keys.** An API that already has a published contract keeps its casing.
- **Dates are `{iso8601, timestamp}` objects**, built by the surface's `DateTransformer`, never a raw string: clients get one shape for every date.
- **Relations through `whenLoaded()`**, wrapped in the related model's resource; counts through `whenCounted()`. A resource never triggers a query: the controller or query class loads what it renders.

Read [responses.md](references/responses.md) when writing a resource, or when the project has no base controller helpers or `DateTransformer` yet.

## Index queries

Every index endpoint is a query class: `{Model}IndexQuery extends QueryBuilder` ([spatie/laravel-query-builder](https://spatie.be/docs/laravel-query-builder)) in `Http/{Surface}/Queries/`, even when it only sorts. It's built in its constructor and injected into `index()`, so the listing's allowed filters and sorts are its public contract, in one place.

- **The base query goes to `parent::__construct()`**, starting from `Order::query()` and eager-loading what the resource renders, usually the builder's `withRelations()` ([laravel-query-builders](../laravel-query-builders/SKILL.md)).
- **Public names are kebab-case, aliased to columns.** `AllowedSort::field('date-created', 'created_at')`, `AllowedFilter::partial('customer-email', 'customer_email')`. Column names never leak into the URL.
- **Always a `defaultSort()`**, so an unsorted request still pages in a stable order.
- **A filter with logic calls a builder method.** The callback types the model's builder and stays one line. A comma in a filter value splits it into an array, so `$value` is `string|array` unless the filter can't take a list.
- **Paginate with `jsonPaginate()`**, from spatie/laravel-json-api-paginate (install it alongside). It reads `page[number]` and `page[size]` and caps the size at the config's `max_results`.

```php
<?php

declare(strict_types=1);

namespace App\Http\Api\V1\Queries;

use App\Builders\OrderBuilder;
use App\Models\Order;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

class OrderIndexQuery extends QueryBuilder
{
    public function __construct()
    {
        parent::__construct(Order::query()->withRelations());

        $this
            ->defaultSort('-created_at')
            ->allowedSorts([
                AllowedSort::field('reference'),
                AllowedSort::field('date-created', 'created_at'),
            ])
            ->allowedFilters([
                AllowedFilter::exact('reference'),
                AllowedFilter::partial('customer-email', 'customer_email'),
                AllowedFilter::callback('state', function (OrderBuilder $query, string|array $value): void {
                    $query->whereState('state', $value);
                }),
            ]);
    }
}
```

## Reach for these when the situation calls for it

**A listing shows only what the user may see.** Constrain the base query in the constructor, `Order::query()->whereCustomer(user()->customer)`, so no filter combination can reach past it.

**Several listings filter the same way**, such as a date or date range. Write a class implementing `Spatie\QueryBuilder\Filters\Filter` (`__invoke(Builder $query, mixed $value, string $property)`) in `Http/Queries/Filters/`, taking the column in its constructor: `AllowedFilter::custom('date-created', new DateFilter('created_at'))`.

**The client must tell "nothing yet" from "nothing matches".** Add whether any record exists at all to the collection's meta, from the same base scope as the query class: `->additional(['meta' => ['hasEntities' => Order::query()->exists()]])`. Laravel merges it into the pagination meta.

## Anti-patterns

- ❌ `OrdersController`, or `cancel()` on `OrderController`.
- ❌ `public function store(...): OrderResource`.
- ❌ `$this->authorize('update', $order)` in a controller method.
- ❌ `CreateOrderData::from($request->validated())`, or `$request->all()` passed to an action.
- ❌ `Order::query()->where(...)->paginate()` in `index()`.
- ❌ `return parent::toArray($request);` in a resource, or `'id' => $this->id`.
- ❌ `'createdAt' => $this->created_at`, serialised as a bare string.
- ❌ `'customer' => CustomerResource::make($this->customer)`, lazy-loading inside the resource.
- ❌ One resource class shared by two surfaces.

## Related

- [laravel-actions](../laravel-actions/SKILL.md): the one action a controller calls
- [laravel-routing](../laravel-routing/SKILL.md): route files, names, binding and `->can()`
- [laravel-validation](../laravel-validation/SKILL.md): the form request
- [laravel-dtos](../laravel-dtos/SKILL.md): the transformer that builds the DTO
- [laravel-exceptions](../laravel-exceptions/SKILL.md): how a failed rule becomes an HTTP error
- [laravel-query-builders](../laravel-query-builders/SKILL.md): the builder a query class starts from
- [laravel-testing](../laravel-testing/SKILL.md): feature tests per endpoint, `can …`/`cannot …`
