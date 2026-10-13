---
name: laravel-testing
description: Pest tests read one way, with test() and will/can names, container mocks, scoped fakes, factory states and endpoint feature tests on a real database. Use when writing or changing tests, model factories, fakes, mocks or the test setup.
---

# Laravel Testing

Every test reads the same way: a Pest `test()` named as a sentence, built from factories, run against a real database, with only its collaborators mocked and only the side effects it asserts faked. A unit test covers one class, built through the container; a feature test covers one controller's endpoints through HTTP. How to test each kind of class lives with the skill that owns it. This one owns the style they share, HTTP tests, models, builders and factories, fakes, and the test environment.

## Rules

- **Read the project's tests first.** `tests/Pest.php`, `tests/TestCase.php` and a neighbouring test. Sign in, compare dates and build URLs with the helpers and expectations they define; this skill gives the shape, the project gives the helpers.
- **`test()`, never `it()`.** Names are lowercase sentences: unit tests say what will happen (`'will cancel a paid order'`, `'will not …'`), HTTP tests what an actor can do (`'can create an order'`, `'cannot …'`).
- **No `// Arrange`, `// Act`, `// Assert` comments.** A blank line separates the setup, the call and the assertions.
- **Pest's Laravel functions, imported.** `getJson()`, `mock()`, `freezeTime()` and `assertDatabaseHas()` come from `use function Pest\Laravel\…;`, never `$this->`.
- **A failure is `->throws()`.** `test(...)->throws(OrderException::class, OrderException::cannotCancel()->getMessage())` ([laravel-exceptions](../laravel-exceptions/SKILL.md)). `expect(fn () => …)->toThrow()` only when the test asserts something after the throw, such as a rolled-back write.
- **Mock collaborators with `mock()`.** `Pest\Laravel\mock(X::class)` binds the mock into the container, so the class under test, built with `resolve()`, receives it; there's nothing to `swap()`. `Mockery::mock()` is only for an object the test hands over itself. [laravel-actions](../laravel-actions/SKILL.md) has the recipe.
- **Real data, from factories.** Models from `Order::factory()` and its states, DTOs from `testFactory()` ([laravel-dtos](../laravel-dtos/SKILL.md)). Never mock a model or DTO, or build one from an inline array. Unit tests hit the database too: they create real rows and assert what was written.
- **`->refresh()` to re-read a model.** It reloads the instance the test holds; `fresh()` returns a second copy and leaves the first stale.
- **`config([...])` to change config**, never `Config::set()`.
- **Freeze what you assert.** `freezeTime()` before asserting a time, `freezeSecond()` when comparing with a stored timestamp (the database drops microseconds), `travelTo()` to move the clock. `Str::freezeUlids()` or `Str::freezeUuids()` before asserting a generated id. Never `Carbon::setTestNow()`.
- **Fake only the events you assert.** `Event::fake([OrderShipped::class])`. A bare `Event::fake()` swallows every event, model events included, so listeners the code depends on stop running and the test can't tell.

## Example

`tests/Feature/Api/V1/OrderTest.php`, for `OrderController` on the `Api/V1` surface:

```php
<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\User;
use Illuminate\Testing\Fluent\AssertableJson;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

getJson('/api/v1/orders')->assertUnauthorized();
getJson('/api/v1/orders/01jbx3vq7rk9z2m4n6p8s0t1w3')->assertUnauthorized();
postJson('/api/v1/orders')->assertUnauthorized();
putJson('/api/v1/orders/01jbx3vq7rk9z2m4n6p8s0t1w3')->assertUnauthorized();
deleteJson('/api/v1/orders/01jbx3vq7rk9z2m4n6p8s0t1w3')->assertUnauthorized();

test('can show an order', function (): void {
    $user = User::factory()->create();
    $order = Order::factory()->for($user)->paid()->create();

    actingAs($user)
        ->getJson("/api/v1/orders/{$order->ulid}")
        ->assertOk()
        ->assertJson(fn (AssertableJson $json): AssertableJson => $json
            ->has('data', fn (AssertableJson $json): AssertableJson => $json
                ->where('ulid', $order->ulid)
                ->where('reference', $order->reference)
                ->etc()));
});

test("cannot show another user's order", function (): void {
    $order = Order::factory()->create();

    actingAs(User::factory()->create())
        ->getJson("/api/v1/orders/{$order->ulid}")
        ->assertForbidden();
});

test('can create an order', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->postJson('/api/v1/orders', ['customer_email' => 'customer@example.com'])
        ->assertCreated();

    expect(Order::query()->sole())
        ->user_id->toBe($user->id)
        ->customer_email->toBe('customer@example.com');
});
```

