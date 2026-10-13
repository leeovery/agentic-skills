<div align="center">

# ⛰️ Nuxt Skills

**Opinionated Nuxt patterns for AI coding agents**

Models, features, components, forms, auth, server data and testing,
<br>refined across multiple production applications.

[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](../LICENSE)
[![Nuxt 4](https://img.shields.io/badge/Nuxt-4-00DC82.svg)](https://nuxt.com)
[![Vue 3](https://img.shields.io/badge/Vue-3-4FC08D.svg)](https://vuejs.org)
[![Tailwind CSS 4](https://img.shields.io/badge/Tailwind%20CSS-4-06B6D4.svg)](https://tailwindcss.com)

[Installation](#installation) · [Skills](#skills) · [All plugins](../README.md)

</div>

---

**These are opinionated.** They represent a domain-driven, type-safe, composable-first architecture — featuring the feature module pattern (queries/mutations/actions), model hydration with casting, and a clear separation between data and presentation layers. They won't be for everyone, and that's okay.

**This is a work in progress.** As I use these skills in real projects, I'm continuously refining them. Expect updates as patterns evolve and edge cases reveal themselves.

## Installation

### agntc (recommended)

```bash
npx agntc@latest add leeovery/agentic-skills/nuxt
```

Remove it with `npx agntc@latest remove leeovery/agentic-skills/nuxt`.

### Vercel Skills CLI

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
| [**nuxt-design-tokens**](skills/nuxt-design-tokens/) | Design tokens and theming with Tailwind 4 `@theme static` and Nuxt UI semantic classes |

### Infrastructure

| Skill | Description |
|-------|-------------|
| [**nuxt-config**](skills/nuxt-config/) | Configuration with nuxt.config.ts, app.config.ts, and environment variables |
| [**nuxt-auth**](skills/nuxt-auth/) | Laravel Sanctum authentication and permission-based authorization |
| [**nuxt-realtime**](skills/nuxt-realtime/) | Real-time features with Laravel Echo and WebSockets |
| [**nuxt-errors**](skills/nuxt-errors/) | Error handling with typed error classes and interceptors |
| [**nuxt-server-data**](skills/nuxt-server-data/) | Server-side data layer with Drizzle ORM, Cloudflare D1 and NuxtHub |

### Quality

| Skill | Description |
|-------|-------------|
| [**nuxt-testing**](skills/nuxt-testing/) | Unit, component, composable, API integration, and E2E testing — with Nitro test seams |
