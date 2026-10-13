# Testing a multi-tenant app

The harness gives every tenanted test an initialised tenant without the test mentioning one, and keeps the cost of a tenant database to once per run.

- **One testing tenant per run.** The first tenanted test creates it through the real `TenantCreated` pipeline, so its database is created, migrated and seeded exactly as in production. Every later tenanted test reuses it.
- **Two transactions per test.** `RefreshDatabaseWithTenant` opens one on the central connection, initialises the testing tenant, then opens one on the tenant connection. Both roll back after the test.
- **Extra tenants are cleaned up.** A tenant a test creates is deleted after it, which drops its database. If a test deletes the testing tenant, the next test creates a fresh one.
- **Parallel-safe.** Tenant databases get a `_test` suffix, or `_test_{token}` per parallel process.

## Files

| Copy to | From |
|---|---|
| `tests/TestCase.php` | [TestCase.php](TestCase.php): central tests; tears down tenancy and extra tenants |
| `tests/TenantTestCase.php` | [TenantTestCase.php](TenantTestCase.php): tenanted tests |
| `tests/Concerns/ManagesTenants.php` | [ManagesTenants.php](ManagesTenants.php) |
| `tests/Concerns/RefreshDatabaseWithTenant.php` | [RefreshDatabaseWithTenant.php](RefreshDatabaseWithTenant.php) |
| `tests/Pest.php` | [Pest.php](Pest.php): merge into the project's own; it assigns the test case by folder |

Merge `TestCase.php` into the project's base test case rather than replacing it: keep whatever else that class already does.

## What the app needs

- **`tenancy.database.drop_tenant_databases_on_migrate_fresh` is `true`.** `RefreshDatabase` runs `migrate:fresh` once per run; with this set, stancl deletes the previous run's tenants first, dropping their databases. Without it, every run leaves its tenant databases behind.
- **The tenant factory leaves `id` to `models.id_generator`.** A fixed id collides as soon as a test creates a second tenant.
- **`TestCase::json()` matches the identification method.** The reference adds the tenant key under the configured header, for header identification. With domain identification, build request URLs on the tenant's domain instead; with path identification, put the tenant key in the path.

## Folders

```
tests/
├── Feature/
│   ├── Tenanted/          # TenantTestCase
│   │   ├── Actions/Order/ExpireStaleOrdersActionTest.php
│   │   └── Api/V1/OrderTest.php
│   └── Central/           # TestCase (as is everything outside Tenanted/)
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
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;

test('can list orders', function (): void {
    Sanctum::actingAs(User::factory()->create());
    Order::factory()->count(2)->create();

    getJson('/api/v1/orders')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});
```

Nothing in it names the tenant: the user and orders are created in the testing tenant's database, and `getJson()` sends the tenant header through the `json()` override. Log in with the project's own helper where it has one; `Sanctum::actingAs()` stands in for it here.

## A central test that creates tenants

```php
<?php

declare(strict_types=1);

use App\Actions\Central\Order\ExpireStaleOrdersForAllTenantsAction;
use App\Jobs\ExpireStaleOrdersJob;
use Illuminate\Support\Facades\Queue;

test('will dispatch one job per tenant', function (): void {
    $this->createTenant();
    $this->createTenant();
    Queue::fake();

    resolve(ExpireStaleOrdersForAllTenantsAction::class)();

    Queue::assertPushed(ExpireStaleOrdersJob::class, 2);
});
```

`createTenant()` provisions a real tenant database, so create only as many as the test needs. Fake the queue after creating them: if the project queues its provisioning pipeline, faking first would stop the databases being created. Under `Queue::fake()` stancl adds no tenant key to the payload, so the test asserts the count, not which tenant each job belongs to.
