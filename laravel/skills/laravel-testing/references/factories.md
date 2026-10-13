# Model factories

A factory's methods are the vocabulary tests describe their data in. A test reads `Order::factory()->shipped()->express()->create()` and never needs to know which columns make an order shipped.

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ShippingMethod;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\User;
use App\States\Order\OrderCancelled;
use App\States\Order\OrderPaid;
use App\States\Order\OrderPending;
use App\States\Order\OrderShipped;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'reference' => fake()->unique()->bothify('ORD-#####'),
            'customer_email' => fake()->safeEmail(),
            'shipping_method' => ShippingMethod::Standard,
            'state' => OrderPending::class,
            'paid_at' => null,
            'shipped_at' => null,
            'cancelled_at' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'state' => OrderPending::class,
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn (): array => [
            'state' => OrderPaid::class,
            'paid_at' => now(),
        ]);
    }

    public function shipped(): static
    {
        return $this->paid()->state(fn (): array => [
            'state' => OrderShipped::class,
            'shipped_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'state' => OrderCancelled::class,
            'cancelled_at' => now(),
        ]);
    }

    public function express(): static
    {
        return $this->state(fn (): array => [
            'shipping_method' => ShippingMethod::Express,
        ]);
    }

    public function withLines(int $count = 1): static
    {
        return $this->has(OrderLine::factory()->count($count), 'lines');
    }
}
```

- **`definition()` is a valid row in the default state.** Every required column filled, relations from their own factories so `Order::factory()->create()` works alone. Nullable columns a state owns are listed as `null`, so the default reads as complete.
- **A state method per model state, named as the state** (`paid()`, `shipped()`). It sets the state and every column that state implies. A state reached through another builds on it: `shipped()` calls `paid()`, so a shipped order has been paid.
- **Variants are adjectives from the domain** (`express()`, `overdue()`); relation helpers are `with{Relation}()`, taking a count when one varies. A relation used by one test stays in that test: `->for($user)`, `->has(OrderLine::factory()->count(3), 'lines')`.
- **`afterCreating()` for setup that needs the saved row**, such as a file in fake storage or a role granted to a user.
- **Methods return `static`**, so they chain in any order. `fake()`, not `$this->faker`.
- **No method for a one-off.** An attribute only one test cares about goes in that test's `create([...])`; a method earns its place when several tests describe data the same way.

Register the factory on the model with `#[UseFactory(OrderFactory::class)]` ([laravel-models](../../laravel-models/SKILL.md)). DTO test factories are a different thing ([laravel-dtos](../../laravel-dtos/SKILL.md)).
