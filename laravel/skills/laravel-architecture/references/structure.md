# Directory structure

Follow the layout the project already has. New projects use flat `app/`; projects on the older `src/{App,Domain,Support}` layout keep it.

## Flat `app/`

Directories appear when their first class does; this is the full set, not a scaffold.

```
app/
├── Actions/{Entity}/
├── Booters/
├── Builders/
├── Casts/                        # custom casts, e.g. for value objects
├── Collections/                  # custom Eloquent collections
├── Console/Commands/
├── Data/
│   ├── Data.php                  # base DTO class
│   ├── Concerns/
│   └── Transformers/{Surface}/
├── Enums/
├── Events/
├── Exceptions/
├── Http/
│   ├── Controllers/Controller.php  # base controller for every surface
│   ├── Middleware/               # shared by more than one surface
│   ├── Web/                      # the app's own frontend, unversioned
│   │   ├── Controllers/
│   │   ├── Middleware/
│   │   ├── Queries/
│   │   ├── Requests/
│   │   └── Resources/
│   └── Api/V1/                   # a public API, versioned
│       ├── Controllers/
│       ├── Queries/
│       ├── Requests/
│       └── Resources/
├── Jobs/
├── Listeners/
├── Models/
│   └── Concerns/
├── Policies/
├── Providers/
├── Rules/
├── Services/{Name}/
├── States/{Model}/
│   └── Transitions/
├── Values/
└── helpers.php
database/factories/Data/          # DTO test factories, beside the model factories
routes/
├── web.php
└── api_v1.php                    # one file per surface
tests/
├── Architecture/
├── Concerns/                     # test traits and helpers
├── Datasets/
├── Feature/{Surface}/            # mirrors Http/{Surface}
└── Unit/{Kind}/                  # mirrors app/: Unit/Actions/Order/, Unit/Models/, …
```

- **Actions group by entity and states by model.** The other kinds stay flat.
- **A surface holds only the kinds it uses.** One with no listings has no `Queries/`. A second public API is a sibling surface (`Http/Partner/V1/`) with its own route file, transformers and feature-test folder.
- **Services keep their internals in their own folder.** Their inner shape is in [laravel-services](../../laravel-services/SKILL.md).
- **Tenancy.** A tenant-aware app's layout follows [laravel-multi-tenancy](../../laravel-multi-tenancy/SKILL.md).

## Older `src/{App,Domain,Support}` layout

Three PSR-4 roots in `composer.json`: `App\` → `src/App/`, `Domain\` → `src/Domain/`, `Support\` → `src/Support/`. A custom `App\Application` points the app path at `src/App`, so `make:` commands generate into `src/App` with an `App\` namespace. Move anything that isn't HTTP or console to its home below and fix its namespace.

| Kind of class | Flat `app/` | `src/{App,Domain,Support}` |
|---|---|---|
| Actions, builders, data, enums, events, exceptions, jobs, listeners, models, policies, states | `app/{Kind}/` | `src/Domain/{Context}/{Kind}/` |
| Value objects | `app/Values/` | `src/Domain/{Context}/ValueObjects/` |
| Controllers, requests, resources, queries | `app/Http/{Surface}/{Kind}/` | `src/App/Http/{Context}/{Kind}/` |
| Commands | `app/Console/Commands/` | `src/App/Console/Commands/` |
| Providers and booters | `app/Providers/`, `app/Booters/` | `src/Support/Providers/`, `src/Support/Booters/` |
| Services | `app/Services/{Name}/` | `src/Support/Services/{Name}/` |
| Base classes (`Data`, `Controller`, models) and classes shared across contexts | `app/{Kind}/` | `src/Support/{Kind}/` |
| Helpers | `app/helpers.php` | `src/Support/helpers.php` |
| Tests | `tests/Unit/{Kind}/`, `tests/Feature/{Surface}/` | `tests/Unit/{Context}/{Kind}/`, `tests/Feature/Http/{Context}/` |

A context is a slice of the domain (`Order`, `Billing`); its namespace reads `Domain\Order\Actions\CancelOrderAction`. A class that belongs to no single context lives in `Support`.
