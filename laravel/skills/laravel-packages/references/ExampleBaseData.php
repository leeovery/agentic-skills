<?php

declare(strict_types=1);

namespace YourName\MyPackage\Data;

use Spatie\LaravelData\Data;

abstract class BaseData extends Data
{
    /**
     * Create a test factory for this DTO.
     */
    public static function testFactory(): callable
    {
        throw new \RuntimeException(
            'Test factory not implemented for ' . static::class
        );
    }
}
