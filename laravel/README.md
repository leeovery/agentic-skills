<div align="center">

# 🧱 Laravel Skills

**Opinionated Laravel patterns for AI coding agents**

Architecture, data, the HTTP layer, jobs, testing and tenancy,
<br>refined over 20 years in the industry and 10+ years with Laravel.

[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](../LICENSE)
[![Laravel 12 | 13](https://img.shields.io/badge/Laravel-12%20%7C%2013-FF2D20.svg)](https://laravel.com)
[![Tests: Pest](https://img.shields.io/badge/tests-Pest-F472B6.svg)](https://pestphp.com)

[Installation](#installation) · [Skills](#skills) · [All plugins](../README.md)

</div>

---

**These are opinionated.** They represent how I build Laravel applications — action-oriented architecture, strict typing, explicit DTOs, and a relentless focus on maintainability. They won't be for everyone, and that's okay.

**This is a work in progress.** As I use these skills in real projects, I'm continuously refining them. Expect updates as patterns evolve and edge cases reveal themselves.

Each skill states its rules with the reason behind them, and keeps situational techniques in a "reach for these when the situation calls for it" section, so an agent applies them only when that situation arises.

## Installation

### agntc (recommended)

```bash
npx agntc@latest add leeovery/agentic-skills/laravel
```

Remove it with `npx agntc@latest remove leeovery/agentic-skills/laravel`.

### Vercel Skills CLI

```bash
npx skills add leeovery/agentic-skills/laravel
```

## Skills

### Foundation

| Skill | Description |
|-------|-------------|
| [**laravel-architecture**](skills/laravel-architecture/) | The map: which kind of class does which job, and where it lives |
| [**laravel-quality**](skills/laravel-quality/) | Strict types, Pint, Rector, Pest type coverage and a debug-call arch test; PHPStan when it earns its keep |

### Core Patterns

| Skill | Description |
|-------|-------------|
| [**laravel-actions**](skills/laravel-actions/) | Every domain operation is an invokable action; entry points only call one |
| [**laravel-dtos**](skills/laravel-dtos/) | spatie/laravel-data DTOs filled by explicit, typed mapping, usually through transformers |
| [**laravel-enums**](skills/laravel-enums/) | Backed enums for every fixed set of values, with behaviour on the case |
| [**laravel-value-objects**](skills/laravel-value-objects/) | Immutable value objects with their own casts |
| [**laravel-state-machines**](skills/laravel-state-machines/) | spatie/laravel-model-states for statuses whose transitions have rules or side effects |

### HTTP Layer

| Skill | Description |
|-------|-------------|
| [**laravel-controllers**](skills/laravel-controllers/) | Controllers that call one action and return JSON resources; index query classes |
| [**laravel-validation**](skills/laravel-validation/) | Form Requests that only validate, tested with endpoint datasets |
| [**laravel-routing**](skills/laravel-routing/) | A route file per surface, explicit named routes, public-id binding, `->can()` on every route |

### Data Layer

| Skill | Description |
|-------|-------------|
| [**laravel-models**](skills/laravel-models/) | Model structure, attributes, casts, relationships and public ids |
| [**laravel-query-builders**](skills/laravel-query-builders/) | A custom Eloquent builder per model for every reusable query |

### Infrastructure

| Skill | Description |
|-------|-------------|
| [**laravel-providers**](skills/laravel-providers/) | Named provider methods, booters, model defaults, helpers and env templates |
| [**laravel-jobs**](skills/laravel-jobs/) | Jobs, events and queued listeners as thin wrappers around actions |
| [**laravel-policies**](skills/laravel-policies/) | Policies that answer from permissions and ownership, never state |
| [**laravel-services**](skills/laravel-services/) | Third-party integrations as a manager, drivers, Saloon, a facade and a deferred provider |
| [**laravel-exceptions**](skills/laravel-exceptions/) | Domain exceptions with named factories, their status codes and reporting |

### Enterprise

| Skill | Description |
|-------|-------------|
| [**laravel-multi-tenancy**](skills/laravel-multi-tenancy/) | Multi-tenant apps on stancl/tenancy v4 |
| [**laravel-packages**](skills/laravel-packages/) | Extracting infrastructure into a package with package-tools, a shipped fake and Testbench |

### Testing

| Skill | Description |
|-------|-------------|
| [**laravel-testing**](skills/laravel-testing/) | Pest style, HTTP feature tests, factories, fakes and the test environment |
