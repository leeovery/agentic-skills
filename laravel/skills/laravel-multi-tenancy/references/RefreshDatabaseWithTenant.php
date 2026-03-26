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
        // This is called at the start of every test case...

        // If we have a Tenant statically available, we'll check if it exists in the database
        // and create it if it doesn't. This is useful when running tests in parallel and
        // ensuring the Tenant exists between tests (in case of inadvertent deletion).
        if (filled(static::$testingTenant) && ! Tenant::find(static::$testingTenant)) {

            // Create quietly to avoid triggering the pipeline, which would create and migrate
            // the tenant database. We don't want that here, we just want to create the tenant.
            Tenant::createQuietly(static::$testingTenant->getAttributes());

        }

        // Create (if not already created) and set the testing tenant.
        $this->getTestingTenant();

        // Start a transaction for the central database.
        $this->parentBeginDatabaseTransaction();

        $this->initializeTenant();

        // Start a transaction for the tenant database.
        $this->parentBeginDatabaseTransaction();
    }
}
