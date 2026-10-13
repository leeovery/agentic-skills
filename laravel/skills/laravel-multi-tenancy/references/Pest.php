<?php

declare(strict_types=1);

use Tests\Concerns\FastRefreshDatabaseWithTenancy;
use Tests\Concerns\RunsInTenant;
use Tests\TestCase;

uses(TestCase::class, FastRefreshDatabaseWithTenancy::class)->in('Feature', 'Unit', 'Architecture');
uses(RunsInTenant::class)->in('Feature/Tenanted', 'Unit/Tenanted');
