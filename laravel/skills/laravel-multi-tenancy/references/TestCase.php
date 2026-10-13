<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Stancl\Tenancy\Resolvers\RequestDataTenantResolver;
use Tests\Concerns\ManagesTenants;

abstract class TestCase extends BaseTestCase
{
    use ManagesTenants;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->trackTenantsCreatedInTest();
    }

    protected function tearDown(): void
    {
        $this->deleteTenantsCreatedInTest();

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
