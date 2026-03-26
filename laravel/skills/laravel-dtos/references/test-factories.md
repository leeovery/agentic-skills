# DTO Test Factories

**Test factories create hydrated DTOs for tests.** They live in `database/factories/Data/` and use the `HasTestFactory` trait to enable `::testFactory()` on Data classes.

**Related guides:**
- [SKILL.md](../SKILL.md) - Core DTO patterns and structure
- [dto-transformers.md](dto-transformers.md) - Transformers for domain logic (different purpose)
- [Testing](../../laravel-testing/SKILL.md) - Using test factories in tests

## Test Factories vs Transformers

| Aspect | Test Factories | Transformers |
|--------|----------------|--------------|
| **Purpose** | Generate fake test data | Transform domain data → DTO |
| **Location** | `database/factories/Data/` | `app/Data/Transformers/` |
| **Class naming** | `{Entity}DataFactory` | `{Entity}DataTransformer` |
| **Used in** | Tests only | Domain logic, controllers, handlers |
| **Method style** | `::testFactory()->make()` | `::fromStripe()`, `::fromRequest()` |

## Setup

### 1. Base Data Class

Apply the `HasTestFactory` trait to your base Data class:

**[→ View HasTestFactory.php](./HasTestFactory.php)**

```php
<?php

declare(strict_types=1);

namespace App\Data;

use App\Data\Concerns\HasTestFactory;
use Spatie\LaravelData\Data as BaseData;

abstract class Data extends BaseData
{
    use HasTestFactory;
}
```

### 2. HasTestFactory Trait

**[→ View HasTestFactory.php](./HasTestFactory.php)**

```php
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
```

### 3. Base Factory Class

**[→ View DataTestFactory.php](./DataTestFactory.php)**

```php
<?php

declare(strict_types=1);

namespace Database\Factories\Data;

use App\Data\Data;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Collection;

/**
 * @template TData
 */
abstract class DataTestFactory
{
    use WithFaker;

    /** @var null|class-string<Data|TData> */
    protected ?string $dataClassName = null;

    private array $states = [];

    abstract public function definition(): array;

    public static function new()
    {
        return tap(new static, function ($factory) {
            $factory->setUpFaker();
        });
    }

    public function setDataClass(string $className): void
    {
        $this->dataClassName = $className;
    }

    /**
     * @return Collection<int, TData>
     */
    public function collect($attributes = [], ?int $count = 1): Collection
    {
        return $this->dataClassName::collect(
            collect(range(1, $count))->map(fn () => $this->make($attributes))
        );
    }

    /**
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

    protected function state(callable|array $array): static
    {
        $this->states[] = value($array);

        return $this;
    }
}
```

### 4. Factory Resolver

Register the factory resolver in `AppServiceProvider`:

**[→ View AppServiceProvider.php](./AppServiceProvider.php)**

```php
<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerModelFactoryResolver();
    }

    private function registerModelFactoryResolver(): void
    {
        Factory::guessFactoryNamesUsing(function (string $modelName) {
            if (str($modelName)->endsWith('Data')) {
                return 'Database\Factories\Data\\'.Str::afterLast($modelName, '\\').'Factory';
            }

            return 'Database\Factories\\'.Str::afterLast($modelName, '\\').'Factory';
        });
    }
}
```

## Creating Test Factories

### Basic Factory

**[→ View AddressDataFactory.php](./AddressDataFactory.php)**

```php
<?php

declare(strict_types=1);

namespace Database\Factories\Data;

class AddressDataFactory extends DataTestFactory
{
    public function definition(): array
    {
        return [
            'address1' => fake()->streetAddress(),
            'address2' => null,
            'address3' => null,
            'town' => fake()->city(),
            'county' => fake()->city(),
            'postcode' => fake()->postcode(),
            'country' => 'UK',
            'fromDate' => fake()->dateTimeBetween('-10 years', '-5 years')->format('d-m-Y'),
            'toDate' => fake()->dateTimeBetween('-4 years')->format('d-m-Y'),
            'current' => true,
        ];
    }
}
```

### Factory with States

