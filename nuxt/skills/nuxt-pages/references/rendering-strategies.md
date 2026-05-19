# Rendering Strategies (per-route)

Nuxt isn't "SSR vs SPA" — it's both, **per route**, driven by
`routeRules` in `nuxt.config.ts`. For most marketing sites with an
embedded app surface, the right answer is:

- Marketing pages → **prerender** (static HTML at build time)
- Private flows / dashboards → **SPA** (`ssr: false`, client-only)
- Dynamic pages with personalised content → **SSR** (default)

This file is the decision guide and the syntax.

---

## The decision table

| Page type | Strategy | Route rule | Why |
| --- | --- | --- | --- |
| Landing / about / pricing | Prerender | `{ prerender: true }` | Identical for everyone, SEO matters, edge cache hit |
| Blog / docs (CMS-backed but stable) | Prerender at build | `{ prerender: true }` | SEO + speed; rebuild on content change |
| Apply flow / multi-step form | SPA | `{ ssr: false }` | Client state survives nav; no SEO need |
| Authenticated dashboard | SPA | `{ ssr: false }` | Personalised data; no SEO value |
| Personalised landing | SSR (default) | (none) | Per-request render needed |
| Aliases / redirects | Redirect | `{ redirect: '/target' }` | Cheaper than a redirect component |

---

## Syntax

```typescript
// nuxt.config.ts
export default defineNuxtConfig({
  routeRules: {
    '/':                { prerender: true },
    '/about':           { prerender: true },
    '/founders-letter': { prerender: true },
    '/apply':           { redirect: '/apply/fit-check' },
    '/apply/**':        { ssr: false }
  }
})
```

Glob patterns:

- `'/path'` — exact match
- `'/path/**'` — recursive (any depth under `/path`)
- `'/blog/*'` — single segment (not recursive)
- `'/api/**'` — server routes (don't prerender these; they're handlers)

Order doesn't matter syntactically, but **specificity matters in
behaviour**: write more-specific rules first when reasoning. Nuxt
merges rules from least to most specific.

---

## `prerender: true`

At build time, Nitro renders the page to static HTML and bundles it
with the deployment.

- No server-side rendering at request time
- No JS hydration cost beyond what's needed for interactivity
- Edge-cacheable indefinitely (until next deploy)
- **No request-time data fetching.** Any `useFetch` / `useAsyncData`
  runs at build time; the response is baked into the HTML.

When prerender breaks:

- The page uses request-time auth (`useUser()` returns null at build)
- The data source isn't available during build (DB, private API)
- The content changes between deploys

For these, either escape into client-side (`<ClientOnly>` with a
fallback) or switch to SSR/SPA.

---

## `ssr: false`

The page renders client-side only. Server returns an empty shell;
Vue mounts on the client and renders the page.

- No SEO content
- No hydration mismatch concerns (nothing on the server to mismatch
  against)
- Cross-route client state survives — perfect for multi-step forms
- Slower first paint (loading spinner until JS executes)

Use for **private flows** where SEO doesn't matter and shared client
state matters.

```typescript
'/apply/**': { ssr: false }   // multi-step form with useState
'/admin/**': { ssr: false }   // authenticated dashboard
```

---

## `redirect`

A static redirect handled at the routing layer (server response on
SSR, client-side router push on SPA). Cheaper than a `<script
setup>navigateTo()</script>` redirect.

```typescript
'/apply':         { redirect: '/apply/fit-check' }
'/old-blog/(.+)': { redirect: { to: '/blog/${1}', statusCode: 301 } }
```

The function form supports param captures and explicit status codes.
Default is 302 (temporary).

---

## Hybrid example: marketing site + apply flow

```typescript
routeRules: {
  // Static marketing — prerendered
  '/':                { prerender: true },
  '/about':           { prerender: true },
  '/founders-letter': { prerender: true },

  // Alias
  '/apply':           { redirect: '/apply/fit-check' },

  // Multi-step form — SPA, shared state via useState
  '/apply/**':        { ssr: false }
}
```

What this produces on deploy:

- `/`, `/about`, `/founders-letter` → static `.html` files in the
  asset bundle, served from the edge
- `/apply` → redirect handler returns 302 → `/apply/fit-check`
- `/apply/fit-check`, `/apply/business`, etc. → empty shell from the
  Worker, client-side rendering with shared `useApplyForm()` state

---

## Layout switching for hybrid sites

The marketing pages and the app surface usually want different chrome.
Use per-page `layout:` to switch:

```vue
<!-- pages/index.vue -->
<script setup lang="ts">
definePageMeta({ layout: 'default' })  // header + footer
</script>
```

```vue
<!-- pages/apply/business.vue -->
<script setup lang="ts">
definePageMeta({ layout: 'apply' })    // minimal nav + progress
</script>
```

```
app/layouts/
├── default.vue   <UAppHeader /> + slot + <UAppFooter />
└── apply.vue     <ApplyNav /> + slot + <ApplyFooter /> (minimal)
```

Layouts are flat (no nesting in Nuxt 4). Pick the right one per page
rather than trying to compose multiple layouts.

---

## Multi-step page flow

```
app/pages/apply/
├── fit-check.vue       (step 1)
├── not-a-fit.vue       (off-ramp from step 1)
├── business.vue        (step 2)
├── growth.vue          (step 3)
├── partnership.vue     (step 4)
└── thanks.vue          (post-submit)
```

Each page is its own component. State is shared via a composable
(`useApplyForm()` — see nuxt-composables/multi-step-state.md). Pages
navigate to each other via `<UButton to="/apply/growth">`.

Don't try to model this as a single page with a `currentStep` ref —
you'd lose browser back/forward navigation and deep linking.

---

## `useSeoMeta` on prerendered pages

```vue
<script setup lang="ts">
useSeoMeta({
  title: 'Reach Systems — Apply',
  description: 'How we\'d work together.'
})
</script>
```

Run-time meta calls work on prerendered pages — the meta is baked
into the static HTML. For SPA routes, set meta inside the page
component too; the title updates as the client navigates (no static
HTML to bake into).

---

## Anti-patterns

```typescript
// ❌ — prerender on a page that needs request-time data
'/dashboard': { prerender: true }   // useUser() returns null at build

// ❌ — ssr:false on a page that should be SEO-discoverable
'/blog/post-1': { ssr: false }      // empty shell to crawlers

// ❌ — redirect component in <script setup> when route rule would do
// pages/old-path.vue
<script setup>navigateTo('/new-path')</script>

// ✔ — route rule
'/old-path': { redirect: '/new-path' }

// ❌ — modelling multi-step as a single page with internal step state
// breaks deep-linking, back button, and refresh

// ✔ — one page per step, shared state via composable
```

---

## Related

- **[nuxt-config](../../nuxt-config/references/cloudflare-deployment.md)** —
  hybrid rendering on Cloudflare Workers, prerendered assets bundling
- **[nuxt-composables](../../nuxt-composables/references/multi-step-state.md)** —
  shared state across SPA routes
- **[pages.md](./pages.md)** — file-based routing, dynamic routes,
  page meta
