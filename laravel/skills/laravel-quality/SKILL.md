---
name: laravel-quality
description: Pint, Rector, Pest type coverage and a debug-call arch test guard quality; strict types and return types everywhere but controllers. Use when setting up or running these tools, typing code, adding architecture tests, or weighing PHPStan.
---

# Laravel Quality

Four tools keep a codebase consistent: Pint formats it, Rector refactors and upgrades it, Pest type coverage reports what's untyped, and one architecture test keeps debug calls out of committed code. Write code that already satisfies them, so running them changes nothing. PHPStan isn't part of the default stack.

## Rules

- **`declare(strict_types=1)` in every PHP file.** App code, tests, config, migrations, routes. Pint's `declare_strict_types` adds it, but write it yourself so a file is right before Pint runs. Strict mode turns a silently coerced scalar into a `TypeError` at the call.
- **A return type on every method, function and closure, `void` included, except controller methods.** Controller methods omit them (see [laravel-controllers](../laravel-controllers/SKILL.md)). Type coverage counts every missing one.
- **Type every parameter and property.** Nullable is `?Order`, never `Order|null`.
- **Generics go in PHPDoc, only where a native type can't say it.** `/** @var Collection<int, OrderLineData> */` above a promoted property, `@return Collection<int, Order>`, `array<string, mixed>`. Never `Order[]`. Don't repeat a native type in a docblock: Rector's dead-code set deletes it.
- **One house pint.json**, [references/pint.json](references/pint.json). Every project formats the same way, so code moves between them without a style diff. Read it when creating or changing a project's Pint config; don't add per-project rules.
- **Rector is run by hand, not on commit.** It rewrites logic, so its diff gets reviewed: `composer rector-dry` to review, `composer rector` to apply, then `composer pint`. Read [references/rector.php](references/rector.php) when creating the config or upgrading PHP or Laravel; bump `LevelSetList::UP_TO_PHP_84` and `LaravelSetList::LARAVEL_120` to the project's versions, and point `withPaths()` at the project's source roots.
- **Format on commit, nothing else.** lint-staged runs `composer pint-dirty` on staged PHP files, so no commit carries style noise. The test suite runs on demand, not as a commit hook.
- **One architecture test: no `dd`, `dump` or `ray`.** Service providers are exempt: that's where local Ray debugging is wired (see [laravel-providers](../laravel-providers/SKILL.md)).
- **Framework glue is excluded from coverage.** Service providers and console commands carry a class-level `@codeCoverageIgnore` docblock, so coverage measures the code that holds logic.
- **No coverage or type-coverage minimum.** Both are reports to read, not gates: no `--min`.

## Example

`tests/Architecture/GeneralTest.php`:

```php
<?php

declare(strict_types=1);

test('will not use dd')
    ->expect('dd')
    ->not->toBeUsed()
    ->ignoring('App\Providers');

test('will not use dump')
    ->expect('dump')
    ->not->toBeUsed()
    ->ignoring('App\Providers');

test('will not use ray')
    ->expect('ray')
    ->not->toBeUsed()
    ->ignoring('App\Providers');
```

## Write it the way the tools would

| Tool and rule | Write |
|---|---|
| Pint `global_namespace_import`, `fully_qualified_strict_types` | Import every class, function and constant. No inline `\Foo\Bar`, no `\strlen()`. |
| Rector `LARAVEL_FACADE_ALIASES_TO_FULL_NAMES` | Facades from `Illuminate\Support\Facades\…`, never the root alias `\DB`. |
| Pint `strict_comparison` | `===` and `!==`. Write them yourself: Pint's rewrite of `==` can change behaviour. |
| Pint `no_useless_else`, `no_superfluous_elseif`; Rector `earlyReturn` | Return or throw early; no `else` after either. |
| Rector `deadCode` | No unused private methods, properties, parameters or variables. |
| Rector `InlineConstructorDefaultToPropertyRector` | A default value on the property declaration, not assigned in the constructor. |
| Pint `protected_to_private` | `private` unless a subclass needs the member. |
| Pint `ordered_class_elements` | Traits, enum cases, constants, properties, constructor, destructor, magic methods, abstract methods, then methods. Constants, properties and methods each run public → protected → private, static methods first. |
| Pint `self_accessor` | `self` inside a class, not its own name. |
| Pint `explicit_string_variable` | `"Order {$order->id}"`: braces around every interpolated variable. |
| Pint `mb_str_functions`, `modernize_types_casting`, `array_push` | `mb_strlen()`, `(int) $value`, `$items[] = $item`. |
| Pint `date_time_immutable` | `DateTimeImmutable`, never `DateTime`. |
| Rector skips `AddOverrideAttributeToOverriddenMethodsRector` | No `#[\Override]`. |
| Rector skips `ClosureToArrowFunctionRector` | An arrow function or a closure, whichever reads better; neither is forced. |

