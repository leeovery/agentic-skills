---
name: nuxt-testing
description: Testing strategy across unit, component, composable, API integration, and E2E layers. Use when adding tests, choosing the right tier for a piece of behaviour, setting up vitest or Playwright, or designing test seams for Nitro-side effects.
---

# Nuxt Testing

Pick the cheapest tier that gives a real signal. Real infrastructure or test seams over mocks. Test user-visible behaviour, not implementation details.

## Core Concepts

- **[unit-and-component.md](references/unit-and-component.md)** — vitest with `@nuxt/test-utils`, Testing Library, composable test helpers
- **[e2e-and-api.md](references/e2e-and-api.md)** — Playwright for UI + API integration via the `request` fixture
- **[test-seams.md](references/test-seams.md)** — `/api/_dev/*` endpoints and env-driven driver swaps for Nitro side effects

## Tier Decision

| Behaviour                              | Tier                          |
|----------------------------------------|-------------------------------|
| Pure logic (models, enums, utils)      | Unit (vitest)                 |
| Composable in isolation                | Unit (vitest + `withSetup`)   |
| Component DOM + user interaction       | Component (Testing Library)   |
| Server route, full request pipeline    | API integration (Playwright `request`) |
| Cross-step user journeys, real browser | E2E (Playwright `page`)       |

Prefer API integration over UI E2E for server-side pipelines — faster, more deterministic, same Playwright runner.

## Red Flags — STOP and Fix

- **`setTimeout()` in a test** → use `findBy*`, `waitFor()`, or `vi.waitFor()`
- **Asserting `wrapper.vm.someInternalRef`** → test what the user sees
- **Mocking a Nitro side effect by stubbing the module** → add a driver-swap seam instead (see [test-seams.md](references/test-seams.md))
- **`mount(Component, { props: { modelValue, 'onUpdate:modelValue': … } })`** → drive v-model from the user side: type in the field, assert the rendered value
- **Component test that fetches over the network** → MSW handler
- **E2E test that hits Stripe/Turnstile/Resend live** → provider test keys + memory driver
- **Snapshot of a whole component tree** → asserts noise; pick the 2-3 things that matter
- **`fullyParallel: true` with a shared in-memory test seam** → set `workers: 1` and clear state in `beforeEach`
- **"Just one mock to get this green"** → the seam is missing; fix the design, not the test

## Quick Setup

```ts
// vitest.config.ts
import { defineVitestConfig } from '@nuxt/test-utils/config'

export default defineVitestConfig({
  test: { environment: 'happy-dom' }
})
```

```ts
// playwright.config.ts — auto-spawn dev server, env-driven test mode
webServer: {
  command: 'npm run dev -- --port 3100',
  url: 'http://localhost:3100',
  reuseExistingServer: !process.env.CI,
  env: { NUXT_EMAIL_DRIVER: 'memory', /* provider test keys */ }
}
```

## When NOT to Use This Skill

- Pure backend-only repos (no Nuxt) — use vitest/Playwright docs directly
- Visual regression — out of scope; use a dedicated tool (Percy, Chromatic)
