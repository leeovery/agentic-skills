# Architectural Patterns

Overview of all architectural patterns used in the Laravel architecture.

**Related guides:**
- [decisions.md](decisions.md) - When to use which pattern
- [structure.md](structure.md) - Where patterns live in directory structure

## Core Patterns (Always Created)

### Action Pattern
- Invokable classes with single responsibility
- All domain logic lives here
- Accept DTOs, return typed results
- Use DB transactions for mutations
- Compose with other actions

**Location:** `app/Actions/`

**Naming:** `{Verb}{Entity}Action`

**See [Actions](../../laravel-actions/SKILL.md) for complete guide**

### Data Transfer Objects
- Extend base `Data` class (Spatie)
- Strict typing with promoted properties
- Include test factory trait
- Apply formatters in constructor
- Never pass multiple primitives

**Location:** `app/Data/`

**Naming:** `{Entity}Data` or `{Action}{Entity}Data`

**See [DTOs](../../laravel-dtos/SKILL.md) for complete guide**

### Strict Typing
- Every file starts with `declare(strict_types=1)`
- All parameters typed
- All return types declared
- Property types required
- PHPDoc for complex types (collections, arrays)

### Thin HTTP Layer
- Controllers delegate to actions (zero domain logic)
- Form Requests handle validation
- Transformers convert requests to DTOs
- Resources format responses
- Queries handle complex filtering/sorting

**Controllers:** HTTP only - See [Controllers](../../laravel-controllers/SKILL.md)
**Jobs/Listeners:** Delegate to actions
**Actions:** Domain logic - See [Actions](../../laravel-actions/SKILL.md)
**Validation:** Form Requests - See [form-requests.md](../../laravel-validation/references/form-requests.md)

### Custom Query Builders
- Better type hints than scopes
- Reusable query methods
- Chainable
- Type-safe

**Location:** `app/Builders/`

**Naming:** `{Entity}Builder`

**See [Models](../../laravel-models/SKILL.md) for custom builder implementation**

## Optional Patterns

### State Machines
**When:** Complex state transitions

Uses Spatie Model States for dedicated state classes with transition logic.

**Location:** `app/States/{Model}/`

### Service Layer
**When:** External API integrations

Manager class with drivers and contracts pattern.

**Location:** `app/Services/{ServiceName}/`

### Value Objects
**When:** Complex domain values with behavior

Immutable objects representing domain concepts.

**Location:** `app/Values/`

### Workflows
**When:** Multi-step business processes

Orchestrates multiple actions into defined workflows.

**Location:** `app/Workflows/{WorkflowName}/`

## Key Principles

1. **Single Responsibility** - Each class does one thing
2. **Separation of Concerns** - Domain in actions, HTTP in controllers
3. **Composability** - Small classes compose together
4. **Type Safety First** - Strong typing throughout
5. **Data Objects Over Primitives** - Always use DTOs
6. **Explicit Over Implicit** - Clear, verbose naming
7. **Testability** - Every layer independently testable
8. **Declarative, Readable, Beautiful Code** - Code should be a pleasure to read

## Data Flow

```
HTTP Request
    ↓
Form Request (validates)
    ↓
Transformer (converts to DTO)
    ↓
Controller (thin orchestration)
    ↓
Action (domain logic)
    ↓
Model/External Service
    ↓
Response Resource (formats output)
    ↓
HTTP Response
```
