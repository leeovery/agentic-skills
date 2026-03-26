# Custom Query Builders

**Always use custom query builders instead of local scopes** for better type hints, IDE autocomplete, and composability.

## Why Custom Builders?

**Custom builders provide:**
- Better IDE autocomplete
- Proper type hints
- Method chaining
- Composability
- Easier testing

**Local scopes are inferior:**
- Poor type hints
- Limited IDE support
- Harder to compose
- Less discoverable

## Creating a Custom Builder

```php
<?php

declare(strict_types=1);

namespace App\Builders;

use App\Enums\OrderStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class OrderBuilder extends Builder
{
    public function pending(): self
    {
        return $this->where('status', OrderStatus::Pending);
    }

    public function completed(): self
    {
        return $this->where('status', OrderStatus::Completed);
    }

    public function forUser(User $user): self
    {
        return $this->where('user_id', $user->id);
    }

    public function withTotal(): self
    {
        return $this->selectRaw('*, (SELECT SUM(total) FROM order_items WHERE order_items.order_id = orders.id) as calculated_total');
    }

    public function recent(int $days = 30): self
    {
        return $this->where('created_at', '>=', now()->subDays($days));
    }
}
```

## Using Custom Builder in Model

```php
class Order extends Model
{
    public function newEloquentBuilder($query): OrderBuilder
    {
        return new OrderBuilder($query);
    }
}
```

## Usage

```php
// Now you get type hints and autocomplete!
$orders = Order::query()
    ->pending()
    ->forUser($user)
    ->recent(7)
    ->get();

// Method chaining works beautifully
$total = Order::query()
    ->completed()
    ->forUser($user)
    ->sum('total');
```

## Directory Structure

```
app/Builders/
├── OrderBuilder.php
├── UserBuilder.php
└── ProductBuilder.php
```
