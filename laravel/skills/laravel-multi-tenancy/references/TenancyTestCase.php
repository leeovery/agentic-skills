<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ManagesTenants;

abstract class TestCase extends BaseTestCase
{
    use ManagesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        static::setupTenancyConfiguration();
        static::setUpTenantCreationListener();
        $this->withoutMiddleware([ThrottleRequestsWithRedis::class, TrustProxies::class]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        tenancy()->end();
        static::deleteTenantDatabases();

        parent::tearDown();
    }

    public function json(
        $method,
        $uri,
        array $data = [],
        array $headers = [],
        $options = 0
    ): TestResponse {
        if ($tenant = tenant()) {
            $headers['X-Tenant'] = $tenant->getTenantKey();
        }

        return parent::json($method, $uri, $data, $headers, $options);
    }
}
