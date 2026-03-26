# Core Philosophy

This guide has strong opinions on clean, declarative Laravel architecture. These principles are non-negotiable and form the foundation of every architectural decision.

## The Big Three

### 1. Declarative Code Above All Else

**Code should read like business intent, not implementation details.**

The goal is short, well-named methods that are self-documenting. Extract imperative instructions behind methods with clear names. When you feel the urge to write a comment explaining what code does, that's your signal to extract it into a well-named method instead.

**Bad - Imperative:**
```php
// Check if order can be cancelled
if (! in_array($order->status, ['pending', 'processing'])) {
    throw new OrderException('Cannot cancel this order');
}
```

**Good - Declarative:**
```php
$this->ensureOrderCanBeCancelled($order);

private function ensureOrderCanBeCancelled(Order $order): void
{
    throw_unless($order->canBeCancelled(), OrderCannotBeCancelled::alreadyFulfilled());
}
```

**Key practices:**
- Short methods (5-10 lines max)
- Clear, explicit names
- Extract complex conditionals into named methods
- No comments - the code explains itself
- Business domain language, not technical jargon

### 2. Strict Separation of Concerns

**Domain logic lives in ONE place: Actions.**

Controllers, Jobs, Listeners, and Commands contain **zero domain logic**. They only validate input, delegate to actions, and return responses. Even a simple `$user->update($data)` should be delegated to an action.

**The HTTP layer is thin:**
- Controllers validate (via Form Requests)
- Controllers transform (request → DTO)
- Controllers delegate (call actions)
- Controllers respond (return resources)

**Nothing else.**

### 3. Type Safety First

**Every file begins with `declare(strict_types=1);` - no exceptions.**

- Type every parameter
- Type every return value
- Type every property
- Use PHPDoc for complex types (collections, arrays)
- DTOs for all data transfer (never primitives)

Type safety isn't optional - it's the foundation of maintainable code.

## Core Architectural Principles

### Validation in Form Requests

**Always use Form Requests for validation.**

Form Requests are the single source of truth for what data is valid. They encapsulate validation logic and keep controllers clean.

- All validation rules in Form Requests
- Never validate in controllers
- Never validate in actions

### Thin Delegation Layers

**Jobs, Listeners, and Commands contain zero domain logic.**

These are thin delegation layers that:
1. Set up context (queue config, error handling)
2. Call actions to perform the work
3. Return results

They never contain business logic.

### Route-Level Authorization

**Authorization happens at the route level using `->can()`, not in controllers.**

```php
Route::post('/orders', [OrdersController::class, 'store'])
    ->can('create', Order::class);
```

**Why:**
- Correct HTTP status codes (404 for unauthorized vs 403)
- No ambiguity - consistent location for all routes
- Authorization logic in policies, enforcement at route level

### Actions Contain All Domain Logic

**Actions are invokable classes with a single responsibility.**

```php
<?php
declare(strict_types=1);

namespace App\Actions\Order;

class CreateOrderAction
{
    public function __invoke(CreateOrderData $data): Order
    {
        $this->guard($data);

        return DB::transaction(fn() =>
            Order::create($data->toArray())
        );
    }

    private function guard(CreateOrderData $data): void
    {
        throw_unless($data->user->canPlaceOrder(), UserCannotPlaceOrder::limitReached());
    }
}
```

**Actions are:**
- Invokable (single `__invoke()` method)
- Single responsibility
- Composable (actions call other actions)
- Stateless (each invocation is independent)
- Transactional (wrap DB modifications)
- Type-safe (strict types everywhere)

### DTOs for All Data Transfer

**Never pass multiple primitive values. Always use Data objects.**

```php
// ❌ NEVER
$action->handle($userId, $email, $name, $phone);

// ✅ ALWAYS
$action(UserData::from($request->validated()));
```

**DTOs provide:**
- Type safety
- IDE autocomplete
- Clear contracts between layers
- Validation integration
- Test factories

### Custom Query Builders, Not Scopes

**Never use local scopes. Always use custom query builders.**

```php
// ❌ NEVER - No autocomplete in nested queries
Order::whereHas('items', fn($q) => $q->active())->get();

// ✅ ALWAYS - Full type safety and autocomplete
Order::query()
    ->whereHas('items', fn(OrderItemBuilder $q) => $q->whereActive())
    ->get();
```

