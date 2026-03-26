<?php

declare(strict_types=1);

namespace App\Values;

use InvalidArgumentException;

final class Money
{
    private function __construct(
        public readonly int $amount,
        public readonly string $currency,
    ) {
        if ($this->amount < 0) {
            throw new InvalidArgumentException('Amount cannot be negative');
        }
    }

    public static function fromCents(int $cents, string $currency = 'USD'): self
    {
        return new self($cents, strtoupper($currency));
    }

    public static function fromDollars(float $dollars, string $currency = 'USD'): self
    {
        return new self((int) round($dollars * 100), strtoupper($currency));
    }

    public function add(Money $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount + $other->amount, $this->currency);
    }

    public function subtract(Money $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount - $other->amount, $this->currency);
    }

    public function multiply(int|float $multiplier): self
    {
        return new self(
            (int) round($this->amount * $multiplier),
            $this->currency
        );
    }

    public function formatted(): string
    {
        return number_format($this->amount / 100, 2);
    }

    public function equals(Money $other): bool
    {
        return $this->amount === $other->amount
            && $this->currency === $other->currency;
    }

    private function assertSameCurrency(Money $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException('Currency mismatch');
        }
    }
}