**[→ View TraceDataFactory.php](./TraceDataFactory.php)**

```php
<?php

declare(strict_types=1);

namespace Database\Factories\Data;

use App\Enums\TraceType;
use Illuminate\Support\Str;

class TraceDataFactory extends DataTestFactory
{
    public function definition(): array
    {
        return [
            'uuid' => Str::uuid()->toString(),
            'provider' => fake()->word(),
            'policyNumber' => fake()->creditCardNumber(separator: ''),
            'employer' => null,
            'industry' => null,
            'type' => TraceType::Pension,
            'fromDate' => fake()->dateTimeBetween('-10 years', '-5 years')->format('d-m-Y'),
            'toDate' => fake()->dateTimeBetween('-4 years')->format('d-m-Y'),
            'documents' => collect(),
        ];
    }

    public function pensionViaProvider(): static
    {
        return $this->state(fn () => [
            'type' => TraceType::Pension,
            'provider' => fake()->company,
            'policyNumber' => fake()->creditCardNumber(separator: ''),
            'employer' => null,
            'industry' => null,
        ]);
    }

    public function pensionViaEmployment(): static
    {
        return $this->state(fn () => [
            'type' => TraceType::Pension,
            'provider' => null,
            'policyNumber' => null,
            'employer' => fake()->company,
            'industry' => fake()->word,
        ]);
    }

    public function investment(): static
    {
        return $this->state(fn () => [
            'type' => TraceType::Investment,
            'provider' => fake()->company,
            'policyNumber' => fake()->creditCardNumber(separator: ''),
            'employer' => null,
            'industry' => null,
        ]);
    }
}
```

## Usage in Tests

### Single Instance

```php
$data = CreateOrderData::testFactory()->make();

// With overrides
$data = CreateOrderData::testFactory()->make([
    'customerEmail' => 'test@example.com',
    'status' => OrderStatus::Pending,
]);
```

### Collections

```php
$items = OrderItemData::testFactory()->collect(count: 5);

// With overrides
$items = OrderItemData::testFactory()->collect(
    attributes: ['quantity' => 1],
    count: 3,
);
```

### With States

```php
// Using state methods
$data = TraceData::testFactory()->pensionViaProvider()->make();
$data = TraceData::testFactory()->pensionViaEmployment()->make();
$data = TraceData::testFactory()->investment()->make();

// Chaining states with overrides
$data = TraceData::testFactory()
    ->pensionViaProvider()
    ->make(['provider' => 'Specific Provider Ltd']);
```

### In Feature Tests

```php
it('creates an order from DTO', function () {
    $user = User::factory()->create();

    $data = CreateOrderData::testFactory()->make([
        'customerEmail' => $user->email,
    ]);

    $order = resolve(CreateOrderAction::class)($user, $data);

    expect($order)
        ->customer_email->toBe($user->email);
});

it('processes multiple items', function () {
    $items = OrderItemData::testFactory()->collect(count: 3);

    $data = CreateOrderData::testFactory()->make([
        'items' => $items,
    ]);

    expect($data->items)->toHaveCount(3);
});
```

## Linking DTOs to Factories

Add PHPDoc to your DTOs for IDE support:

```php
/**
 * @see \Database\Factories\Data\CreateOrderDataFactory
 * @method static CreateOrderDataFactory testFactory()
 */
class CreateOrderData extends Data
{
    public function __construct(
        public string $customerEmail,
        public ?string $notes,
        public OrderStatus $status,
    ) {}
}
```

## Directory Structure

```
database/factories/Data/
├── DataTestFactory.php        # Base factory class
├── AddressDataFactory.php
├── CreateOrderDataFactory.php
├── OrderItemDataFactory.php
├── TraceDataFactory.php
└── UserDataFactory.php
```

## Key Principles

1. **Test factories are for tests only** - Never use in domain logic
2. **Use `::testFactory()->make()`** - Creates hydrated DTO instances
3. **Use states for variations** - `->pensionViaProvider()`, `->investment()`
4. **Override specific attributes** - `->make(['email' => 'test@example.com'])`
5. **Use `collect()` for collections** - `->collect(count: 5)`
