<?php

declare(strict_types=1);

namespace App\ValueObjects;

use BadMethodCallException;
use Brick\Math\BigNumber;
use Brick\Money\Money as BrickMoney;
use JsonSerializable;
use Livewire\Wireable;
use Stringable;

/**
 * Money value object wrapping Brick\Money\Money.
 *
 * Provides a clean API for working with money values without exposing
 * the underlying Brick\Money implementation.
 *
 * @method bool isZero()
 * @method bool isPositive()
 * @method bool isNegative()
 * @method bool isPositiveOrZero()
 * @method bool isNegativeOrZero()
 * @method self abs()
 * @method self negated()
 * @method self plus(Money|BigNumber|int|float|string $addend)
 * @method self minus(Money|BigNumber|int|float|string $subtrahend)
 * @method bool isEqualTo(Money|BigNumber|int|float|string $other)
 * @method bool isLessThan(Money|BigNumber|int|float|string $other)
 * @method bool isGreaterThan(Money|BigNumber|int|float|string $other)
 */
final readonly class Money implements JsonSerializable, Stringable, Wireable
{
    /** @var list<string> Methods that return scalar values (delegate directly) */
    private const array SCALAR_METHODS = [
        'isZero',
        'isPositive',
        'isNegative',
        'isPositiveOrZero',
        'isNegativeOrZero',
    ];

    /** @var list<string> Methods that return BrickMoney (wrap in self) */
    private const array MONEY_METHODS = [
        'abs',
        'negated',
    ];

    /** @var list<string> Methods that accept Money argument and return BrickMoney (unwrap arg, wrap result) */
    private const array ARITHMETIC_METHODS = [
        'plus',
        'minus',
    ];

    /** @var list<string> Methods that accept Money argument and return bool (unwrap arg, return directly) */
    private const array COMPARISON_METHODS = [
        'isEqualTo',
        'isLessThan',
        'isGreaterThan',
    ];

    private function __construct(
        private BrickMoney $money,
    ) {}

    public static function of(BigNumber|int|float|string $amount, string $currency = 'GBP'): self
    {
        return new self(BrickMoney::of($amount, $currency));
    }

    public static function ofMinor(BigNumber|int|float|string $minorAmount, string $currency = 'GBP'): self
    {
        return new self(BrickMoney::ofMinor($minorAmount, $currency));
    }

    /**
     * @internal Used by casts and internal operations only
     */
    public static function fromBrick(BrickMoney $money): self
    {
        return new self($money);
    }

    /**
     * @internal Used by casts and internal operations only
     */
    public function toBrick(): BrickMoney
    {
        return $this->money;
    }

    /**
     * @param  array<mixed>  $arguments
     */
    public function __call(string $name, array $arguments): mixed
    {
        if (in_array($name, self::SCALAR_METHODS, true)) {
            return $this->money->{$name}(...$arguments);
        }

        if (in_array($name, self::MONEY_METHODS, true)) {
            return new self($this->money->{$name}(...$arguments));
        }

        if (in_array($name, self::ARITHMETIC_METHODS, true)) {
            return new self($this->money->{$name}($this->unwrap($arguments[0])));
        }

        if (in_array($name, self::COMPARISON_METHODS, true)) {
            return $this->money->{$name}($this->unwrap($arguments[0]));
        }

        throw new BadMethodCallException(sprintf('Method %s::%s does not exist.', self::class, $name));
    }

    private function unwrap(mixed $value): mixed
    {
        return $value instanceof self ? $value->money : $value;
    }

    public function getMinorAmount(): int
    {
        return $this->money->getMinorAmount()->toInt();
    }

    public function getAmount(): float
    {
        return $this->money->getAmount()->toFloat();
    }

    public function getCurrencyCode(): string
    {
        return $this->money->getCurrency()->getCurrencyCode();
    }

    public function format(bool $showNegativeInParentheses = false): string
    {
        $symbol = $this->getCurrencySymbol();
        $formatted = $symbol.number_format($this->money->abs()->getAmount()->toFloat(), 2);

        if ($showNegativeInParentheses && $this->isNegative()) {
            return "({$formatted})";
        }

        return $formatted;
    }

    private function getCurrencySymbol(): string
    {
        return match ($this->getCurrencyCode()) {
            'GBP' => '£',
            'USD' => '$',
            'EUR' => '€',
            default => $this->getCurrencyCode().' ',
        };
    }

    public function __toString(): string
    {
        return $this->format();
    }

    /**
     * @return array{minor_amount: int, currency: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'minor_amount' => $this->getMinorAmount(),
            'currency' => $this->getCurrencyCode(),
        ];
    }

    /**
     * @return array{minor_amount: int, currency: string}
     */
    public function toLivewire(): array
    {
        return [
            'minor_amount' => $this->getMinorAmount(),
            'currency' => $this->getCurrencyCode(),
        ];
    }

    /**
     * @param  array{minor_amount: int, currency: string}  $value
     */
    public static function fromLivewire($value): self
    {
        return self::ofMinor($value['minor_amount'], $value['currency']);
    }
}
