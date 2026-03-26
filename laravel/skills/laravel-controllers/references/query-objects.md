# Query Objects

Query Objects encapsulate complex filtering, sorting, and includes for API endpoints using Spatie Query Builder.

**Related guides:**
- [Controllers](../SKILL.md) - Controllers use query objects
- [Query Builders](../../laravel-query-builders/SKILL.md) - Custom Eloquent query builders (different purpose)

## When to Use Query Objects

**Use query objects when:**
- API endpoint needs filtering, sorting, or includes
- Complex query configuration for list endpoints
- Multiple allowed filters/sorts/includes
- Need to reuse query logic across endpoints

**Don't use for:**
- Simple queries (use model/builder methods directly)
- Internal queries without API exposure
- Single-use queries

## Basic Structure

```php
<?php

declare(strict_types=1);

namespace App\Http\Web\Queries;

use App\Builders\OrderBuilder;
use App\Models\Order;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

class OrderIndexQuery extends QueryBuilder
{
    public function __construct()
    {
        $query = Order::query()
            ->with(['customer', 'items']);

        parent::__construct($query);

        $this
            ->defaultSort('-created_at')
            ->allowedSorts([
                AllowedSort::field('id'),
                AllowedSort::field('total'),
                AllowedSort::field('created_at'),
            ])
            ->allowedFilters([
                AllowedFilter::exact('status'),
                AllowedFilter::callback('search', function (OrderBuilder $query, $value): void {
                    $query->where(function (OrderBuilder $query) use ($value): void {
                        $query
                            ->where('id', $value)
                            ->orWhere('order_number', $value)
                            ->orWhereHas('customer', fn ($q) => $q->whereLike('email', "%{$value}%"));
                    });
                }),
            ])
            ->allowedIncludes(['customer', 'items', 'shipments']);
    }
}
```

## Usage in Controllers

```php
public function index(OrderIndexQuery $query): AnonymousResourceCollection
{
    return OrderResource::collection($query->jsonPaginate());
}
```

## Filter Types

### Exact Filters

```php
AllowedFilter::exact('status'),
AllowedFilter::exact('customer_id'),
```

**Usage:** `?filter[status]=pending`

### Partial Filters

```php
AllowedFilter::partial('name'),
AllowedFilter::partial('email'),
```

**Usage:** `?filter[name]=john` (matches "John", "Johnny", etc.)

### Callback Filters

```php
AllowedFilter::callback('search', function (Builder $query, $value): void {
    $query->where(function (Builder $query) use ($value): void {
        $query
            ->where('name', 'like', "%{$value}%")
            ->orWhere('email', 'like', "%{$value}%");
    });
}),
```

**Usage:** `?filter[search]=john`

### Scope Filters

```php
AllowedFilter::scope('active'),
AllowedFilter::scope('created_after'),
```

**Usage:** `?filter[active]=true&filter[created_after]=2024-01-01`

## Sort Configuration

```php
->defaultSort('-created_at')  // Default descending by created_at
->allowedSorts([
    AllowedSort::field('id'),
    AllowedSort::field('total'),
    AllowedSort::field('created_at'),
    AllowedSort::field('name', 'customer_name'),  // Alias
])
```

**Usage:** `?sort=-created_at` (descending) or `?sort=total` (ascending)

## Includes (Eager Loading)

```php
->allowedIncludes(['customer', 'items', 'items.product', 'shipments'])
```

**Usage:** `?include=customer,items.product`

## Organization

Query objects live alongside controllers:

```
app/Http/
├── Web/
│   ├── Controllers/
│   │   └── OrdersController.php
│   └── Queries/
│       ├── OrderIndexQuery.php
│       └── CustomerIndexQuery.php
└── Api/V1/
    ├── Controllers/
    │   └── OrdersController.php
    └── Queries/
        └── OrderIndexQuery.php
```

## Benefits

- **Separation of concerns** - Query logic outside controllers
- **Reusability** - Same query across multiple endpoints
- **Testability** - Query objects are independently testable
- **Type safety** - Works with custom builders for IDE support
- **Consistency** - Standard API query patterns

## Query Objects vs Custom Builders

| Aspect | Query Objects | Custom Builders |
|--------|---------------|-----------------|
| **Purpose** | API filtering/sorting/includes | Reusable query methods |
| **Package** | Spatie Query Builder | Native Eloquent |
| **Location** | `app/Http/{Layer}/Queries/` | `app/Builders/` |
| **Used by** | Controllers (API endpoints) | Actions, other queries |
| **Example** | `OrderIndexQuery` | `OrderBuilder::pending()` |

**Use together:** Query objects can use custom builders for complex filters:

```php
AllowedFilter::callback('search', function (OrderBuilder $query, $value): void {
    $query->search($value);  // Delegates to custom builder method
});
```
