---
name: laravel-policies
description: Policies answer whether a user may act on a kind of record, from permissions and ownership only; state rules belong to action guards. Use when writing or changing a policy, a permission, an ability check, or how a policy is wired to its model.
---

# Laravel Policies

A policy answers one question: may this user do this to this kind of record? It answers from the user's permissions and, where it matters, whether they own the record. Whether the record is in a state that allows the operation is a different question. An order can't be cancelled once it has shipped, whoever asks; the action's guard enforces that and throws a domain exception that says why. Routes call policies, and a policy never does anything but answer.

## Rules

- **Permission and ownership only.** No state checks (`$order->isShipped()`, `canTransitionTo()`): they belong in the action's guard ([laravel-actions](../laravel-actions/SKILL.md)). A state rule in a policy fails with a bare "This action is unauthorized." and duplicates the guard the action needs anyway.
- **Ask the project's permission system, by enum.** `$user->can(Permission::CancelOrders)`, never a string. Laravel's Gate accepts enum abilities, and a permission system that hooks the Gate (spatie/laravel-permission does) answers them.
- **Permissions, never role names.** Roles are data an admin re-grants. `hasRole('manager')` in a policy hard-codes a grant that belongs in the role's permissions.
- **Ownership is a model method.** `$order->notOwnedBy($user)`, never `$order->user_id !== $user->id` in the policy. The model knows how it's owned, and every policy reads the same.
- **Early returns, ending on the permission.** A check with more than one condition is a run of `if (…) { return false; }`, closing on `return $user->can(...)`; not one long `&&` chain.
- **Return `bool`.** A denial needs no message: it's a 403. A rule that needs explaining is a state rule, and its exception explains it.
- **Abilities are named after what they protect.** Resource abilities keep Laravel's names (`viewAny`, `view`, `create`, `update`, `delete`). Any other ability is the verb of the action it guards: `cancel` for `CancelOrderAction`.
- **`viewAny` doesn't filter.** It says whether the user may list orders at all. Which orders they see is a constraint on the listing's query ([laravel-query-builders](../laravel-query-builders/SKILL.md)).
- **Permission cases are `{Verb}{Subject}`; values are `'{verb} {subject}'`,** subject kebab-cased: `CancelOrders = 'cancel orders'`, `ViewAllOrders = 'view all-orders'`. The space is the one departure from [laravel-enums](../laravel-enums/SKILL.md)' kebab-case values. A value is a stored permission name that roles are granted by, so it never changes once deployed.
- **Wire the policy with `#[UsePolicy]` on the model** (Laravel 12.18+; [laravel-models](../laravel-models/SKILL.md) owns the attribute). Below 12.18, auto-discovery finds `App\Policies\OrderPolicy` by name; a policy outside that convention is registered with `Gate::policy()` in a provider's `boot()`.
- **Routes call policies.** Every route on a surface with a permission model carries `->can()`, and nothing calls `authorize()`. [laravel-routing](../laravel-routing/SKILL.md) owns the rule.

## Example

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ViewOrders);
    }

    public function view(User $user, Order $order): bool
    {
        if ($order->notOwnedBy($user)) {
            return false;
        }

        return $user->can(Permission::ViewOrders);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::CreateOrders);
    }

    public function cancel(User $user, Order $order): bool
    {
        if ($order->notOwnedBy($user)) {
            return false;
        }

        return $user->can(Permission::CancelOrders);
    }
}
```

`cancel()` doesn't ask whether the order has shipped: `CancelOrderAction`'s guard does. The ownership helpers on `Order` compare through the relation, which needs no query:

```php
public function notOwnedBy(User $user): bool
{
    return ! $this->user()->is($user);
}
```

## Adding a permission

A new permission touches several places. Miss one and it works on a fresh database but fails where roles already exist.

1. The enum case.
2. The policy method or gate that asks for it.
3. The route's `->can()`.
4. The grant: the seeder that builds roles for a fresh database, and a migration or deploy step that creates the permission and grants it to roles in environments already running.
5. Anything the project generates from the enum, such as test fixtures or frontend constants.

## Reach for these when the situation calls for it

**When some users may act only on their own records and others on everyone's,** give the ability a pair of permissions, `UpdateOrders` and `UpdateAllOrders`, and let ownership pick which applies:

```php
public function update(User $user, Order $order): bool
{
    if ($order->notOwnedBy($user)) {
        return $user->can(Permission::UpdateAllOrders);
    }

    return $user->can(Permission::UpdateOrders);
}
```

**When an ability has no model behind it** (opening a reports area, exporting data), define it with `Gate::define()` in a service provider's `boot()` ([laravel-providers](../laravel-providers/SKILL.md)), named by an enum case and making the same kind of check a policy method makes.

## Testing

Resolve the policy and call the method, one test per outcome. Grant permissions however the project's factories or test helpers do.

```php
test('will deny viewing an order the user does not own', function (): void {
    $user = User::factory()->withPermissions(Permission::ViewOrders)->create();

    expect(resolve(OrderPolicy::class)->view($user, Order::factory()->create()))->toBeFalse();
});
```

That the route enforces the policy is an HTTP test (`cannot view another user's order`, asserting `assertForbidden()`); see [laravel-testing](../laravel-testing/SKILL.md).

## Anti-patterns

- ❌ A state check in a policy: `&& $order->isPending()`.
- ❌ A string ability or permission: `$user->can('cancel orders')`.
- ❌ `$user->hasRole(...)` in a policy.
- ❌ Raw key comparison for ownership: `$order->user_id === $user->id`.
- ❌ `$this->authorize()` in a controller, or an `authorize()` method on a Form Request.
- ❌ `use HandlesAuthorization`: a leftover from the old `make:policy` stub, needed only for `allow()`/`deny()`.
- ❌ Relying on `viewAny` to hide other users' records from a listing.
- ❌ Renaming a deployed permission's value without migrating its grants.

## Related

- [laravel-routing](../laravel-routing/SKILL.md) — `->can()` on routes, the only place policies are called
- [laravel-actions](../laravel-actions/SKILL.md) — guards that enforce state rules
- [laravel-models](../laravel-models/SKILL.md) — `#[UsePolicy]` and model helpers
- [laravel-enums](../laravel-enums/SKILL.md) — enums, including `Permission`
- [laravel-exceptions](../laravel-exceptions/SKILL.md) — the exceptions guards throw
- [laravel-testing](../laravel-testing/SKILL.md) — testing style and HTTP authorization tests
