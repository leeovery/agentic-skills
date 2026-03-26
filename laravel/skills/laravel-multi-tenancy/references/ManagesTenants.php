<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\ParallelTesting;
use RuntimeException;
use Stancl\Tenancy\Contracts\Tenant as BaseTenant;

trait ManagesTenants
{
    public static bool $tenantsCreated = false;

    public static ?Tenant $testingTenant = null;

    public ?Tenant $tenant = null;

    public static function setUpTenantCreationListener(): void
    {
        Tenant::created(static fn () => static::$tenantsCreated = true);
    }

    public static function deleteTenantDatabases(): void
    {
        if (! static::$testingTenant && ! static::$tenantsCreated) {
            return;
        }

        // If the testing tenant was deleted in-test, detect this here so that a new one
        // can be created for the next test.
        $db = static::$testingTenant?->database();
        if (static::$testingTenant && ! $db->manager()->databaseExists($db->getName())) {
            static::$testingTenant = null;
        }

        // If tenant(s) were created which were not the testing tenant, delete them.
        if (static::$tenantsCreated) {

            Tenant::query()
                ->when(filled(static::$testingTenant), fn (Builder $query) => $query
                    ->whereKeyNot(static::$testingTenant)
                )
                ->cursor()
                ->each(function (Tenant $tenant): void {
                    rescue(callback: fn () => $tenant->delete(), report: false);
                });

        }

        static::$tenantsCreated = false;
    }

    public static function setupTenancyConfiguration(): void
    {
        if ($token = ParallelTesting::token()) {
            config(['tenancy.database.suffix' => "_test_{$token}"]);
        } else {
            config(['tenancy.database.suffix' => '_test']);
        }
    }

    public function currentTenant(): BaseTenant|Tenant
    {
        return $this->tenant ??
            throw new RuntimeException('No tenant is currently initialized.');
    }

    public function initializeTenant(): void
    {
        tenancy()->initialize($this->getTestingTenant());
    }

    public function getTestingTenant(): Tenant
    {
        $tenant = Tenant::firstOr(function () {
            $tenantsCreated = static::$tenantsCreated;

            $tenant = $this->createTenant();

            static::$tenantsCreated = $tenantsCreated ?: false;

            return $tenant;
        });

        static::$testingTenant = clone $tenant;
        $this->tenant = $tenant;

        return $tenant;
    }

    public function createTenant(array $data = []): Tenant
    {
        static::setupTenancyConfiguration();

        return Tenant::factory()->create($data);
    }
}
