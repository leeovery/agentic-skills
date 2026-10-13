<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\Tenant;
use Illuminate\Support\Facades\ParallelTesting;

trait ManagesTenants
{
    /** @var list<Tenant> */
    private array $tenantsCreatedInTest = [];

    public function createTenant(array $attributes = []): Tenant
    {
        $token = ParallelTesting::token();

        config(['tenancy.database.suffix' => $token ? "_test_{$token}" : '_test']);

        return Tenant::factory()->create($attributes);
    }

    protected function runsInTenant(): bool
    {
        return false;
    }

    protected function trackTenantsCreatedInTest(): void
    {
        Tenant::created(function (Tenant $tenant): void {
            $this->tenantsCreatedInTest[] = $tenant;
        });
    }

    // Their rows roll back with the test, but their databases were created outside its transaction.
    protected function deleteTenantsCreatedInTest(): void
    {
        foreach ($this->tenantsCreatedInTest as $tenant) {
            $tenant->delete();
        }
    }
}
