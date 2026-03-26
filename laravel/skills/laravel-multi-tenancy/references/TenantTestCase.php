<?php

declare(strict_types=1);

namespace Tests;

use Tests\Concerns\RefreshDatabaseWithTenant;

abstract class TenantTestCase extends TestCase
{
    use RefreshDatabaseWithTenant;
}
