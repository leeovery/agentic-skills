<?php

declare(strict_types=1);

namespace Database\Factories\Data;

use App\Data\Data;
use Illuminate\Support\Collection;

/**
 * @template TData of Data
 */
abstract class DataTestFactory
{
    /**
     * @var class-string<TData>
     */
    protected string $dataClassName;

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $states = [];

    /**
     * @return array<string, mixed>
     */
    abstract public function definition(): array;

    public static function new(): static
    {
        return new static;
    }

    /**
     * @param  class-string<TData>  $className
     */
    public function setDataClass(string $className): void
    {
        $this->dataClassName = $className;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return TData
     */
    public function make(array $attributes = []): Data
    {
        return $this->dataClassName::from(
            array_replace(
                array_replace($this->definition(), ...$this->states),
                $attributes,
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return Collection<int, TData>
     */
    public function collect(array $attributes = [], int $count = 1): Collection
    {
        return Collection::times($count, fn (): Data => $this->make($attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function state(array $attributes): static
    {
        $this->states[] = $attributes;

        return $this;
    }
}