## HTTP tests

- **One file per controller**, in `tests/Feature/{Surface}/`, named after it without the suffix: `OrderController` → `OrderTest.php`, `CancelOrderController` → `CancelOrderTest.php`.
- **Unauthenticated first.** The file opens with one top-level request per route, asserting `assertUnauthorized()`. Authentication runs before binding, so the id needn't exist.
- **Then the endpoints in controller order**: index, show, store, update, destroy. Each gets its happy path, then its validation dataset test ([laravel-validation](../laravel-validation/SKILL.md)), then its failures.
- **Request the literal URL**, `'/api/v1/orders'`, never `route()`. The URL is what clients call; a test that builds it from the route name follows a changed path instead of catching it.
- **Sign in the way the project's tests already do.** Where the project has no helper, `actingAs($user)`.
- **Every route's `->can()` gets a `cannot …` test** with a user who lacks the ability, asserting `assertForbidden()`. The policy's own logic is unit-tested ([laravel-policies](../laravel-policies/SKILL.md)).
- **Assert the response and the change.** `assertJson()` with an `AssertableJson` closure for the fields the endpoint promises, then the database. A business-rule failure asserts its status and message ([laravel-exceptions](../laravel-exceptions/SKILL.md)).

## Models, builders and factories

- **A model test covers what the model computes**: accessors, `is{State}()` helpers and capabilities, casts that transform a value. Relationships and attributes Laravel provides need no test.
- **A builder method is tested with rows on both sides of its condition**, asserting exactly the matching ones come back. A constraint that matches everything passes any test whose rows all match.

```php
test('will only return paid orders', function (): void {
    $paid = Order::factory()->paid()->create();
    Order::factory()->pending()->create();

    expect(Order::query()->wherePaid()->pluck('id')->all())->toBe([$paid->id]);
});
```

- **Every model has a factory whose `definition()` makes a valid row**, with related models from their own factories: `'user_id' => User::factory()`.
- **One state method per model state**, setting every column that state implies: `shipped()` sets `shipped_at` and `paid_at`, so a shipped order is shipped all the way through ([laravel-state-machines](../laravel-state-machines/SKILL.md)).
- **Tests call states, never spell out columns.** `Order::factory()->shipped()->create()`. Named variants (`express()`) and `with{Relation}()` methods cover other common shapes; `for($user)` and `has()` cover a one-off relation.

Read [references/factories.md](references/factories.md) when writing or extending a model factory.

## Fakes and mocks

| When the code under test … | The test |
|---|---|
| calls another action | `mock(ReleaseStockAction::class)->shouldReceive('__invoke')`, per [laravel-actions](../laravel-actions/SKILL.md) |
| calls a service | `Shipping::shouldReceive('cancelShipment')` on its facade; the driver's own test uses Saloon fixtures, per [laravel-services](../laravel-services/SKILL.md) |
| dispatches an event | `Event::fake([OrderShipped::class])`, then `Event::assertDispatched(fn (OrderShipped $event): bool => $event->order->is($order))` |
| queues a job | `Queue::fake()`, then `Queue::assertPushed(fn (ExpireOrderJob $job): bool => $job->order->is($order))`; the job's own test is in [laravel-jobs](../laravel-jobs/SKILL.md) |
| writes files | `Storage::fake('documents')` |
| calls a package facade that offers `fake()` | that fake, not a mock |

## Test environment

- **A real database on the production engine**: a separate test database, configured in `.env-testing` ([laravel-providers](../laravel-providers/SKILL.md) owns the env templates). `phpunit.xml` sets only `APP_ENV=testing`. SQLite in memory hides the engine's differences in JSON, dates and constraints.
- **Reset with `FastRefreshDatabase`** (plannr/laravel-fast-refresh-database), applied in `tests/Pest.php`. Like `RefreshDatabase`, it wraps each test in a transaction; it re-migrates only when a migration file has changed.
- **No real outbound requests.** `TestCase::setUp()` calls `Http::preventStrayRequests()` and, once the app has a Saloon connector, `Saloon\Config::preventStrayRequests()`, because the first doesn't catch Saloon's requests. A request with no fake then throws.
- **The queue is `sync` in `.env-testing`**, so a dispatched job runs inline unless the test fakes the queue.
- **Run the suite through the composer scripts**, `composer pest` or `composer pest-dirty` ([laravel-quality](../laravel-quality/SKILL.md) owns them, and the architecture test).

