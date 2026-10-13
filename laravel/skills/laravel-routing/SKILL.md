---
name: laravel-routing
description: Each surface has its own route file registered in bootstrap/app.php; every endpoint is an explicit named route that binds by public id and authorizes with ->can(). Use when adding or changing routes, route files, API versions, route names or verbs, binding or authorization.
---

# Laravel Routing

A surface is one consumer of the app: its own frontend, or a versioned public API. Each surface has one route file, registered in `bootstrap/app.php` with its middleware, prefix and name, and its controllers live under its own namespace. The route file is then the complete map of that surface: every endpoint written out with its verb, its public URL, its name and the ability a caller needs.

## Rules

- **One route file per surface.** `routes/web.php` for the app's own frontend, `routes/api_v1.php` for version 1 of an API. Controllers are namespaced to match: `App\Http\Api\V1\Controllers`. A breaking API change is a new surface beside the old one ([laravel-architecture](../laravel-architecture/SKILL.md)).
- **Surfaces are registered in the `withRouting()` callback.** One chain per surface, `Route::middleware()->domain()->prefix()->name()->group(base_path(...))` (domain only when the surface has one), with a short comment above it saying who the surface serves. The surface's prefix, name prefix and middleware sit there and nowhere else; the route file never repeats them.
- **The callback replaces the named arguments.** With a `using` callback, Laravel ignores `web:`, `api:` and `health:`: it loads no route file and registers no `/up` route on its own. Register everything inside the callback.
- **A surface's own middleware is a named group in the MiddlewareBooter** ([laravel-providers](../laravel-providers/SKILL.md)), stacked after the stock group: `Route::middleware(['api', 'api_v1'])`. A group used instead of `api` or `web` must list `SubstituteBindings`, or no route on the surface binds its models.
- **Authentication sits in the route file.** `Route::middleware('auth:sanctum')->group(...)` wraps the routes that need it, so a public login or token route can live in the same file.
- **One explicit route per endpoint, never `Route::resource()`.** Each endpoint is a `Route::{verb}()` with its own `->can()` and `->name()`, so its verb, path, ability and name read together and a search finds it. The routes for one resource sit in `Route::prefix('orders')->group(...)`, nested resources included.
- **Names are `{resource}.{action}`, singular kebab-case.** `order.show`, `order.cancel`, `order.resend-confirmation`. A nested resource is `{parent}-{child}.{action}`: `order-note.store`. Write each route's full name; the surface's prefix comes from its group, so `order.show` on the API is `api-v1.order.show`. URLs stay plural: `/orders/{order:ulid}`.
- **Bind by the public column.** `{order:ulid}`, never the auto-increment id; [laravel-models](../laravel-models/SKILL.md) owns which column a model has. A child bound by its column is scoped to its parent: `/orders/{order:ulid}/notes/{note:ulid}` resolves through `$order->notes()`, so another order's note is a 404.
- **Authorization lives on the route.** Every route on a surface with a permission model carries `->can()`. Never `authorize()` in a Form Request, and never `$this->authorize()` or `Gate::authorize()` in a controller. `->can()` is route middleware, so it runs before the controller and its Form Request are resolved: an unauthorized caller gets a 403, not a 422 listing what's wrong with their input, and no validation query runs. Bindings resolve first (`SubstituteBindings` outranks `Authorize`), so a missing record is still a 404.

## Verbs

| Endpoint | Verb | Route |
|---|---|---|
| List, show | `GET` | `[OrderController::class, 'index']`, `'show'` |
| Create a record | `POST` | `[OrderController::class, 'store']` |
| Update a record | `PUT` | `[OrderController::class, 'update']` |
| Delete a record | `DELETE` | `[OrderController::class, 'destroy']` |
| Move an existing record to another state | `PATCH /{order:ulid}/cancel` | invokable `CancelOrderController` |
| Trigger a side effect | `POST /{order:ulid}/resend-confirmation` | invokable `ResendOrderConfirmationController` |

Which work gets a resource method and which an invokable is [laravel-controllers](../laravel-controllers/SKILL.md)' call.

## Authorization

| Route | `->can()` | The policy method receives |
|---|---|---|
| `index`, `store` | `->can('viewAny', Order::class)`, `->can('create', Order::class)` | the user |
| An instance route | `->can('update', 'order')`: the string names the route parameter | the user and the order |
| A nested list or create | `->can('create', [OrderNote::class, 'order'])` | the user and the parent order, on `OrderNotePolicy` |
| An invokable | `->can('cancel', 'order')`: the ability is named after the controller's verb | the user and the order |

What a policy method checks is [laravel-policies](../laravel-policies/SKILL.md)' concern.

## Example

```php
<?php

declare(strict_types=1);

use App\Booters\ExceptionBooter;
use App\Booters\MiddlewareBooter;
use App\Booters\ScheduleBooter;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(function (): void {
        // Partners' systems placing and tracking orders. Versioned: consumers upgrade on their own schedule.
        Route::middleware(['api', 'api_v1'])
            ->domain(config('app.api_domain'))
            ->prefix('api/v1')
            ->name('api-v1.')
            ->group(base_path('routes/api_v1.php'));

        // The app's own frontend, for customers and staff.
        Route::middleware('web')
            ->group(base_path('routes/web.php'));
    })
    ->withMiddleware(new MiddlewareBooter)
    ->withSchedule(new ScheduleBooter)
    ->withExceptions(new ExceptionBooter)
    ->create();
```

