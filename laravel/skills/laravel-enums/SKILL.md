---
name: laravel-enums
description: Backed enums for every fixed set of values, owning their own behaviour; per-case labels and mapped values come from case attributes. Use when creating or changing an enum, labelling its cases, comparing enum values, or choosing between an enum and a state machine.
---

# Laravel Enums

An enum is the one definition of a fixed set of values: a status, a type, a queue name, a permission. Anything the code needs to know about a case — its label, how it behaves, what an external system calls it — is answered by the enum, so call sites ask the case instead of switching on it.

## Rules

- **Backed, whenever the value leaves PHP.** An enum that's persisted, validated, serialised or sent to a frontend is backed (`enum ShippingMethod: string`), so its stored form is explicit and stable. A pure enum is fine only for an internal discriminator that never leaves the process, such as the outcome type inside a result object.
- **PascalCase cases, kebab-case string values.** `case NextDay = 'next-day'`. When an external system dictates the values, use its values verbatim and keep the case names PascalCase. Permission values are the one exception, `'{verb} {subject}'` ([laravel-policies](../laravel-policies/SKILL.md)).
- **Every fixed identifier is an enum.** Queue names, permissions, flash message types, rate-limiter names. Pass `->value` where an API wants a string. A typo becomes an error, and every use is one "find usages" away.
- **Named for what it is, in `app/Enums/`.** `OrderStatus`, `ShippingMethod`; no `Enum` suffix.
- **Behaviour lives on the enum.** A method on the enum answers a question about a case (`$method->requiresAddress()`), never a `match` or `switch` at the call site. Otherwise every caller repeats the mapping, and a new case has to be hunted down in all of them.
- **Instance methods, not statics taking `self`.** `$period->dateRange()`, not `Period::toDateRange($period)`.
- **Exhaustive `match`, no `default` arm.** A new case then fails loudly with `UnhandledMatchError` in the first test that hits it, instead of silently taking the default.
- **The display label is `label()`.** Not `getLabel()`. Other per-case values follow suit: `remoteValue()`, not `getRemoteValue()`.
- **Predicates are real methods.** A case callers often ask about gets one written out (`isClickAndCollect()`), never left to a magic `__call`, so static analysis sees it.
- **A status with transition rules or side effects is a state machine, not an enum.** When moving from one value to another has preconditions, or triggers work, use a state machine ([laravel-state-machines](../laravel-state-machines/SKILL.md)). An enum fits a status whose values can be set freely. On an event-sourced aggregate the state is an enum, because the aggregate is the state machine ([laravel-event-sourcing](../laravel-event-sourcing/SKILL.md)).

## Example

```php
<?php

declare(strict_types=1);

namespace App\Enums;

use Carbon\CarbonImmutable;

enum ShippingMethod: string
{
    case Standard = 'standard';
    case NextDay = 'next-day';
    case ClickAndCollect = 'click-and-collect';

    public function isClickAndCollect(): bool
    {
        return $this === self::ClickAndCollect;
    }

    public function requiresAddress(): bool
    {
        return ! $this->isClickAndCollect();
    }

    public function estimatedDelivery(CarbonImmutable $dispatchedAt): CarbonImmutable
    {
        return $dispatchedAt->addWeekdays(match ($this) {
            self::Standard => 3,
            self::NextDay => 1,
            self::ClickAndCollect => 0,
        });
    }
}
```

## Reach for these when the situation calls for it

**When cases carry a label or a mapped value** (a display name, an external API's code, a related enum), put it on the case as an attribute and read it with a `Has{X}` trait. The value sits next to the case it belongs to, and the trait gives every enum the same accessor. One attribute class per kind in `app/Enums/Attributes/`, one trait per kind in `app/Enums/Concerns/`:

```php
<?php

declare(strict_types=1);

namespace App\Enums\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
class Label
{
    public function __construct(public string $value) {}
}
```

```php
<?php

declare(strict_types=1);

namespace App\Enums\Concerns;

use ReflectionEnumUnitCase;

trait ReadsCaseAttributes
{
    /**
     * @template T of object
     *
     * @param  class-string<T>  $attribute
     * @return T|null
     */
    protected function caseAttribute(string $attribute): ?object
    {
        $attributes = (new ReflectionEnumUnitCase($this, $this->name))->getAttributes($attribute);

        return ($attributes[0] ?? null)?->newInstance();
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Enums\Concerns;

use App\Enums\Attributes\Label;

trait HasLabel
{
    use ReadsCaseAttributes;

    public function label(): string
    {
        return $this->caseAttribute(Label::class)?->value ?? $this->name;
    }
}
```

```php
enum ShippingMethod: string
{
    use HasLabel, HasRemoteValue;

    #[Label('Next day'), RemoteValue('NXT')]
    case NextDay = 'next-day';
}
```

`HasRemoteValue` has the same shape as `HasLabel`. An attribute can hold another enum, read the same way: `#[DefaultCarrier(Carrier::Dpd)]` and `HasDefaultCarrier::defaultCarrier()`.

**When the project has henzeb/enumhancer** (or an enum needs comparison helpers or select options), opt in per enum with its concerns:

- `Comparison`: `$method->is(ShippingMethod::NextDay)`, `->isNot()`, `->isIn(...)`, `->isNotIn(...)`. Prefer them to `===` wherever the enum has them. They also accept a raw value or case name (`->is('next-day')`). It also answers magic `is{Case}()` calls; write the predicates you use as real methods instead.
- `Dropdown`: `ShippingMethod::dropdown()` returns `[value => label]` for a select or a CLI prompt.
- `Attributes`: its `getAttribute()` replaces `ReadsCaseAttributes`.

To feed `dropdown()` your attribute labels, `HasLabel` also uses enumhancer's `Labels` and `Dropdown`, and overrides `labels()` keyed by **case name**. `dropdown()` looks each label up by `$case->name`. Key the map by `->value` and it silently falls back to showing case names.

```php
trait HasLabel
{
    use Dropdown, Labels, ReadsCaseAttributes;

    /** @return array<string, string> */
    public static function labels(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case): array => [$case->name => $case->label()])
            ->all();
    }

    public function label(): string
    {
        return $this->caseAttribute(Label::class)?->value ?? $this->name;
    }
}
```

If the project has its own enum concerns, use their API.

## Anti-patterns

- ❌ `match ($order->shipping_method) { … }` in an action, controller or view to derive something about a case. Add a method to the enum.
- ❌ A `label()` built from a `match` over every case once cases carry labels. Use `#[Label]` on the case.
- ❌ `getLabel()`, `getRemoteValue()`.
- ❌ A `labels()` map keyed by `->value` alongside enumhancer's `dropdown()`.
- ❌ `default =>` in a `match` over the enum's own cases.
- ❌ Presentation methods (`color()`, `icon()`) that nothing calls yet.
- ❌ `$order->status->value === 'paid'`. Compare cases, not strings.
- ❌ A status enum plus guards in actions checking which value it may move to next. That's a state machine.
- ❌ A string literal for a queue, permission or flash type.

## Related

- [laravel-state-machines](../laravel-state-machines/SKILL.md) — statuses whose transitions have rules or side effects
- [laravel-models](../laravel-models/SKILL.md) — casting enum columns
- [laravel-jobs](../laravel-jobs/SKILL.md) — the queue-name enum
- [laravel-validation](../laravel-validation/SKILL.md) — validating enum input
- [laravel-dtos](../laravel-dtos/SKILL.md) — enum properties, built with `$request->enum()`
- [laravel-event-sourcing](../laravel-event-sourcing/SKILL.md) — state enums on an event-sourced aggregate