## Composer scripts

```json
"scripts": {
    "pint": "./vendor/bin/pint --parallel",
    "pint-dirty": "./vendor/bin/pint --dirty --parallel",
    "pint-sniff": "./vendor/bin/pint --test --parallel",
    "rector": "./vendor/bin/rector",
    "rector-dry": "./vendor/bin/rector --dry-run",
    "pest": ["Composer\\Config::disableProcessTimeout", "./vendor/bin/pest --compact"],
    "pest-dirty": ["Composer\\Config::disableProcessTimeout", "./vendor/bin/pest --dirty --compact"],
    "pest-parallel": ["Composer\\Config::disableProcessTimeout", "./vendor/bin/pest --parallel"],
    "pest-coverage": ["Composer\\Config::disableProcessTimeout", "./vendor/bin/pest --coverage"],
    "pest-type-coverage": ["Composer\\Config::disableProcessTimeout", "./vendor/bin/pest --type-coverage"]
}
```

And in `package.json`:

```json
"lint-staged": {
    "**/*.php": "composer pint-dirty"
}
```

## Reach for these when the situation calls for it

**Agents write most of the code.** Static analysis catches what a reviewer skims past. `phpstan/phpstan` is already installed, because Rector and the type-coverage plugin need it; add `larastan/larastan` and a `phpstan.neon`:

```neon
includes:
    - vendor/larastan/larastan/extension.neon

parameters:
    level: 5
    paths:
        - app
```

Level 5 is a starting point; raise it as the code allows. Expect false positives, such as dates cast in `casts()` read as strings, and Spatie model-state assignments rejected. Don't silence them with `@phpstan-ignore` comments, `@property` docblocks or a baseline. If the noise outweighs what it catches, remove Larastan rather than write code around it.

**A naming or shape convention keeps drifting.** Pin it with an arch test, one expectation per test:

```php
test('actions are named as actions')
    ->expect('App\Actions')
    ->classes()
    ->toHaveSuffix('Action');

test('actions are invokable')
    ->expect('App\Actions')
    ->classes()
    ->toBeInvokable();
```

`classes()` leaves out traits, interfaces and enums. In a chain, `classes()` filters only the next expectation and `ignoring()` only the one before it, so chained expectations silently check more than you meant. `toHaveMethod()` and `toBeInvokable()` use `ReflectionClass::hasMethod()`: inherited methods count, and there's no wildcard. `toOnlyBeUsedIn()` takes one string or array.

## Anti-patterns

- ❌ `expect('App\Models')->toHaveMethod('newEloquentBuilder')`: every model inherits it, so it always passes.
- ❌ `->not->toHaveMethod('scope*')`: there's no wildcard, so it always passes.
- ❌ `->toOnlyBeUsedIn('App\Http', 'App\Jobs')`: only the first argument counts. Pass an array.
- ❌ `checkMissingIterableValueType` or `checkGenericClassInNonGenericObjectType` in `phpstan.neon`: PHPStan 2 removed both and refuses the config.
- ❌ `includes: vendor/nunomaduro/larastan/extension.neon`: Larastan 3 lives at `vendor/larastan/larastan`. The old path aborts PHPStan before it analyses anything.
- ❌ `--coverage --min=80`, or any coverage gate.
- ❌ An arch test for strict types: Pint already adds the declaration.
- ❌ `public function find(int $id): Order|null`, or `@var Order[]`.

## Related

- [laravel-controllers](../laravel-controllers/SKILL.md): controller methods omit return types
- [laravel-providers](../laravel-providers/SKILL.md): Ray debugging, model defaults and `CarbonImmutable` dates
- [laravel-testing](../laravel-testing/SKILL.md): test style and helpers