**Benefits:**
- Better type hints
- IDE autocomplete in nested queries
- Reusable, chainable methods
- More explicit and readable

### Composability Over Complexity

**Small, focused classes that compose together.**

Don't build giant monolithic actions. Build small, focused actions that compose:

```php
class CreateOrderAction
{
    public function __invoke(CreateOrderData $data): Order
    {
        $order = $this->createOrder($data);
        $this->applyDiscount($order, $data->promoCode);
        $this->notifyCustomer($order);

        return $order;
    }

    private function applyDiscount(Order $order, ?PromoCode $code): void
    {
        if ($code) {
            resolve(ApplyPromoCodeAction::class)($order, $code);
        }
    }
}
```

### State Management

**Use backed enums for simple states (2-5 values), state machines for complex workflows.**

**Backed enums** provide type-safe, finite sets of values:
```php
enum OrderStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
}
```

**State machines** (Spatie Model States) for complex transitions:
- Type-safe state transitions
- Transition validation
- Transition hooks/side effects
- State-specific behavior
- History tracking

Use state machines when you need workflow management, not just status tracking.

### Service Layer for External APIs

**Use Manager pattern with multiple drivers for external service integration.**

For external APIs (Stripe, Twilio, etc.):
1. Manager pattern for driver selection
2. Driver contract (interface)
3. Real driver wrapping external API
4. Null driver for testing
5. Facade for convenience

**Benefits:**
- Swap implementations easily
- Test with null driver
- Circuit breakers and retry logic
- Never mock external libraries directly

### Value Objects for Domain Concepts

**Value objects are simple, immutable objects representing domain concepts.**

```php
readonly class Money
{
    public function __construct(
        public int $amount,
        public Currency $currency,
    ) {}

    public static function fromCents(int $cents, Currency $currency): self
    {
        return new self($cents, $currency);
    }

    public function add(Money $other): self
    {
        throw_unless($this->currency === $other->currency, CurrencyMismatch::create());
        return new self($this->amount + $other->amount, $this->currency);
    }
}
```

**Value Objects vs DTOs:**
- VOs are simpler, focused on single concept
- VOs are immutable with domain logic
- DTOs transfer data between layers
- VOs encapsulate domain rules

### Models Are Thin

**Models handle persistence, relationships, and casting - not business logic.**

Models should:
- Be unguarded globally (configure in AppServiceProvider)
- Use custom query builders (never scopes)
- Cast complex data to DTOs
- Wrap state transitions in methods
- Dispatch domain events
- Define relationships

Models should NOT:
- Contain business logic (use actions)
- Have complex methods (extract to actions)
- Perform calculations (use actions)
- Handle external APIs (use services)

## Code Cleanliness Principles

### Self-Documenting Code

**The code should explain itself without comments.**

When you find yourself writing a comment to explain what code does, that's your signal to extract that code into a well-named method instead. The method name becomes the documentation.

**Only use comments for:**
- Unexpected behavior or constraints you can't refactor away
- Non-obvious business rules that aren't reflected in code structure
- Why something is done a certain way (when the "what" is already clear)

**Never use comments to:**
- Explain what code does (extract to named method instead)
- Document obvious code
- Repeat what's already in method/variable names

**Key practices:**
- Use descriptive variable names
- Extract complex logic into well-named methods
- Use guard methods for validation
- Use early returns to reduce nesting
- Name things from the business domain

### Small Methods

**Every method should do ONE thing well.**

If a method is longer than 10 lines, look for opportunities to extract smaller methods. Each method name becomes documentation for what that section of code does.

### Explicit Over Implicit

**Verbose, clear names over clever, short names.**

```php
// ❌ Unclear
$o->proc();

// ✅ Clear
$order->processPayment();
```

**Name things clearly:**
- `CreateOrderAction` not `OrderCreator`
- `ensureUserCanAccessOrder()` not `check()`
- `calculateTotalWithTax()` not `total()`

### No Magic

**Everything should be explicit and traceable.**

