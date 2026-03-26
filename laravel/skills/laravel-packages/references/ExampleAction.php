<?php

declare(strict_types=1);

namespace YourName\MyPackage;

abstract class Action
{
    /**
     * Execute the action.
     */
    abstract public function __invoke(mixed ...$parameters): mixed;

    /**
     * Create a new instance via dependency injection.
     */
    public static function make(): static
    {
        return resolve(static::class);
    }

    /**
     * Create and immediately execute the action.
     */
    public static function run(mixed ...$parameters): mixed
    {
        return static::make()(...$parameters);
    }
}
