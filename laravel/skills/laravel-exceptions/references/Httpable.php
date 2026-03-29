<?php

declare(strict_types=1);

namespace App\Exceptions\Concerns;

trait Httpable
{
    public function getStatusCode(): int
    {
        return $this->code ?? 500;
    }

    public function getHeaders(): array
    {
        return [];
    }
}
