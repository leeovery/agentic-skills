<?php

declare(strict_types=1);

use Illuminate\Support\Collection;

if (! function_exists('collect_get')) {
    /**
     * Get an item from an array or object using "dot" notation and wrap into a collection.
     *
     * Useful for safely accessing nested arrays in external API responses and
     * immediately mapping them to DTOs.
     *
     * @param  mixed|array  $target  The array or object to search
     * @param  int|array|string|null  $key  The key to retrieve
     * @param  mixed|array  $default  Default value if key not found (default: [])
     * @return Collection
     *
     * @example
     * collect_get($payload, 'items')
     *     ->map(fn ($item) => ItemData::from($item))
     *
     * @example
     * collect_get($response, 'data.users', default: [])
     *     ->map(UserDataFactory::fromApiUser(...))
     */
    function collect_get(mixed $target, int|array|string|null $key, mixed $default = []): Collection
    {
        return collect(data_get($target, $key, $default));
    }
}
