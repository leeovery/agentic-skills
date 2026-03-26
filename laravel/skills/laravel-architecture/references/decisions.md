# Architectural Decision Guide

Guidance for choosing the right architectural pattern for your use case.

**Related guides:**
- [patterns.md](patterns.md) - Overview of all patterns
- [structure.md](structure.md) - Where to place code

## When to Use Which Pattern

### Actions

**Use when:**
- Any domain logic operation (including simple CRUD)
- Multi-step business processes
- Need to compose operations
- Jobs/listeners need to perform work
- Any operation touching models/data

**Examples:**
- `CreateOrderAction`
- `ProcessPaymentAction`
- `SendNotificationAction`
- `CalculateShippingCostAction`

**Don't use for:**
- Pure data retrieval for display (use queries)
- HTTP concerns (use middleware)
- Presentation logic (use resources)

**See [Actions](../../laravel-actions/SKILL.md) for complete implementation guide**

### Data Transfer Objects (DTOs)

**Use when:**
- Passing data between layers
- Request validation result needs structure
- Multiple primitive values would be passed
- Need test factories for data
- Storing complex data in JSON columns

**Examples:**
- `CreateOrderData` - Request input
- `OrderMetadataData` - JSON column cast
- `PaymentResponseData` - External API response

**Always over:**
- Multiple method parameters
- Arrays with string keys
- Passing request objects to actions

**See [DTOs](../../laravel-dtos/SKILL.md) for complete implementation guide**

### Custom Query Builders

**Use when:**
- Reusable query logic needed
- Want better IDE support than scopes
- Complex filtering/joins
- Query composition

**Create methods for:**
- Status filtering: `pending()`, `completed()`
- Relationship queries: `forUser(User $user)`
- Date ranges: `createdBetween($start, $end)`
- Search: `search(string $term)`

**Don't use scopes because:**
- Poor type hints
- Less IDE support
- Harder to compose

**See [Models](../../laravel-models/SKILL.md) for custom builder implementation**

### Query Objects (Spatie Query Builder)

**Use when:**
- API filtering/sorting needed
- Complex query configuration for endpoints
- Multiple allowed filters/sorts/includes

**Example:**
- `OrderIndexQuery` - For `/api/orders` with ?filter, ?sort, ?include

**Don't use for:**
- Simple queries (use builder methods)
- Internal queries (use builder)

### State Machines

**Use when:**
- Complex state transitions with rules
- State-specific behavior needed
- Transition history required
- Guards/validations per transition

**Examples:**
- Order states: Pending → Processing → Shipped → Delivered
- Application states: Draft → Submitted → Review → Approved/Rejected

**Don't use for:**
- Simple status fields
- Binary states (use enum)
- No transition logic

### Service Layer

**Use when:**
- Integrating external APIs
- Multiple drivers for same service (email, payment)
- Complex service configuration
- Need to swap implementations

**Pattern:**
```
Services/
└── Payment/
    ├── PaymentManager.php
    ├── Drivers/          # Stripe, PayPal
    └── Contracts/
```

**Don't use for:**
- Internal business logic (use actions)
- Simple HTTP calls (use Laravel's HTTP facade directly)

### Value Objects

**Use when:**
- Complex domain value with behavior
- Immutability required
- Rich validation logic
- Need equality comparison

**Examples:**
- `Money` - Amount + currency
- `EmailAddress` - Validation + formatting
- `Coordinate` - Lat/long with distance calculations

**Don't use for:**
- Simple scalars
- Data without behavior (use DTO)

### Workflows

**Use when:**
- Long-running multi-step processes
- Steps can be paused/resumed
- Branching logic
- Error recovery needed

**Examples:**
- Onboarding workflow
- Order fulfillment workflow
- Document approval workflow

**Don't use for:**
- Simple action composition
- Synchronous operations

### Repository Pattern

**Use sparingly:**
- Most cases don't need repositories
- Actions + models + builders cover most needs

**Only use when:**
- Swapping data sources (rare)
- Complex query abstraction needed
- Legacy code migration

## Layer Decisions

### Controller vs Action

**Controller (HTTP only):**
- Validate (Form Request)
- Transform (Transformer)
- Call action
- Return response

**See [Controllers](../../laravel-controllers/SKILL.md) for controller patterns**

**Action (domain logic):**
- Business rules
- Data manipulation
- External calls
- Transactions

**See [Actions](../../laravel-actions/SKILL.md) for action patterns**

### Job vs Listener vs Action

**Job:**
- Queue configuration
- Retry logic
- Delegates to action

**Listener:**
- Responds to events
- Queue configuration
- Delegates to action

**Action:**
- Actual work performed
- Both Job and Listener call same action

### Request vs Validator

**Form Request (always prefer):**
- HTTP-specific validation
- Type-hinted in controller
- Auto-validation

**See [form-requests.md](../../laravel-validation/references/form-requests.md) for validation patterns**

**Validator class:**
- Reusable across contexts
- Complex validation logic
- Not tied to HTTP

## Public API vs Web Layer

**Public API (`routes/api/v*.php`):**
- External consumers
- Versioned
- Stable contract
- Breaking changes = new version

**Web Layer (`routes/web.php`):**
- Your own frontend
- Not versioned
- Can change freely
- Private contract

**See [structure.md](structure.md) and [Controllers](../../laravel-controllers/SKILL.md) for routing details**

## Namespace Organization

**By feature:**
```
Actions/
├── Order/
│   ├── CreateOrderAction
│   └── CancelOrderAction
└── User/
```

## Testing Decisions

**Feature tests for:**
- HTTP endpoints
- Complete workflows
- Integration points
- Anything touching the database
- Code using framework infrastructure

**Unit tests for:**
- Isolated pure PHP classes with no external dependencies
- Value objects (self-contained logic and behavior)
- Utility classes with calculations or transformations

**Don't test:**
- Framework code
- Third-party packages
- Simple getters/setters

## When to Extract Package

**Extract when:**
- Used across 3+ projects
- Stable interface
- Generic enough
- Well-tested

**Keep internal when:**
- Project-specific
- Changing frequently
- Coupled to domain

## Enum vs Constant

**Enum (prefer):**
- Typed
- Can have methods
- Can have attributes
- IDE support

**Constant (legacy):**
- Only if PHP < 8.1
- Simple values
