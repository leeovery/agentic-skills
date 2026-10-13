# Money

Read when the domain stores, calculates or displays amounts of money.

## Rules

- **moneyphp/money's `Money` is the value.** It holds an integer amount in minor units with its currency, and has arithmetic, comparison and allocation built in (`add()`, `subtract()`, `multiply()`, `allocate()`, `equals()`, `greaterThan()`). Don't wrap it in an app `Money` class or hand-roll the arithmetic: floats lose pennies, and a wrapper only re-exposes the same API.
- **Every `Money` is built by one `MoneyFactory`.** The conversion between what people type (`19.99`) and minor units (`1999`) then lives in one place. Decimals are parsed as strings by moneyphp's `DecimalMoneyParser`, never multiplied by 100: `19.99 * 100` is `1998.9999…` in floating point.
- **Stored through a `MoneyCast`.** moneyphp's `Money` is a `final` vendor class and can't implement `Castable`, so the model names the cast: `'total' => MoneyCast::class`.
- **Formatted at the edge.** `IntlMoneyFormatter` (with a `NumberFormatter` and `ISOCurrencies`) where it's displayed. JSON carries what `Money::jsonSerialize()` gives: the minor amount as a string, and the currency code.
- **A value made of money and more is its own value object.** A price with the exchange rate it was converted at holds `Money` parts and is `Castable`, like any other value object.

## The factory

```php
<?php

declare(strict_types=1);

namespace App\Values;

use Money\Currencies\ISOCurrencies;
use Money\Currency;
use Money\Money;
use Money\Parser\DecimalMoneyParser;

class MoneyFactory
{
    public static function make(null|int|string $amount, ?string $currency, bool $minor = false): ?Money
    {
        if (is_null($amount) || is_null($currency)) {
            return null;
        }

        $currency = new Currency($currency);

        return $minor
            ? new Money($amount, $currency)
            : (new DecimalMoneyParser(new ISOCurrencies()))->parse((string) $amount, $currency);
    }
}
```

`MoneyFactory::make('19.99', 'GBP')` takes major units; `MoneyFactory::make(1999, 'GBP', minor: true)` takes what's stored. A null amount or currency gives null, so nullable input and nullable columns need no special case.

## The cast

```php
<?php

declare(strict_types=1);

namespace App\Casts;

use App\Values\MoneyFactory;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Money\Money;

/**
 * @implements CastsAttributes<Money, Money>
 */
class MoneyCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        if (is_null($value)) {
            return null;
        }

        /** @var array{amount: string, currency: string} $money */
        $money = json_decode((string) $value, true, flags: JSON_THROW_ON_ERROR);

        return MoneyFactory::make($money['amount'], $money['currency'], minor: true);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if (is_null($value)) {
            return null;
        }

        if (! $value instanceof Money) {
            throw new InvalidArgumentException("The {$key} attribute must be a ".Money::class.'.');
        }

        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
```

One JSON column keeps the amount and its currency together. When amounts are summed, sorted or filtered in SQL, store them in two columns instead (`{key}_amount` as a bigint of minor units, `{key}_currency`) and have the cast read and write both, as `CoordinateCast` does in the [skill](../SKILL.md).

## From a request

Controllers build money through the factory too. A `money()` request macro, registered with the app's other macros (see [laravel-providers](../../laravel-providers/SKILL.md)), keeps it to one call: `$request->money('price', 'currency')`.

```php
Request::macro('money', function (string $amountKey = 'amount', string $currencyKey = 'currency'): ?Money {
    /** @var Request $this */
    return $this->filled($amountKey)
        ? MoneyFactory::make($this->string($amountKey)->value(), $this->string($currencyKey)->value())
        : null;
});
```

`string()` turns a JSON number into the decimal string the parser expects, so `19.99` never passes through a float multiplication.
