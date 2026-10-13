<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\ParallelTesting;

trait ManagesTenants
{
    public static bool $tenantsCreated = false;

    public static ?Tenant $testingTenant = null;

    public static function setUpTenantCreationListener(): void
    {
        Tenant::created(static function (): void {
            static::$tenantsCreated = true;
        });
    }

    public static function setUpTenancyConfiguration(): void
    {
        $token = ParallelTesting::token();

        config(['tenancy.database.suffix' => $token ? "_test_{$token}" : '_test']);
    }

    public static function deleteTenantDatabases(): void
    {
        if (static::$testingTenant) {
            $database = static::$testingTenant->database();

            // A test deleted the testing tenant: forget it so the next test creates a fresh one.
            if (! $database->manager()->databaseExists($database->getName())) {
                static::$testingTenant = null;
            }
        }

        if (static::$tenantsCreated) {
            Tenant::query()
                ->when(static::$testingTenant, fn (Builder $query, Tenant $testingTenant): Builder => $query
                    ->whereKeyNot($testingTenant->getTenantKey()))
                ->cursor()
                ->each(function (Tenant $tenant): void {
                    rescue(fn () => $tenant->delete(), report: false);
                });
        }

        static::$tenantsCreated = false;
    }

    public function initializeTenant(): void
    {
        tenancy()->initialize($this->getTestingTenant());
    }

    public function getTestingTenant(): Tenant
    {
        $tenant = Tenant::query()->firstOr(function (): Tenant {
            $tenantsCreated = static::$tenantsCreated;

            $tenant = $this->createTenant();

            // The testing tenant outlives the test, so its creation doesn't mark the test for cleanup.
            static::$tenantsCreated = $tenantsCreated;

            return $tenant;
        });

        static::$testingTenant = clone $tenant;

        return $tenant;
    }

    public function createTenant(array $attributes = []): Tenant
    {
        static::setUpTenancyConfiguration();

        return Tenant::factory()->create($attributes);
    }
}
