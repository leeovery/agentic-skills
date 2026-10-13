<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;
use Stancl\Tenancy\Resolvers\RequestDataTenantResolver;
use Tests\Concerns\ManagesTenants;

abstract class TestCase extends BaseTestCase
{
    use ManagesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        static::setUpTenancyConfiguration();
        static::setUpTenantCreationListener();
    }

    protected function tearDown(): void
    {
        tenancy()->end();
        static::deleteTenantDatabases();

        parent::tearDown();
    }

    public function json($method, $uri, array $data = [], array $headers = [], $options = 0): TestResponse
    {
        if ($tenant = tenant()) {
            $headers[RequestDataTenantResolver::headerName()] = $tenant->getTenantKey();
        }

        return parent::json($method, $uri, $data, $headers, $options);
    }
}
