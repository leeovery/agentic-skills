# Multi-Tenancy Testing

Testing patterns for multi-tenant applications using Stancl Tenancy package.

**Related guides:**
- [Multi-tenancy](../SKILL.md) - Core multi-tenancy patterns
- [Testing](../../laravel-testing/SKILL.md) - General testing patterns

## Testing Central Actions

```php
use App\Actions\Central\Tenant\CreateTenantAction;
use App\Data\Central\CreateTenantData;

it('creates a tenant with database', function () {
    $data = CreateTenantData::from([
        'tenantId' => 'tenant-1',
        'name' => 'Acme Corp',
        'domain' => 'acme.myapp.com',
    ]);

    $tenant = resolve(CreateTenantAction::class)($data);

    expect($tenant)
        ->toBeInstanceOf(Tenant::class)
        ->id->toBe('tenant-1')
        ->name->toBe('Acme Corp');

    // Verify database exists
    expect($tenant->database()->exists())->toBeTrue();
});
```

## Testing Tenanted Actions

```php
use App\Actions\Tenanted\Order\CreateOrderAction;
use App\Support\TenantContext;

it('creates order in tenant context', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant, function () {
        $user = User::factory()->create();
        $data = CreateOrderData::factory()->make();

        $order = resolve(CreateOrderAction::class)($user, $data);

        expect($order)
            ->toBeInstanceOf(Order::class)
            ->user_id->toBe($user->id);

        assertDatabaseHas('orders', [
            'id' => $order->id,
            'user_id' => $user->id,
        ]);
    });
});
```

## Testing Traits

### ManagesTenants Trait

**Location:** `tests/Concerns/ManagesTenants.php`

Provides tenant management functionality for tests:
- Creating and tracking test tenants
- Cleaning up tenant databases after tests
- Parallel testing support
- Tenant initialization helpers

**[View full implementation →](ManagesTenants.php)**

### RefreshDatabaseWithTenant Trait

**Location:** `tests/Concerns/RefreshDatabaseWithTenant.php`

Extends Laravel's RefreshDatabase trait to handle both central and tenant databases in transactions.

**[View full implementation →](RefreshDatabaseWithTenant.php)**

### TenantTestCase

**Location:** `tests/TenantTestCase.php`

Base test case for all tenant-scoped tests.

**[View full implementation →](TenantTestCase.php)**

### Enhanced TestCase

**Location:** `tests/TestCase.php`

Base test case with multi-tenancy support.

**[View full implementation →](TenancyTestCase.php)**

### Pest Configuration

**Location:** `tests/Pest.php`

Configure Pest to automatically use the correct test case based on directory structure.

**[View full implementation →](TenancyPest.php)**

## Test Directory Structure

```
tests/
├── Concerns/
│   ├── ManagesTenants.php
│   └── RefreshDatabaseWithTenant.php
├── Feature/
│   ├── Central/              # Tests extending TestCase
│   │   └── TenantManagementTest.php
│   └── Tenanted/             # Tests extending TenantTestCase
│       ├── OrderTest.php
│       └── CustomerTest.php
├── Unit/
│   ├── Central/
│   │   └── CreateTenantActionTest.php
│   └── Tenanted/
│       └── CreateOrderActionTest.php
├── Pest.php
├── TestCase.php
└── TenantTestCase.php
```

## Using Test Helpers

**Central tests** (no tenant context needed):

```php
// tests/Feature/Central/TenantManagementTest.php

it('creates a tenant', function () {
    $tenant = create_tenant('tenant-1', ['name' => 'Acme Corp']);

    expect($tenant)
        ->toBeInstanceOf(Tenant::class)
        ->name->toBe('Acme Corp');
});
```

**Tenanted tests** (automatic tenant context):

```php
// tests/Feature/Tenanted/OrderTest.php

it('creates an order', function () {
    // Tenant is automatically initialized via TenantTestCase
    $user = User::factory()->create();

    $response = actingAs($user)
        ->postJson('/orders', [
            'items' => [
                ['product_id' => 1, 'quantity' => 2],
            ],
        ]);

    $response->assertCreated();

    // Database assertions are scoped to tenant database
    assertDatabaseHas('orders', [
        'user_id' => $user->id,
    ]);
});
```

## Key Benefits

1. **Automatic tenant database management** - Databases created and cleaned up automatically
2. **Parallel testing support** - Each test suite gets unique tenant database suffix
3. **Directory-based test organization** - Central vs Tenanted tests auto-detected
4. **Transaction handling** - Both central and tenant databases wrapped in transactions
5. **HTTP header injection** - Tenant ID automatically added to API requests
