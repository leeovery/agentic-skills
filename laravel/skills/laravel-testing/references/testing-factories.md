# Declarative Factory Methods

Detailed guide to creating readable, maintainable tests using declarative factory methods.

**Related guides:**
- [Testing](../SKILL.md) - Core testing patterns
- [testing-conventions.md](testing-conventions.md) - Test file structure

## The Problem: Leaking Database Schema

When you expose database columns directly in tests, you create brittle, hard-to-read tests:

```php
// ❌ Bad - Database schema leaks into test
it('sends reminder for accepted calendars', function () {
    $calendar = Calendar::factory()->create([
        'status' => 'accepted',
        'reminder_sent_at' => null,
        'approved_by' => User::factory()->create()->id,
        'approved_at' => now(),
    ]);

    // Test logic...
});
```

**Problems with this approach:**
- Exposes database column names (`status`, `reminder_sent_at`, `approved_by`, `approved_at`)
- Not obvious what "accepted" means in business context
- Hard to read and understand intent
- Breaks when database schema changes
- Forces test authors to know exact column names and values

## The Solution: Declarative Factory Methods

Create **named methods** on your factory that express business intent:

```php
// ✅ Good - Declarative and readable
it('sends reminder for accepted calendars', function () {
    $calendar = Calendar::factory()->accepted()->create();

    // Test logic...
});
```

**Benefits:**
- Reads like plain English
- Hides database implementation
- Single place to update when schema changes
- Expresses business domain, not database structure
- Self-documenting tests

## Implementation: State Methods

Create methods for each meaningful state:

```php
// database/factories/CalendarFactory.php
class CalendarFactory extends Factory
{
    public function accepted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'accepted',
            'approved_by' => User::factory(),
            'approved_at' => now(),
            'reminder_sent_at' => null,
        ]);
    }

    public function declined(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'declined',
            'declined_by' => User::factory(),
            'declined_at' => now(),
            'declined_reason' => fake()->sentence(),
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
            'approved_by' => null,
            'approved_at' => null,
            'declined_by' => null,
            'declined_at' => null,
        ]);
    }
}

// Usage in tests - perfectly readable
$acceptedCalendar = Calendar::factory()->accepted()->create();
$declinedCalendar = Calendar::factory()->declined()->create();
$pendingCalendar = Calendar::factory()->pending()->create();
```

## Beyond States: Behavioral Methods

Factory methods aren't just for status columns - use them for **any meaningful business scenario**:

```php
class OrderFactory extends Factory
{
    // Order states
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => OrderStatus::Pending,
            'paid_at' => null,
            'shipped_at' => null,
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => OrderStatus::Paid,
            'paid_at' => now(),
            'payment_intent_id' => 'pi_' . uniqid(),
        ]);
    }

    public function shipped(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => OrderStatus::Shipped,
            'paid_at' => now()->subDays(2),
            'shipped_at' => now(),
            'tracking_number' => fake()->uuid(),
        ]);
    }

    // Behavioral scenarios
    public function overdue(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => OrderStatus::Pending,
            'created_at' => now()->subDays(30),
            'due_date' => now()->subDays(5),
        ]);
    }

    public function withGiftMessage(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_gift' => true,
            'gift_message' => fake()->sentence(),
            'gift_wrap' => true,
        ]);
    }

    public function international(): static
    {
        return $this->state(fn (array $attributes) => [
            'shipping_country' => 'CA',
            'requires_customs' => true,
            'currency' => 'CAD',
        ]);
    }

    // Common scenarios with complex setup
    public function fullyProcessed(): static
    {
        return $this->paid()
            ->shipped()
            ->has(OrderItem::factory()->count(3));
    }
}
```

**Usage - tests read like business requirements:**

```php
it('marks overdue orders', function () {
    $order = Order::factory()->overdue()->create();
    // ...
});

it('adds gift wrap fee for gift orders', function () {
    $order = Order::factory()->withGiftMessage()->create();
    // ...
});

it('calculates customs fees for international orders', function () {
    $order = Order::factory()->international()->create();
    // ...
});
```

## Complex Scenarios: Relationships and Setup

Factory methods can handle complex relationship setup:

```php
class UserFactory extends Factory
{
    public function withSubscription(): static
    {
        return $this->has(
            Subscription::factory()->active(),
            'subscription'
        );
    }

    public function withExpiredSubscription(): static
    {
        return $this->has(
            Subscription::factory()->expired(),
            'subscription'
        );
    }

    public function admin(): static
    {
        return $this->afterCreating(function (User $user) {
            $user->roles()->attach(Role::where('name', 'admin')->first());
            $user->permissions()->attach(Permission::all());
        });
    }

    public function withOrders(int $count = 3): static
    {
        return $this->has(
            Order::factory()->paid()->count($count),
            'orders'
        );
    }

    public function activeCustomer(): static
    {
        return $this->withSubscription()
            ->withOrders(5)
            ->state(fn (array $attributes) => [
                'last_login_at' => now(),
                'email_verified_at' => now(),
            ]);
    }
}

// Usage - complex setup in one readable line
$user = User::factory()->activeCustomer()->create();
$admin = User::factory()->admin()->create();
$expiredUser = User::factory()->withExpiredSubscription()->create();
```

## Chainable Methods for Flexibility

Make methods chainable for maximum flexibility:

```php
// Combine multiple states
$order = Order::factory()
    ->paid()
    ->international()
    ->withGiftMessage()
    ->create();

// Start with one state, chain modifications
$calendar = Calendar::factory()
    ->accepted()
    ->create(['title' => 'Special Event']);
```

## Real-World Example: Before and After

**❌ Before - Hard to read, brittle:**

```php
it('processes payment for order', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
        'subscription_status' => 'active',
        'subscription_expires_at' => now()->addMonth(),
    ]);

    $order = Order::factory()->create([
        'user_id' => $user->id,
        'status' => 'pending',
        'total' => 10000,
        'currency' => 'usd',
        'payment_intent_id' => null,
        'paid_at' => null,
    ]);

    $order->items()->createMany([
        [
            'product_id' => Product::factory()->create(['active' => true, 'stock' => 10])->id,
            'quantity' => 2,
            'price' => 5000,
        ],
    ]);

    // Test logic...
});
```

**✅ After - Readable, maintainable:**

```php
it('processes payment for order', function () {
    $user = User::factory()->withActiveSubscription()->create();
    $order = Order::factory()->pending()->withItems(2)->for($user)->create();

    // Test logic...
});
```

## Guidelines for Factory Methods

### ✅ Do create methods for:
- Common states (`pending()`, `active()`, `cancelled()`)
- Business scenarios (`overdue()`, `international()`, `premium()`)
- Complex setup (`withItems()`, `fullyProcessed()`, `activeCustomer()`)
- Testing edge cases (`expired()`, `invalid()`, `almostFull()`)

### ❌ Don't create methods for:
- One-off test scenarios (use inline array instead)
- Overly specific cases (`withExactly47Items()`)
- Things that should be parameters (`withItems(int $count)` not `with3Items()`)

## Naming Conventions

**State methods:**
- Use adjectives: `active()`, `pending()`, `expired()`, `cancelled()`
- Past tense for completed states: `paid()`, `shipped()`, `verified()`

**Behavioral methods:**
- Use descriptive phrases: `withItems()`, `withSubscription()`, `asAdmin()`
- Boolean properties: `featured()`, `published()`, `archived()`

**Scenario methods:**
- Use business terms: `overdue()`, `international()`, `premium()`
- Combine states meaningfully: `activeCustomer()`, `fullyProcessed()`
