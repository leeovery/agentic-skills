<?php

declare(strict_types=1);

namespace App\Data;

use App\Data\Concerns\HasTestFactory;
use Illuminate\Support\Collection;
use Spatie\LaravelData\Data as BaseData;
use Spatie\LaravelData\Optional;

abstract class Data extends BaseData
{
    use HasTestFactory;

    public function has(string $propertyName): bool
    {
        if (! isset($this->{$propertyName})) {
            return false;
        }

        if ($this->{$propertyName} instanceof Optional) {
            return false;
        }

        if ($this->{$propertyName} instanceof Collection) {
            return $this->{$propertyName}->isNotEmpty();
        }

        return true;
    }
}
