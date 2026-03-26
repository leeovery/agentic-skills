<?php

declare(strict_types=1);

namespace App\Exceptions\Concerns;

trait Httpable
{
    public function getStatusCode(): int
    {
        return $this->getCode();
    }

    public function getHeaders(): array
    {
        return [];
    }
}