- No dynamic method calls unless absolutely necessary
- No string-based class references
- No hidden global state
- Type-hint dependencies explicitly
- Use named constructors for complex object creation
- Always use `resolve()` helper, never `app()` for consistency

### PHPDoc Usage

**Use PHPDoc minimally. Declarative code should be self-documenting.**

**Only use PHPDoc when:**
1. Type information can't be expressed in native PHP (generics, collections)
   ```php
   /** @var Collection<int, Order> */
   public Collection $orders;
   ```
2. Complex logic that cannot be simplified or made declarative
3. Unexpected behavior that isn't obvious from code

**Never use PHPDoc for:**
- Documenting every class and method automatically
- Repeating what code already says
- Type information already in type hints
- Obvious code that should be refactored instead

## Testing Philosophy

### Triple-A Pattern

**Every test follows Arrange-Act-Assert.**

```php
test('creates order with items', function () {
    // Arrange
    $user = User::factory()->create();
    $data = CreateOrderData::factory()->make();

    // Act
    $order = resolve(CreateOrderAction::class)($data);

    // Assert
    expect($order)->toBeInstanceOf(Order::class);
    expect($order->items)->toHaveCount(3);
});
```

### Only Mock What You Own

**Never mock external libraries directly.**

For external services, create a service layer with:
1. Manager pattern
2. Driver contract (interface)
3. Real driver (wraps external API)
4. Null driver for testing
5. Facade for convenience

### Factory-Based Testing

**Use factories, not hardcoded data.**

**Bad - Leaks database implementation:**
```php
Calendar::factory()->create([
    'status' => 'accepted',
    'reminder_sent_at' => null,
    'approved_by' => User::factory()->create()->id,
]);
```

**Good - Declarative and domain-focused:**
```php
Calendar::factory()->accepted()->create();
```

**Benefits:**
- Reads like plain English
- Hides database implementation details
- Single place to update when schema changes
- Self-documenting tests

## Quality Standards

### Non-Negotiable Rules

1. **Every file starts with `declare(strict_types=1);`**
2. **Controllers contain zero domain logic**
3. **Never pass primitives - always use DTOs**
4. **Never use local scopes - always use custom builders**
5. **Every route must be named**
6. **Every action must be transactional**
7. **Every test uses factories, not hardcoded data**
8. **80%+ test coverage on critical paths**
9. **PHPStan level 8 with zero errors**
10. **Zero Laravel Pint violations**

### Architecture Tests Enforce Standards

**Architecture tests automatically verify compliance with these principles.**

Use Pest's architecture testing to enforce:
- Actions are invokable
- Actions live in correct namespace
- Data objects extend base class
- Controllers don't query database directly
- Models use custom builders, not scopes
- Naming conventions followed
- Strict types declared in all files
- No domain logic in controllers/jobs/listeners

Architecture tests prevent drift from these standards over time.

## What NOT to Do

### Never

- ❌ Domain logic in controllers/jobs/listeners/commands
- ❌ Pass multiple primitives (use DTOs)
- ❌ Use local scopes (use custom builders)
- ❌ Skip type declarations
- ❌ Skip `declare(strict_types=1)`
- ❌ Fat models with business logic
- ❌ Validation outside form requests
- ❌ Comments explaining what code does (extract to named method)
- ❌ Methods longer than 10-15 lines
- ❌ Hardcoded test data (use factories)
- ❌ Mock external libraries directly (use service layer)
- ❌ Dynamic method calls without type safety
- ❌ Untyped arrays or collections
- ❌ Missing return types
- ❌ Missing property types

## The Strong Opinions

This architecture is opinionated by design:

1. **Declarative over imperative** - Code reads like business intent
2. **Type safety first** - Strict types everywhere, no exceptions
3. **Separation of concerns** - Domain logic ONLY in actions
4. **DTOs for all data transfer** - Never pass primitives
5. **Testability by design** - Every layer independently testable
6. **Composability** - Small, focused, reusable units
7. **Self-documenting code** - Well-named methods, no comments
8. **Factory-based testing** - Realistic, readable test data
9. **Only mock what you own** - Never mock external libraries
10. **Architecture enforcement** - Tests verify compliance automatically

These aren't suggestions - they're requirements. Following them creates maintainable, testable, readable Laravel applications that scale with your team and your business.
