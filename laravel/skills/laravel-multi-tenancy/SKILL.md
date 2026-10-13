---
name: laravel-multi-tenancy
description: Tenancy on stancl/tenancy v4. Middleware identifies the tenant, bootstrappers carry it into jobs, cache and storage, and central code is marked. Use only in a multi-tenant app, when writing tenant identification, central or tenant models, provisioning, or tenant tests.
---

# Laravel Multi-Tenancy

In a multi-tenant app every line of code runs in one of two contexts: central (the app itself: tenants, plans, sign-up) or one tenant's. stancl/tenancy switches between them. Identification middleware finds the tenant on each request and initialises tenancy; bootstrappers then point the database, cache, filesystem and queue at that tenant, and revert them when tenancy ends. Tenant code is then ordinary Laravel code that never mentions the tenant. Central code is the marked exception, and the only code that moves between contexts.

This skill targets stancl/tenancy v4 (PHP 8.4, Laravel 12). v3 differs in namespaces and in how identification is configured; when `composer.lock` shows v3, check each API against its vendor source.

## Choices the project makes once

| Choice | Options | Consequence |
|---|---|---|
| Where tenant data lives | A database per tenant, or one shared database | Decides how tenant models look ([Rules](#rules), [one shared database](#when-tenants-share-one-database)) |
| How a request names its tenant | Domain or subdomain, a path segment, or a header | Decides the middleware and what the tenant model needs ([Identification](#identification)) |

Follow the choice the project has made. Don't mix modes or identification methods without a reason the project states.

## Rules

- **Tenant code uses the normal layout; central code is marked.** Central actions, DTOs and HTTP sit under a `Central` level (`Actions/Central/Tenant/CreateTenantAction`, `Data/Central/`, `Http/Central/`, `routes/central.php`). Code without the marker may assume a tenant is initialised; code with it must not touch tenant tables.
- **The tenant model extends stancl's and adds only what the mode needs.** For a database per tenant: `implements TenantWithDatabase` and `use HasDatabase`. Add `HasDomains` only when tenants are identified by domain or subdomain; `$tenant->domains()` doesn't exist without it.
- **Columns you query on `tenants` are listed in `getCustomColumns()`.** Any other attribute is stored in the `data` JSON column, so `where('name', …)` against it finds nothing.
- **The tenant's key is public.** It travels in the header, path or database name, so `models.id_generator` is `ULIDGenerator` or `UUIDGenerator` to match the project's public ids, never an incrementing int. `tenants` is the one table whose primary key is stancl's string id rather than the auto-increment `id` that [laravel-models](../laravel-models/SKILL.md) keeps.
- **Every central model except `Tenant` uses `CentralConnection`** (database per tenant). Once tenancy is initialised the default connection is the tenant's, so a central model without it queries the wrong database. `Tenant` gets it from stancl's base model.
- **Tenant models are plain models** (database per tenant). No `tenant_id` column, no scoping trait, no `where('tenant_id', …)`: the connection is the scope.
- **Relationships point from tenant to central, never back.** A tenant model may `belongsTo` a central one, because the related query runs on the central model's connection. A central model can't reach tenant tables. Neither direction survives `whereHas()`, `withCount()` or a join, which run as one statement on one database.
- **Rules that check a central table name the model class.** `Rule::exists(Plan::class, 'ulid')` resolves the model's central connection; `'exists:plans,ulid'` looks in the tenant's database.
- **Creating a tenant provisions it.** `Tenant::query()->create()` fires `TenantCreated`, and the pipeline in the app's `TenancyServiceProvider` creates, migrates and seeds its database. Never do any of that by hand. Reference data every tenant needs (roles, defaults) goes in the tenant seeder named in `seeder_parameters`, written so it can run again; add `SeedDatabase` to the pipeline, where the published stub leaves it commented out.
- **No `DB::transaction` around tenant creation.** The database is created on a separate host connection, so rolling back the central transaction leaves an orphaned database behind.
- **Migrations are split.** Central ones in `database/migrations/` run with `php artisan migrate`; tenant ones in `database/migrations/tenant/` (`migration_parameters.--path`) run with `php artisan tenants:migrate`. Deploys run both.
- **Identification runs before anything that reads the database.** The published `TenancyServiceProvider` calls `makeTenancyMiddlewareHighestPriority()` so the identification middleware sorts ahead of authentication and route binding; keep it.
- **Identification isn't authorization.** Anyone can send any tenant's header or visit any subdomain. With users in each tenant's database, authentication is already scoped: a user or token from one tenant doesn't exist in another. When users are central and belong to several tenants, every tenant route checks membership.
- **Jobs need nothing tenancy-specific.** Dispatched in a tenant's context, a job carries that tenant: `QueueTenancyBootstrapper` stamps the tenant key into the payload and initialises tenancy on the worker before the job is unserialized. Never pass the tenant to the constructor or add a tenancy trait. Dispatched in central context, a job runs central.
- **Cache and files are scoped by config.** `CacheTenancyBootstrapper` prefixes keys on each store in `tenancy.cache.stores`, and `FilesystemTenancyBootstrapper` suffixes the root of each disk in `tenancy.filesystem.disks`. List every store and disk tenant code uses, `s3` included (the stub leaves it commented out). A value shared by every tenant goes through `GlobalCache`.
- **The scheduler runs central.** Scheduled work over tenant data fans out: the scheduler calls a central action that dispatches one job per tenant from inside that tenant's context, as in the example. A tenant query in a scheduled action without the fan-out reads the central database.
- **Use stancl's API directly:** `tenant()` for the current tenant, `$tenant->run(fn)` to run code as a tenant, `tenancy()->central(fn)` to step out. Each restores the previous context. When the project wraps these in its own facade, use the wrapper.

## Example

The scheduler's fan-out, central code that runs tenant work:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Central\Order;

use App\Jobs\ExpireStaleOrdersJob;
use App\Models\Tenant;

class ExpireStaleOrdersForAllTenantsAction
{
    public function __invoke(): void
    {
        Tenant::query()
            ->lazyById()
            ->each(function (Tenant $tenant): void {
                $tenant->run(function (): void {
                    ExpireStaleOrdersJob::dispatch();
                });
            });
    }
}
```

The job is an ordinary job wrapping the tenant-side `ExpireStaleOrdersAction` ([laravel-jobs](../laravel-jobs/SKILL.md)); it runs in the tenant it was dispatched in, and one tenant's failure can't stop the others.

The tenant model, for a database per tenant:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

#[UseFactory(TenantFactory::class)]
class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase, HasFactory;

    public static function getCustomColumns(): array
    {
        return ['id', 'name'];
    }
}
```

## Identification

| When the project identifies tenants by | Middleware | Also |
|---|---|---|
| Domain or subdomain | `InitializeTenancyByDomain`, `…BySubdomain` or `…ByDomainOrSubdomain` | The tenant model uses `HasDomains`; the action that creates a tenant creates its domain with `$tenant->domains()->create(['domain' => …])`; `identification.central_domains` lists the app's own hosts |
| A path segment | `InitializeTenancyByPath` | Tenant routes are prefixed with `{tenant}` |
| A header (an API) | `InitializeTenancyByRequestData` | The header name is config (`identification.resolvers.RequestDataTenantResolver.header`, default `X-Tenant`), and the value is the tenant key; never subclass the middleware to change it |

Use the package middleware directly and set `identification.default_middleware` to it. A tenant surface's middleware group in the MiddlewareBooter lists it (`$middleware->group('api_v1', [InitializeTenancyByRequestData::class])`), stacked after `api` or `web` as [laravel-routing](../laravel-routing/SKILL.md) describes. Central surfaces need nothing: a route without identification middleware is central, because `default_route_mode` is `RouteMode::CENTRAL`.

## When tenants share one database

Leave `DatabaseTenancyBootstrapper` out of `tenancy.bootstrappers` and `HasDatabase` off the tenant model. Every tenant table has an indexed `tenant_id`, part of every unique index, and its model uses `BelongsToTenant`, which fills `tenant_id` on create and scopes queries to the current tenant. A model whose parent carries the tenant uses `BelongsToPrimaryModel` instead. The scope applies only while tenancy is initialised: central code sees every tenant's rows, so a central query over tenant tables filters deliberately. Unique and exists rules on tenant tables add `->where('tenant_id', tenant()->getTenantKey())`.

## Reach for these when the situation calls for it

**A package keeps state that must differ per tenant** (a cache key, a config value, a singleton's setting). Write a bootstrapper in `app/Bootstrappers/`, list it in `tenancy.bootstrappers`, and undo in `revert()` exactly what `bootstrap()` did:

```php
use Spatie\Permission\PermissionRegistrar;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

class PermissionCacheBootstrapper implements TenancyBootstrapper
{
    private readonly string $baseKey;

    public function __construct(private readonly PermissionRegistrar $registrar)
    {
        $this->baseKey = config('permission.cache.key');
    }

    public function bootstrap(Tenant $tenant): void
    {
        $this->registrar->cacheKey = "{$this->baseKey}.tenant.{$tenant->getTenantKey()}";
    }

    public function revert(): void
    {
        $this->registrar->cacheKey = $this->baseKey;
    }
}
```

**A job must always run central, wherever it's dispatched.** Give it a queue connection with `'central' => true` in `config/queue.php`; `QueueTenancyBootstrapper` leaves its payload untouched.

**An artisan command does tenant work.** Run an existing command per tenant with `php artisan tenants:run {command}`, or give a new one `HasTenantOptions` and `TenantAwareCommand` (both `Stancl\Tenancy\Concerns`) so it runs once per tenant and takes `--tenants=`.

**Tenants share a host and the app uses sessions** (path or header identification on the web). Add `ScopeSessions` after the identification middleware, so a session started in one tenant gets a 403 in another.

**A route serves both contexts** (a shared login or asset route). Add the `universal` middleware flag alongside the identification middleware.

**Users exist centrally and in several tenants.** stancl's resource syncing: the central model implements `SyncMaster`, the tenant model `Syncable`, both use `ResourceSyncing`, and the synced attributes are listed on each.

## Testing

Tests that run in a tenant live in a `Tenanted/` folder (`tests/Feature/Tenanted/Api/V1/OrderTest.php`) and run in one testing tenant, kept between runs, with both databases wrapped in a transaction per test. A test there reads like any other: it never creates or mentions the tenant, and HTTP calls carry the tenant automatically. Every other test runs central. The project keeps laravel-testing's one `TestCase`, but swaps `FastRefreshDatabase` for a tenancy-aware wrapper of it. Read [references/tenancy-testing.md](references/tenancy-testing.md) when setting up or changing that harness, or writing a central test that creates tenants. Test style is [laravel-testing](../laravel-testing/SKILL.md)'s.

## Anti-patterns

- ❌ A tenant parameter or tenancy trait on a job.
- ❌ `DB::transaction` around `Tenant::query()->create()`, or creating, migrating or seeding a tenant database by hand.
- ❌ `$tenant->domains()` on a tenant model without `HasDomains`.
- ❌ A central model without `CentralConnection`, or a central model with a relationship to a tenant model.
- ❌ `tenant_id` columns or `where('tenant_id', …)` in a database-per-tenant app.
- ❌ A scheduled action that queries tenant tables without fanning out per tenant.
- ❌ A subclass of an identification middleware that only changes its header or domain.
- ❌ `'exists:plans,ulid'` for a central table in a tenant request.
- ❌ Querying a tenant attribute that isn't in `getCustomColumns()`.

## Related

- [laravel-actions](../laravel-actions/SKILL.md): actions, the sweep-and-dispatch pattern the fan-out follows
- [laravel-jobs](../laravel-jobs/SKILL.md): the jobs the fan-out dispatches
- [laravel-routing](../laravel-routing/SKILL.md): surfaces, route files and their middleware groups
- [laravel-providers](../laravel-providers/SKILL.md): the MiddlewareBooter and ScheduleBooter
- [laravel-models](../laravel-models/SKILL.md): model structure and public ids
- [laravel-validation](../laravel-validation/SKILL.md): Form Requests and their rules
- [laravel-architecture](../laravel-architecture/SKILL.md): the layout the `Central` marker sits in
- [laravel-testing](../laravel-testing/SKILL.md): test style
