<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

trait RefreshDatabaseWithTenant
{
    use ManagesTenants, RefreshDatabase {
        RefreshDatabase::beginDatabaseTransaction as parentBeginDatabaseTransaction;
    }

    public function beginDatabaseTransaction(): void
    {
        $this->restoreTestingTenantRecord();

        $this->getTestingTenant();

        // Central connection first; once tenancy is initialised the default connection is the tenant's.
        $this->parentBeginDatabaseTransaction();
        $this->initializeTenant();
        $this->parentBeginDatabaseTransaction();
    }

    private function restoreTestingTenantRecord(): void
    {
        $testingTenant = static::$testingTenant;

        if (! $testingTenant || Tenant::query()->whereKey($testingTenant->getTenantKey())->exists()) {
            return;
        }

        // Quietly, so the provisioning pipeline doesn't try to create the database that already exists.
        Tenant::query()->createQuietly($testingTenant->getAttributes());
    }
}
