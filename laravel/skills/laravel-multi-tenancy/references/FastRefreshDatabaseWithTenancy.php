<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\Tenant;
use Plannr\Laravel\FastRefreshDatabase\Traits\FastRefreshDatabase;

trait FastRefreshDatabaseWithTenancy
{
    use FastRefreshDatabase {
        FastRefreshDatabase::beginDatabaseTransaction as beginTransactionsOnConnections;
    }

    public function beginDatabaseTransaction(): void
    {
        // Before any transaction opens, so the testing tenant's row commits and outlives the test.
        if ($this->runsInTenant()) {
            tenancy()->initialize($this->testingTenant());
        }

        $this->beginTransactionsOnConnections();
    }

    // Read again at teardown: a test that ended tenancy has already lost the tenant connection.
    protected function connectionsToTransact(): array
    {
        $central = config('tenancy.database.central_connection');

        return tenancy()->initialized ? [$central, 'tenant'] : [$central];
    }

    private function testingTenant(): Tenant
    {
        return Tenant::query()->find('testing') ?? $this->createTenant(['id' => 'testing']);
    }
}
