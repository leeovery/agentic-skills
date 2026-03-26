<?php

declare(strict_types=1);

namespace App\Data\Concerns;

use Database\Factories\Data\DataTestFactory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Trait to add test factory support to Data objects.
 *
 * Apply this trait to your base Data class to enable test factory resolution.
 *
 * @see DataTestFactory
 */
trait HasTestFactory
{
    /**
     * Get a test factory instance for this Data class.
     *
     * @return DataTestFactory<static>
     */
    public static function testFactory(): DataTestFactory
    {
        return tap(Factory::factoryForModel(static::class))->setDataClass(static::class);
    }
}
