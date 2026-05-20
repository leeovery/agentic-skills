# Rendering Strategies (per-route)

Nuxt picks rendering mode per route via `routeRules` in `nuxt.config.ts`. Three modes you'll mix:

- **Prerender** — static HTML at build time
- **SSR** (default) — server-side render per request
- **SPA** (`ssr: false`) — client-side render only

The default is SSR. Override per route where another mode is cheaper or required.

## Decision table

| Page shape | Mode | Rule | Why |
| --- | --- | --- | --- |
| Marketing / landing / docs | Prerender | `{ prerender: true }` | Same HTML for everyone; SEO matters; edge-cached |
| Blog / content (build-time data) | Prerender | `{ prerender: true }` | Build, deploy, done |
| Private flow (form across routes, dashboard) | SPA | `{ ssr: false }` | Cross-route client state; no SEO |
| Personalised page (per-user content) | SSR | (default) | Needs request-time data |
| Aliases | Redirect | `{ redirect: '/target' }` | Cheaper than a redirect component |

## Syntax

```typescript
routeRules: {
  '/':            { prerender: true },
  '/about':       { prerender: true },
  '/blog/**':     { prerender: true },
  '/account':     { redirect: '/account/profile' },
  '/account/**':  { ssr: false }
}
```

Glob patterns:
- `'/x'` — exact
- `'/x/**'` — recursive
- `'/x/*'` — one segment
- `'/api/**'` — server handlers, never set rendering here

Specificity matters: more-specific rules win. Order in the file doesn't.

## When prerender breaks

The page tries to do something at request time that doesn't exist at build time:

- `useUser()` returns `null` because there's no session at build
- `useFetch` hits a DB / private API that isn't reachable from the build host
- Content changes between deploys (rebuild required for fresh content)

Fix by escaping with `<ClientOnly>` for the request-time bit, or switch the page to SSR / SPA.

## When `ssr: false` is right

The page renders nothing on the server. The browser receives an empty shell, then mounts Vue. Use it when:

- Cross-route client state must survive transitions via `useState` (no hydration concern because there's nothing on the server to mismatch)
- Authenticated dashboard where SEO is irrelevant
- Heavy client-only deps you don't want in the SSR bundle

Trade-off: slower first paint, no SEO content.

## Redirect rules

```typescript
'/account':       { redirect: '/account/profile' }
'/old-blog/(.+)': { redirect: { to: '/blog/${1}', statusCode: 301 } }
```

Object form supports captures and explicit status. Default is 302. Cheaper than `<script setup>navigateTo()</script>` because no Vue component renders.

## Hybrid in practice

```typescript
routeRules: {
  '/':           { prerender: true },
  '/about':      { prerender: true },
  '/pricing':    { prerender: true },
  '/account':    { redirect: '/account/profile' },
  '/account/**': { ssr: false }
}
```

Deploy artefacts:

- `/`, `/about`, `/pricing` → static `.html` files
- `/account` → 302 → `/account/profile`
- `/account/profile`, `/account/settings`, etc. → empty shell + client-side mount with shared `useState`-backed state

## Multi-step flows: page per step, state in a composable

```
pages/account/
├── profile.vue
├── billing.vue
└── done.vue
```

Each page is its own route. Shared state via a composable (see [nuxt-composables/multi-step-state.md](../../nuxt-composables/references/multi-step-state.md)).

Don't model multi-step as a single page with an internal `currentStep` ref — you lose deep-linking, back/forward, refresh, and history.

## `useSeoMeta` and rendering mode

| Mode | Meta visible to crawlers |
| --- | --- |
| Prerender | Yes — baked into static HTML at build |
| SSR | Yes — rendered per request |
| SPA (`ssr: false`) | No — JS-only DOM; crawlers see the empty shell |

Never put SEO-critical pages behind `ssr: false`.

## Anti-patterns

```typescript
// ❌ — prerender on a page needing request-time data
'/dashboard': { prerender: true }

// ❌ — ssr:false on an SEO page
'/blog/[slug]': { ssr: false }

// ❌ — multi-step modelled as one page with internal step state
// breaks deep-linking, back/forward, refresh

// ❌ — <script setup>navigateTo()</script> instead of a route rule
```

## Related

- **[nuxt-config/cloudflare-deployment.md](../../nuxt-config/references/cloudflare-deployment.md)** — prerender artefacts on Cloudflare Workers
- **[nuxt-composables/multi-step-state.md](../../nuxt-composables/references/multi-step-state.md)** — shared state across SPA routes
- **[layouts.md](./layouts.md)** — layout switching per page
- **[seo.md](./seo.md)** — SEO concerns by rendering mode
- **[pages.md](./pages.md)** — file-based routing and page meta
