<?php

declare(strict_types=1);

namespace App\Data;

use App\Data\Concerns\HasTestFactory;
use Illuminate\Support\Collection;
use Spatie\LaravelData\Data as BaseData;
use Spatie\LaravelData\Optional;

/**
 * Base Data class for all DTOs in the application.
 *
 * Extends Spatie Laravel Data and adds:
 * - Test factory support via HasTestFactory trait
 * - has() method for checking if a property has a value
 *
 * All application DTOs should extend this class.
 */
abstract class Data extends BaseData
{
    use HasTestFactory;

    /**
     * Check if a property has a value (not null, not Optional, not empty Collection).
     */
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
