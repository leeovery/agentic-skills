<?php

declare(strict_types=1);

namespace Tests\Concerns;

trait RunsInTenant
{
    protected function runsInTenant(): bool
    {
        return true;
    }
}
