<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Closure;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Arr;

class RequestDataProviderItem implements Arrayable
{
    public string $attribute;

    public mixed $value;

    public ?string $expectedError = null;

    public ?string $notExpectedError = null;

    public array|Closure $extraRequestData = [];

    public array $taps = [];

    public static function buildString(int $count, string $item = 'x'): string
    {
        return str_repeat($item, $count);
    }

    public static function buildArray(int $count, mixed $item = []): array
    {
        return array_fill(0, $count, $item);
    }

    public function attribute(string $attribute): self
    {
        $this->attribute = $attribute;

        return $this;
    }

    public function tap(callable $callable): self
    {
        $this->taps[] = $callable;

        return $this;
    }

    public function value(mixed $value): self
    {
        $this->value = $value;

        return $this;
    }

    public function number(): self
    {
        $this->value = random_int(10, 1000);

        return $this;
    }

    public function empty(): self
    {
        $this->value = null;

        return $this;
    }

    public function boolean(bool $true = true): self
    {
        $this->value = $true;

        return $this;
    }

    public function string(int $length): self
    {
        $this->value = static::buildString($length);

        return $this;
    }

    public function email(bool $valid = true): static
    {
        $this->value = $valid
            ? fake()->unique()->safeEmail()
            : 'invalid-email@';

        return $this;
    }

    public function date(string $format = 'Y-m-d'): self
    {
        $this->value = now()->format($format);

        return $this;
    }

    public function array(int $count, mixed $item = []): static
    {
        $this->value = static::buildArray($count, $item);

        return $this;
    }

    public function assertError(string $error): self
    {
        $this->expectedError = $error;

        return $this;
    }

    public function assertNotError(string $error): self
    {
        $this->notExpectedError = $error;

        return $this;
    }

    public function with(array|Closure $extraRequestData): self
    {
        $this->extraRequestData = $extraRequestData;

        return $this;
    }

    public function toArray(): array
    {
        return [
            $this->attribute,
            $this->value,
            $this->expectedError,
            $this->notExpectedError,
            $this->extraRequestData,
        ];
    }

    public function buildRequest(...$args): array
    {
        $requestData = [];

        foreach ($this->taps as $tap) {
            value($tap, ...$args);
        }

        return array_replace_recursive(
            data_set($requestData, $this->attribute, value($this->value, ...$args)),
            Arr::undot(value($this->extraRequestData, $requestData, ...$args))
        );
    }
}
