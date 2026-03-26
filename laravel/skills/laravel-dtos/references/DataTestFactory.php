<?php

declare(strict_types=1);

namespace Database\Factories\Data;

use App\Data\Data;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Collection;

/**
 * Base factory class for all Data object test factories.
 *
 * Provides:
 * - Definition pattern via abstract definition() method
 * - Factory creation: new()
 * - Single instance: make()
 * - Collections: collect()
 * - State pattern: state()
 *
 * @template TData
 */
abstract class DataTestFactory
{
    use WithFaker;

    /**
     * @var null|class-string<Data|TData>
     */
    protected ?string $dataClassName = null;

    private array $states = [];

    /**
     * Define the default state for the Data object.
     *
     * Return an array that will be passed to Data::from()
     */
    abstract public function definition(): array;

    /**
     * Create a new factory instance.
     *
     * @return static
     */
    public static function new()
    {
        return tap(new static, function ($factory) {
            $factory->setUpFaker();
        });
    }

    /**
     * Set the Data class this factory creates.
     *
     * Called automatically by HasTestFactory trait.
     */
    public function setDataClass(string $className): void
    {
        $this->dataClassName = $className;
    }

    /**
     * Create a collection of Data objects.
     *
     * @param  array  $attributes  Override attributes
     * @param  int|null  $count  Number of items to create
     * @return Collection<int, TData>
     */
    public function collect($attributes = [], ?int $count = 1): Collection
    {
        return $this->dataClassName::collect(
            collect(range(1, $count))->map(fn () => $this->make($attributes))
        );
    }

    /**
     * Create a single Data object instance.
     *
     * @param  array  $attributes  Override attributes
     * @return TData
     */
    public function make($attributes = [])
    {
        return $this->dataClassName::from(
            array_replace(
                array_replace($this->definition(), ...$this->states),
                $attributes
            )
        );
    }

    /**
     * Apply a state to the factory.
     *
     * States are merged with definition() before creating the Data object.
     *
     * @param  callable|array  $array  State array or callable returning array
     * @return static
     */
    protected function state(callable|array $array): static
    {
        $this->states[] = value($array);

        return $this;
    }
}
