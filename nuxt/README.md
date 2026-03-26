# Nuxt Skills

Opinionated Nuxt 4 + Vue 3 development patterns refined across multiple production applications.

**These are opinionated.** They represent a domain-driven, type-safe, composable-first architecture — featuring the feature module pattern (queries/mutations/actions), model hydration with casting, and a clear separation between data and presentation layers. They won't be for everyone, and that's okay.

**This is a work in progress.** As I use these skills in real projects, I'm continuously refining them. Expect updates as patterns evolve and edge cases reveal themselves.

## Installation

**agntc** (recommended):
```bash
npx agntc@latest add leeovery/agentic-skills/nuxt
```

**Vercel Skills CLI:**
```bash
npx skills add leeovery/agentic-skills/nuxt
```

## Skills

### Foundation

| Skill | Description |
|-------|-------------|
| [**nuxt-architecture**](skills/nuxt-architecture/) | Project structure, philosophy, technology stack, and pattern selection |
| [**nuxt-layers**](skills/nuxt-layers/) | Working with base, nuxt-ui, and x-ui shared layers |

### Core Patterns

| Skill | Description |
|-------|-------------|
| [**nuxt-models**](skills/nuxt-models/) | Domain models with hydration, relations, casts, and value objects |
| [**nuxt-enums**](skills/nuxt-enums/) | Class-based enums with Castable interface and behavior methods |
| [**nuxt-repositories**](skills/nuxt-repositories/) | Data access layer with BaseRepository and model hydration |

### Feature Layer

| Skill | Description |
|-------|-------------|
| [**nuxt-features**](skills/nuxt-features/) | Feature module pattern — queries, mutations, and actions |

### UI Layer

| Skill | Description |
|-------|-------------|
| [**nuxt-components**](skills/nuxt-components/) | Component patterns with script setup order convention |
| [**nuxt-pages**](skills/nuxt-pages/) | File-based routing with list/detail page patterns |
| [**nuxt-composables**](skills/nuxt-composables/) | Core composables — useWait, useFlash, useReactiveFilters, and more |
| [**nuxt-forms**](skills/nuxt-forms/) | Form handling with XForm component and validation |
| [**nuxt-tables**](skills/nuxt-tables/) | Column builder pattern and XTable component |

### Infrastructure

| Skill | Description |
|-------|-------------|
| [**nuxt-config**](skills/nuxt-config/) | Configuration with nuxt.config.ts, app.config.ts, and environment variables |
| [**nuxt-auth**](skills/nuxt-auth/) | Laravel Sanctum authentication and permission-based authorization |
| [**nuxt-realtime**](skills/nuxt-realtime/) | Real-time features with Laravel Echo and WebSockets |
| [**nuxt-errors**](skills/nuxt-errors/) | Error handling with typed error classes and interceptors |