`routes/api_v1.php`:

```php
<?php

declare(strict_types=1);

use App\Http\Api\V1\Controllers\CancelOrderController;
use App\Http\Api\V1\Controllers\OrderController;
use App\Http\Api\V1\Controllers\OrderNoteController;
use App\Http\Api\V1\Controllers\ResendOrderConfirmationController;
use App\Models\Order;
use App\Models\OrderNote;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::prefix('orders')->group(function (): void {
        Route::get('/', [OrderController::class, 'index'])
            ->can('viewAny', Order::class)
            ->name('order.index');
        Route::post('/', [OrderController::class, 'store'])
            ->can('create', Order::class)
            ->name('order.store');
        Route::get('/{order:ulid}', [OrderController::class, 'show'])
            ->can('view', 'order')
            ->name('order.show');
        Route::put('/{order:ulid}', [OrderController::class, 'update'])
            ->can('update', 'order')
            ->name('order.update');
        Route::delete('/{order:ulid}', [OrderController::class, 'destroy'])
            ->can('delete', 'order')
            ->name('order.destroy');
        Route::patch('/{order:ulid}/cancel', CancelOrderController::class)
            ->can('cancel', 'order')
            ->name('order.cancel');
        Route::post('/{order:ulid}/resend-confirmation', ResendOrderConfirmationController::class)
            ->can('resendConfirmation', 'order')
            ->name('order.resend-confirmation');

        Route::get('/{order:ulid}/notes', [OrderNoteController::class, 'index'])
            ->can('viewAny', [OrderNote::class, 'order'])
            ->name('order-note.index');
        Route::post('/{order:ulid}/notes', [OrderNoteController::class, 'store'])
            ->can('create', [OrderNote::class, 'order'])
            ->name('order-note.store');
    });
});
```

## Reach for these when the situation calls for it

**Every lookup of a model through a route needs eager loading or decoding.** Override `resolveRouteBinding()` on the model. Build on `resolveRouteBindingQuery()` so the public-id trait still turns a malformed id into a 404 before querying:

```php
#[UseEloquentBuilder(OrderBuilder::class)]
class Order extends Model
{
    use HasUlid;

    public function resolveRouteBinding(mixed $value, mixed $field = null): ?self
    {
        return $this->resolveRouteBindingQuery(self::query(), $value, $field)
            ->withRelations()
            ->first();
    }
}
```

**The same parameter needs a different lookup on some routes.** For example, the API's routes need the order loaded with its relations before their policies and resources read them, while the frontend's bind it plainly. First check the route's own options: `->withTrashed()` resolves soft-deleted records, and a binding column already scopes a child to its parent. When they don't cover it, register a `ConditionalRouteBinder` in an `AppServiceProvider::bootRouteBindings()` method. It matches the current route's name against patterns; on any route it doesn't match, Laravel's implicit binding resolves the parameter as usual. Copy [references/ConditionalRouteBinder.php](references/ConditionalRouteBinder.php) when you need it.

```php
ConditionalRouteBinder::bind('order')
    ->forRoute('api-v1.*', fn (string $value): Order => Order::query()
        ->where('ulid', $value)
        ->withRelations()
        ->firstOrFail());
```

A matched resolver replaces implicit binding entirely: it looks up by the column itself and fails with `firstOrFail()`, and the route's `->withTrashed()` and parent scoping don't apply to it.

## Anti-patterns

- ❌ `Route::resource()` or `Route::apiResource()`.
- ❌ A plural or snake_case route name: `orders.show`, `order_note.index`.
- ❌ `POST /orders/{order:ulid}/cancel`. A state change on an existing record is a `PATCH`.
- ❌ `authorize()` in a Form Request, or `$this->authorize()` in a controller.
- ❌ `{order}` binding the auto-increment id.
- ❌ A version prefix, name prefix or surface middleware inside the route file, or a route file nested as `routes/api/v1.php`.
- ❌ A custom surface middleware group without `SubstituteBindings` and not stacked on `api` or `web`.
- ❌ A `Route::bind()` closure that repeats what `{order:ulid}` already does.

## Related

- [laravel-architecture](../laravel-architecture/SKILL.md): surfaces and where their classes live
- [laravel-providers](../laravel-providers/SKILL.md): `bootstrap/app.php`, the MiddlewareBooter and `AppServiceProvider`
- [laravel-models](../laravel-models/SKILL.md): public ids and the `HasUlid`/`HasUuid` concerns
- [laravel-query-builders](../laravel-query-builders/SKILL.md): `withRelations()`
- [laravel-controllers](../laravel-controllers/SKILL.md): resource methods and invokables
- [laravel-policies](../laravel-policies/SKILL.md): what each ability checks
- [laravel-validation](../laravel-validation/SKILL.md): Form Requests, which validate and never authorize
