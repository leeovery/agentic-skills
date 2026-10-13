<?php

declare(strict_types=1);

namespace App\Data\Concerns;

use Database\Factories\Data\DataTestFactory;
use Illuminate\Database\Eloquent\Factories\Factory;

trait HasTestFactory
{
    /**
     * @return DataTestFactory<static>
     */
    public static function testFactory(): DataTestFactory
    {
        return tap(Factory::factoryForModel(static::class))->setDataClass(static::class);
    }
}
