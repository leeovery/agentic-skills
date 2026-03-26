# Code Examples

Quick reference examples showing the core patterns in action.

**Related guides:**
For detailed implementation guides, see:
- [Actions](../../laravel-actions/SKILL.md) - Action patterns
- [DTOs](../../laravel-dtos/SKILL.md) - DTO patterns
- [Controllers](../../laravel-controllers/SKILL.md) - Controller patterns
- [form-requests.md](../../laravel-validation/references/form-requests.md) - Validation patterns
- [Testing](../../laravel-testing/SKILL.md) - Testing guide with triple-A pattern, mocking, and factories
- [validation-testing.md](../../laravel-validation/references/validation-testing.md) - Comprehensive validation testing
- [Models](../../laravel-models/SKILL.md) - Model and custom builder patterns

## Base Data Class

`app/Data/Data.php`
```php
<?php

declare(strict_types=1);

namespace App\Data;

use App\Data\Concerns\HasTestFactory;

abstract class Data extends \Spatie\LaravelData\Data
{
    use HasTestFactory;
}
```

## Sample DTO

`app/Data/CreateOrderData.php`
```php
<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\OrderStatus;
use Illuminate\Support\Collection;

class CreateOrderData extends Data
{
    public function __construct(
        public string $customerEmail,
        public ?string $notes,
        public OrderStatus $status,
        /** @var Collection<int, OrderItemData> */
        public Collection $items,
    ) {
        $this->customerEmail = EmailFormatter::format($this->customerEmail);
    }
}
```

## Sample Action

`app/Actions/Order/CreateOrderAction.php`
```php
<?php

declare(strict_types=1);

namespace App\Actions\Order;

use App\Data\CreateOrderData;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateOrderAction
{
    public function __invoke(User $user, CreateOrderData $data): Order
    {
        return DB::transaction(function () use ($user, $data) {
            $order = $user->orders()->create([
                'status' => $data->status,
                'notes' => $data->notes,
            ]);

            $order->items()->createMany(
                $data->items->map(fn ($item) => [
                    'product_id' => $item->productId,
                    'quantity' => $item->quantity,
                    'price' => $item->price,
                ])->all()
            );

            return $order->fresh(['items']);
        });
    }
}
```

## Sample Controller

`app/Http/Web/Controllers/OrderController.php`
```php
<?php

declare(strict_types=1);

namespace App\Http\Web\Controllers;

use App\Actions\Order\CreateOrderAction;
use App\Data\Transformers\Web\OrderDataTransformer;
use App\Http\Controllers\Controller;
use App\Http\Web\Requests\CreateOrderRequest;
use App\Http\Web\Resources\OrderResource;

class OrderController extends Controller
{
    public function store(
        CreateOrderRequest $request,
        CreateOrderAction $action
    ): OrderResource {
        $order = $action(
            user(),
            OrderDataTransformer::fromRequest($request)
        );

        return OrderResource::make($order);
    }
}
```

## Sample Form Request

`app/Http/Web/Requests/CreateOrderRequest.php`
```php
<?php

declare(strict_types=1);

namespace App\Http\Web\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateOrderRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'customer_email' => [
                'required',
                'string',
                'email',
            ],
            'items' => [
                'required',
                'array',
                'min:1',
            ],
            'items.*.product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id'),
            ],
        ];
    }
}
```

## Sample Transformer

`app/Data/Transformers/Web/OrderDataTransformer.php`
```php
<?php

declare(strict_types=1);

namespace App\Data\Transformers\Web;

use App\Data\CreateOrderData;
use App\Http\Web\Requests\CreateOrderRequest;

class OrderDataTransformer
{
    public static function fromRequest(CreateOrderRequest $request): CreateOrderData
    {
        return CreateOrderData::from([
            'customerEmail' => $request->input('customer_email'),
            'notes' => $request->input('notes'),
            'status' => OrderStatus::from($request->input('status')),
            'items' => OrderItemData::collect($request->input('items')),
        ]);
    }
}
```

## Sample Custom Builder

`app/Builders/OrderBuilder.php`
```php
<?php

declare(strict_types=1);

namespace App\Builders;

use Illuminate\Database\Eloquent\Builder;

class OrderBuilder extends Builder
{
    public function pending(): self
    {
        return $this->where('status', OrderStatus::Pending);
    }

    public function forCustomer(User $customer): self
    {
        return $this->where('user_id', $customer->id);
    }
}
```

## Sample Test

`tests/Feature/Web/OrderControllerTest.php`
```php
<?php

declare(strict_types=1);

use App\Data\CreateOrderData;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

it('creates an order', function () {
    $user = User::factory()->create();
    $data = CreateOrderData::testFactory()->make();

    actingAs($user)
        ->postJson('/orders', $data->toArray())
        ->assertCreated()
        ->assertJsonStructure(['data' => ['id', 'status']]);
});
```

## Bootstrap Configuration

`bootstrap/app.php`
```php
<?php

declare(strict_types=1);

use App\Booters\ExceptionBooter;
use App\Booters\MiddlewareBooter;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(function () {
        Route::middleware('api')
            ->group(base_path('routes/web.php'));

        Route::middleware(['api', 'throttle:api'])
            ->prefix('api/v1')
            ->name('api.v1.')
            ->group(base_path('routes/api/v1.php'));
    })
    ->withMiddleware(new MiddlewareBooter)
    ->withExceptions(new ExceptionBooter)
    ->create();
```
