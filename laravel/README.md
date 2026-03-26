# Laravel Skills

Opinionated Laravel development patterns and practices refined over **20 years in the industry** and **10+ years working with Laravel**.

**These are opinionated.** They represent how I build Laravel applications — action-oriented architecture, strict typing, DTOs everywhere, and a relentless focus on maintainability. They won't be for everyone, and that's okay.

**This is a work in progress.** As I use these skills in real projects, I'm continuously refining them. Expect updates as patterns evolve and edge cases reveal themselves.

## Installation

**agntc** (recommended):
```bash
npx agntc@latest add leeovery/agentic-skills/laravel
npx agntc@latest remove leeovery/agentic-skills/laravel
```

**Vercel Skills CLI:**
```bash
npx skills add leeovery/agentic-skills/laravel
```

## Skills

### Foundation

| Skill | Description |
|-------|-------------|
| [**laravel-architecture**](skills/laravel-architecture/) | High-level architecture decisions, patterns, and project structure |
| [**laravel-quality**](skills/laravel-quality/) | Code quality with PHPStan, Pint, and strict types |

### Core Patterns

| Skill | Description |
|-------|-------------|
| [**laravel-actions**](skills/laravel-actions/) | Action-oriented architecture — domain logic in invokable classes |
| [**laravel-dtos**](skills/laravel-dtos/) | Data Transfer Objects with Spatie Laravel Data |
| [**laravel-enums**](skills/laravel-enums/) | Backed enums with labels and business logic |
| [**laravel-value-objects**](skills/laravel-value-objects/) | Immutable value objects for domain values |
| [**laravel-state-machines**](skills/laravel-state-machines/) | Complex state management with Spatie Model States |

### HTTP Layer

| Skill | Description |
|-------|-------------|
| [**laravel-controllers**](skills/laravel-controllers/) | Thin HTTP layer — zero domain logic in controllers |
| [**laravel-validation**](skills/laravel-validation/) | Form requests and validation testing |
| [**laravel-routing**](skills/laravel-routing/) | Route model binding and authorization |

### Data Layer

| Skill | Description |
|-------|-------------|
| [**laravel-models**](skills/laravel-models/) | Eloquent models and relationships |
| [**laravel-query-builders**](skills/laravel-query-builders/) | Type-safe custom query builders |

### Infrastructure

| Skill | Description |
|-------|-------------|
| [**laravel-providers**](skills/laravel-providers/) | Service providers and bootstrapping |
| [**laravel-jobs**](skills/laravel-jobs/) | Background processing with jobs and listeners |
| [**laravel-policies**](skills/laravel-policies/) | Authorization policies |
| [**laravel-services**](skills/laravel-services/) | External API integration with Saloon |
| [**laravel-exceptions**](skills/laravel-exceptions/) | Custom exceptions and error handling |

### Enterprise

| Skill | Description |
|-------|-------------|
| [**laravel-multi-tenancy**](skills/laravel-multi-tenancy/) | Multi-tenant application patterns |
| [**laravel-packages**](skills/laravel-packages/) | Package extraction and development |

### Testing

| Skill | Description |
|-------|-------------|
| [**laravel-testing**](skills/laravel-testing/) | Comprehensive testing patterns with Pest |