Read [references/test-setup.md](references/test-setup.md) when the project has no `tests/Pest.php` or `TestCase` setup yet.

## Reach for these when the situation calls for it

**The app needs reference data to run**, such as roles, permissions or lookup rows. Set `protected bool $seed = true;` on the `TestCase` so every test starts with it, and never recreate it in a test. `DatabaseSeeder` then seeds reference data in every environment and demo data only in `local`. `FastRefreshDatabase` checksums migration files only, so after changing a seeder delete `storage/app/migration-checksum_*.txt`.

**Roles get different answers from the same routes.** One `describe()` per role signs in with `beforeEach()` (granting the role however the project's factories do) and lists each route with that role's status:

```php
describe('permissions: viewer', function (): void {
    beforeEach(function (): void {
        Order::factory()->create(['ulid' => '01jbx3vq7rk9z2m4n6p8s0t1w3']);
        actingAs(User::factory()->viewer()->create());
    });

    getJson('/api/v1/orders/01jbx3vq7rk9z2m4n6p8s0t1w3')->assertOk();
    postJson('/api/v1/orders')->assertForbidden();
});
```

A role that may create asserts `assertUnprocessable()` on the same empty `POST`: it got past authorization, and the test needed no payload.

**A large structure that should change only on purpose**, such as a full API response or a model's serialised attributes. `expect($response)->toMatchSnapshot()`, built from fixed values (ids, ulids, dates) so it's stable between runs. Regenerate with `--update-snapshots` only for an intended change.

**A test that is one expectation.** Write it higher-order: `test('will not let a shipped order be edited')->expect(fn (): bool => Order::factory()->shipped()->make()->isEditable())->toBeFalse();`

**Setup whose own model events would start work the test isn't about.** Create it with `createQuietly()`; otherwise `create()`.

## Anti-patterns

- ❌ `it('creates an order', ...)`, or `// Arrange` / `// Act` / `// Assert` comments.
- ❌ `swap(ReleaseStockAction::class, $mock)` after `mock()`, or `Mockery::mock(ReleaseStockAction::class)` for a class the container builds.
- ❌ `Mockery::mock(Order::class)`, or `CreateOrderData::from([...])` in a test.
- ❌ `expect(fn () => resolve(CancelOrderAction::class)($order))->toThrow(OrderException::class)` as the whole test.
- ❌ `Event::fake();` with no list.
- ❌ `Order::factory()->create(['state' => OrderShipped::class, 'shipped_at' => now()])`.
- ❌ `$order->fresh()`, `Config::set(...)`, `Carbon::setTestNow(...)`.
- ❌ `getJson(route('api-v1.order.show', $order))`.
- ❌ `assertJsonStructure([...])` as the only check on a response.
- ❌ Switching a service to its `NullDriver` to keep it out of a test. Mock its facade.
- ❌ An in-memory SQLite test database for an app that runs on MySQL, MariaDB or Postgres.

## Related

- [laravel-actions](../laravel-actions/SKILL.md) — testing an action and mocking the actions it composes
- [laravel-jobs](../laravel-jobs/SKILL.md) — job and listener tests
- [laravel-services](../laravel-services/SKILL.md) — driver tests with Saloon fixtures, facade mocks for callers
- [laravel-validation](../laravel-validation/SKILL.md) — validation dataset tests and `RequestDataProviderItem`
- [laravel-policies](../laravel-policies/SKILL.md) — policy unit tests
- [laravel-state-machines](../laravel-state-machines/SKILL.md) — transition tests
- [laravel-exceptions](../laravel-exceptions/SKILL.md) — asserting a domain exception and its message
- [laravel-dtos](../laravel-dtos/SKILL.md) — DTO test factories
- [laravel-models](../laravel-models/SKILL.md) — `#[UseFactory]` and model helpers
- [laravel-query-builders](../laravel-query-builders/SKILL.md) — the builder methods under test
- [laravel-quality](../laravel-quality/SKILL.md) — the architecture test, composer scripts and strict types
- [laravel-providers](../laravel-providers/SKILL.md) — `.env-testing` and the other env templates
