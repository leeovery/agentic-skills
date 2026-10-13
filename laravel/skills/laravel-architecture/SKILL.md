---
name: laravel-architecture
description: Where each kind of class lives and which pattern does which job in a Laravel app; follow the project's layout, flat app/ for new ones. Use when adding a class or file, choosing the pattern for a job, or restructuring code.
---

# Laravel Architecture

This skill is the map. Every kind of class has one job and one home, and the skill that owns it says how to write it. Business logic lives in actions; everything around them (HTTP, queues, events, the console) gathers input, calls an action and hands back the result. The layout follows the project you're in: new projects use flat `app/`, and older projects keep the layout they have.

## Rules

- **Follow the project's existing layout.** Put a new class where its kind already lives. New projects use flat `app/`; projects on `src/{App,Domain,Support}` keep it (see [structure.md](references/structure.md)). Two layouts in one codebase means every class has two possible homes.
- **Business logic lives in actions.** Controllers, jobs, listeners, commands and the scheduler gather input and call one action. The operation then has one home whatever triggers it, and can be tested without its trigger.
- **Group by entity or subsystem, never by verb.** `Actions/Order/CancelOrderAction`, `States/Order/`. Everything about orders is in one place.
- **Mirror each HTTP surface wherever it appears.** A surface is the app's own frontend (`Web`, unversioned) or a public API (`Api/V1`, versioned). Each has its own `Http/{Surface}/` and route file, and its transformers and feature tests sit under the same name (`Data/Transformers/{Surface}/`, `tests/Feature/{Surface}/`), so a surface can change, or gain a new version, without touching another.
- **Typed data between layers.** Models, DTOs, enums and value objects cross boundaries. Request arrays never leave the HTTP layer.
- **Declarative code.** Short methods named in the domain's language. When a block needs a comment to say what it does, extract it into a method whose name says it. Comments are for why.
- **`resolve()`, never `app()`.** One way to reach the container, everywhere.

## Which pattern for which job

| Job | Reach for | Lives in (flat `app/`) | How to write it |
|---|---|---|---|
| A business operation: any write, or a read that computes, caches or calls a service | Action, `CancelOrderAction` | `Actions/{Entity}/` | [laravel-actions](../laravel-actions/SKILL.md) |
| Handle an HTTP request | Controller that calls one action | `Http/{Surface}/Controllers/` | [laravel-controllers](../laravel-controllers/SKILL.md) |
| A filterable, sortable, paginated listing | Query class | `Http/{Surface}/Queries/` | [laravel-controllers](../laravel-controllers/SKILL.md) |
| Shape a JSON response | API resource | `Http/{Surface}/Resources/` | [laravel-controllers](../laravel-controllers/SKILL.md) |
| Validate input | Form request | `Http/{Surface}/Requests/` | [laravel-validation](../laravel-validation/SKILL.md) |
| Route a surface, bind models, authorize | One route file per surface | `routes/{surface}.php` | [laravel-routing](../laravel-routing/SKILL.md) |
| Carry typed data between layers | DTO and its transformer | `Data/`, `Data/Transformers/{Surface}/` | [laravel-dtos](../laravel-dtos/SKILL.md) |
| Persistence, relationships, casts | Model | `Models/` | [laravel-models](../laravel-models/SKILL.md) |
| Reusable query constraints | Custom builder, `OrderBuilder` | `Builders/` | [laravel-query-builders](../laravel-query-builders/SKILL.md) |
| A fixed set of values | Backed enum | `Enums/` | [laravel-enums](../laravel-enums/SKILL.md) |
| A status whose transitions have rules or side effects | Model states | `States/{Model}/` | [laravel-state-machines](../laravel-state-machines/SKILL.md) |
| A domain value with behaviour | Value object | `Values/` | [laravel-value-objects](../laravel-value-objects/SKILL.md) |
| A third-party API, or an implementation that swaps | Service behind a facade | `Services/{Name}/` | [laravel-services](../laravel-services/SKILL.md) |
| Work off the request, or a reaction to something that happened | Job, or event and listener, calling an action | `Jobs/`, `Events/`, `Listeners/` | [laravel-jobs](../laravel-jobs/SKILL.md) |
| Who may do what | Policy | `Policies/` | [laravel-policies](../laravel-policies/SKILL.md) |
| A business rule that failed | One exception per entity, named constructors | `Exceptions/` | [laravel-exceptions](../laravel-exceptions/SKILL.md) |
| Middleware, exception handling, schedule, model defaults | Booters and providers | `Booters/`, `Providers/` | [laravel-providers](../laravel-providers/SKILL.md) |
| Self-contained infrastructure worth reusing | Package | its own repository | [laravel-packages](../laravel-packages/SKILL.md) |

A multi-step process is actions composed in one call, or a chain or batch of jobs when its steps need their own retries or progress ([laravel-jobs](../laravel-jobs/SKILL.md)). Which fits depends on the project.

Read [structure.md](references/structure.md) when creating a directory, placing a class whose home isn't in the table, or working in a project on the `src/{App,Domain,Support}` layout.

## Example

Cancelling an order through the public API, in a flat `app/` project:

```
app/
├── Actions/Order/CancelOrderAction.php
├── Data/
│   ├── CancelOrderData.php
│   └── Transformers/Api/V1/CancelOrderDataTransformer.php
├── Exceptions/OrderException.php
├── Http/Api/V1/
│   ├── Controllers/CancelOrderController.php
│   ├── Requests/CancelOrderRequest.php
│   └── Resources/OrderResource.php
└── States/Order/
    ├── OrderCancelled.php
    └── Transitions/PaidToCancelled.php
routes/api_v1.php
tests/
├── Feature/Api/V1/CancelOrderTest.php
└── Unit/Actions/Order/CancelOrderActionTest.php
```

The controller, request, resource, transformer, route file and feature test belong to the `Api/V1` surface. The action, DTO, exception and states belong to the domain, and any surface can reuse them.

## Reach for these when the situation calls for it

**A public API needs a breaking change.** Add the next version as a surface beside the old one: `Http/Api/V2/`, `routes/api_v2.php`, `Data/Transformers/Api/V2/`, `tests/Feature/Api/V2/`. V1 stays as it is until its consumers move. Actions, DTOs and models are shared by both.

**A class serves several surfaces.** Middleware or query filters used by more than one surface sit at `Http/Middleware/` or `Http/Queries/Filters/`, outside any surface. Surface-only middleware goes in `Http/{Surface}/Middleware/`.

## Anti-patterns

- ❌ Moving an existing project to a different layout, or adding flat `app/` classes to a `src/Domain` project.
- ❌ Scaffolding the full directory tree up front. A directory appears when its first class does.
- ❌ Grouping by verb: `Actions/Create/`, `Actions/Update/`.
- ❌ Business logic in a controller, job, listener, command or model.
- ❌ One controller serving two surfaces.
- ❌ A public API with no version.
- ❌ An array of request data passed beyond the controller.
- ❌ `app(CancelOrderAction::class)`.

## Related

- [laravel-quality](../laravel-quality/SKILL.md) — strict types, Pint, Rector and the arch tests that hold these lines
- [laravel-testing](../laravel-testing/SKILL.md) — what goes in `tests/Unit` and `tests/Feature`
- [laravel-multi-tenancy](../laravel-multi-tenancy/SKILL.md) — layout and boundaries in a tenant-aware app
- The skills in the table above, one per pattern
