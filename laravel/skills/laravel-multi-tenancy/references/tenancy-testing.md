# Testing a multi-tenant app

The harness gives every tenanted test an initialised tenant without the test mentioning one, and provisions a tenant database only when the migrations change. It keeps [laravel-testing](../../laravel-testing/SKILL.md)'s setup, one `TestCase` and `FastRefreshDatabase`, with one substitution.

- **One testing tenant, kept between tests and runs.** The first tenanted test creates it, with the key `testing`, through the real `TenantCreated` pipeline, so its database is created, migrated and seeded exactly as in production. It's created before the test's transactions open, so its row commits and every later tenanted test reuses it.
- **Two transactions per tenanted test.** Tenancy is initialised first, then one transaction opens on the central connection and one on the tenant connection, under one transactions manager, so after-commit callbacks behave as in any other test. Both roll back after the test.
- **Extra tenants are cleaned up.** A tenant a test creates is deleted after it, dropping its database; its row would roll back anyway, but the database was created outside the transaction.
- **The testing tenant is rebuilt if its database goes.** Each tenanted test checks the database exists before reusing the row; if it was dropped by hand or by a run cut short, the stale row is removed and the tenant provisioned again. A test never deletes the testing tenant itself: the row's deletion would roll back, and the drop can't proceed while the test's own tenant connection holds that database open. A test about deleting a tenant creates one with `createTenant()` in a central test.
- **Parallel-safe.** Tenant databases get a `_test` suffix, or `_test_{token}` per parallel process.

## Files

| Copy to | From |
|---|---|
| `tests/Pest.php` | [Pest.php](Pest.php) |
| `tests/TestCase.php` | [TestCase.php](TestCase.php) |
| `tests/Concerns/ManagesTenants.php` | [ManagesTenants.php](ManagesTenants.php) |
| `tests/Concerns/FastRefreshDatabaseWithTenancy.php` | [FastRefreshDatabaseWithTenancy.php](FastRefreshDatabaseWithTenancy.php) |
| `tests/Concerns/RunsInTenant.php` | [RunsInTenant.php](RunsInTenant.php) |

`Pest.php` and `TestCase.php` are laravel-testing's files with the tenancy lines added; merge them into the project's own rather than replacing anything else they do.

## Where it departs from laravel-testing, and why

`Pest.php` applies `FastRefreshDatabaseWithTenancy` where laravel-testing applies `FastRefreshDatabase`, and adds one line giving the `Tenanted/` folders the `RunsInTenant` marker:

```php
uses(TestCase::class, FastRefreshDatabaseWithTenancy::class)->in('Feature', 'Unit', 'Architecture');
uses(RunsInTenant::class)->in('Feature/Tenanted', 'Unit/Tenanted');
```

- **The testing tenant must exist before the central transaction opens**, or its row rolls back after every test while its database stays. The only hook at that moment is `beginDatabaseTransaction()`, which `FastRefreshDatabase` calls once its migrations are in place. `FastRefreshDatabaseWithTenancy` uses `FastRefreshDatabase` and overrides that method; a trait's own method beats the ones it composes, so the override always runs.
- **Never apply `FastRefreshDatabase` beside it.** Two traits defining the same methods on one test class is a fatal error. Moving the tenancy trait onto a parent test case doesn't help either: the `FastRefreshDatabase` applied to the generated test class overrides the parent's `beginDatabaseTransaction()`, so tenancy is never initialised and tenanted tests write to the central database.
- **The marker is a trait, not a second test case.** Pest throws when a directory and a directory inside it are given different test case classes; traits added to a nested directory are merged instead. `RunsInTenant` overrides `runsInTenant()`, which `ManagesTenants` defines as `false` on `TestCase`.

## What the app needs

- **`tenancy.database.drop_tenant_databases_on_migrate_fresh` is `true`.** `FastRefreshDatabase` runs `migrate:fresh` only when a file under `database/migrations` changes, tenant migrations included, or the git branch does. With this set, stancl deletes the testing tenant first, dropping its database, and the next tenanted test provisions a fresh one. Without it, the row goes and the database stays, and recreating `testing` fails because its database already exists.
- **`Tenanted/` sits directly under `tests/Feature` or `tests/Unit`.** That's where the `RunsInTenant` line looks.
- **`TestCase::json()` matches the identification method.** The reference adds the tenant key under the configured header, for header identification. With domain identification, build request URLs on the tenant's domain instead; with path identification, put the tenant key in the path.

## Folders

```
tests/
├── Feature/
│   ├── Tenanted/          # RunsInTenant
│   │   ├── Actions/Order/ExpireStaleOrdersActionTest.php
│   │   └── Api/V1/OrderTest.php
│   └── Central/
│       └── Actions/Order/ExpireStaleOrdersForAllTenantsActionTest.php
└── Unit/
```

Most tests are tenanted, because most code is tenant code. A test goes outside `Tenanted/` only when the code under test runs central.

## A tenanted test

```php
<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\User;

use function Pest\Laravel\actingAs;

test('can list orders', function (): void {
    $user = User::factory()->create();
    Order::factory()->count(2)->create();

    actingAs($user)
        ->getJson('/api/v1/orders')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});
```

Nothing in it names the tenant: the user and orders are created in the testing tenant's database, and `getJson()` sends the tenant header through the `json()` override. Sign in the way the project's tests already do.

A tenanted test stays in its tenant. `tenancy()->end()`, `tenancy()->central()` or another tenant's `run()` purges the tenant connection, and its transaction with it, so anything written afterwards commits to the testing tenant's database. Code that moves between contexts is tested as central code.

## A central test that creates tenants

```php
<?php

declare(strict_types=1);

use App\Actions\Central\Order\ExpireStaleOrdersForAllTenantsAction;
use App\Jobs\ExpireStaleOrdersJob;
use App\Models\Tenant;
use Illuminate\Support\Facades\Queue;

test('will dispatch one job per tenant', function (): void {
    $this->createTenant();
    $this->createTenant();
    Queue::fake();

    resolve(ExpireStaleOrdersForAllTenantsAction::class)();

    Queue::assertPushed(ExpireStaleOrdersJob::class, Tenant::query()->count());
});
```

The expected count is read from the database, never written as a number: the testing tenant's row is committed, so a central test sees it whenever a tenanted test has run before it. `createTenant()` provisions a real tenant database, so create only as many as the test needs. Fake the queue after creating them: if the project queues its provisioning pipeline, faking first would stop the databases being created. Under `Queue::fake()` stancl adds no tenant key to the payload, so the test asserts the count, not which tenant each job belongs to.
