# Marketing / Public Site Shape

Public-facing sites — landing, marketing, brochure, content-driven — are page-centric, not domain-centric. The admin-app patterns in [structure.md](./structure.md) (models, repositories, features) are overkill here.

This file is the counterpoint: what to skip, what to lean on instead.

## Decision: admin shape vs public shape

If you have API entities the UI lists / shows / edits → admin shape.
If the site is narrative content + maybe one form → public shape (this file).

When unsure: start public, add structure only when you outgrow it.

---

## What you don't need

| Admin-app pattern | Public site has… |
| --- | --- |
| Models (hydration, value objects) | Nothing — no API entities |
| Repositories | Nothing — or one server util for one third-party API |
| Features (`features/<domain>/...`) | Nothing — pages compose primitives directly |
| Enums with behaviour | Plain TS const arrays in `shared/utils/` |
| Tables | Nothing |
| Real-time / Echo | Nothing |
| Permission system | Site is public |
| Error handlers per status code | Inline `try/catch` on the one or two `$fetch` calls |
| Multi-tenant routing | Single host |

Don't import these from a base layer just because it exposes them. If you find yourself reaching for `Model` or `BaseRepository` on a public site, stop.

---

## What you do use

Sparse directory shape, page-centric:

```
app/
├── components/        primitives at top, page-scoped subdirs below
├── composables/       sparse — one or two stateful flows at most
├── layouts/           usually 2: default chrome + one feature-area chrome
├── pages/             file-based routes
├── plugins/*.client.ts  client-only directives for DOM effects
└── assets/css/main.css  tokens + base styles

shared/
└── utils/             Zod schemas, formatters, anything client + server both use

server/
├── api/               Nitro handlers
├── db/                Drizzle schema + migrations (if persisting)
└── utils/             email clients, anything server-only
```

Rules:

- **`components/` top-level = primitives** (visual, no domain). Page-specific components live in subdirs (`Landing/`, `About/`, …) and get auto-prefixed (`<LandingHero />`).
- **`composables/` stays sparse.** Marketing sites have one or two stateful flows. Ten composables is a smell.
- **`shared/utils/` is the single source of truth** for code both sides run (Zod schemas, URL coercion, date formatters). Never import server APIs into `shared/`; never import `useFetch`/Vue from `shared/`.

---

## `shared/utils/` and `shared/types/`

Auto-imported into both client and server. Use for code that *must* be identical on both sides.

Belongs here:
- Zod schemas (server validates against the same schema the client uses for UX)
- TypeScript types for request/response shapes
- Pure utility functions

Doesn't belong here:
- DB schema → `server/db/`
- API client / fetch wrappers → `server/utils/` (server) or `app/composables/` (client)
- Vue composables → `app/composables/`

A function in `shared/` that imports `defineEventHandler` or `useFetch` is misplaced.

---

## Client-only plugins for DOM effects

A Vue directive driven by `IntersectionObserver` (e.g. reveal-on-scroll) needs the `.client.ts` suffix — without it, SSR throws because the API doesn't exist server-side.

```typescript
// app/plugins/reveal.client.ts
export default defineNuxtPlugin((nuxtApp) => {
  nuxtApp.vueApp.directive('reveal', {
    mounted(el: HTMLElement, binding) {
      const io = new IntersectionObserver(([entry]) => {
        if (entry?.isIntersecting) {
          el.classList.remove('opacity-0', 'translate-y-3')
          el.classList.add('opacity-100', 'translate-y-0')
          io.unobserve(el)
        }
      }, { threshold: 0.1 })
      io.observe(el)
    }
  })
})
```

Why a directive, not a wrapper component:

- No extra DOM node
- Works on any element type (heading, image, paragraph)
- Stagger via `binding.value` — no per-instance prop boilerplate

---

## Stay flat as long as possible

Resist introducing admin-app patterns prematurely:

| Symptom | Wrong reaction | Right reaction |
| --- | --- | --- |
| Two pages fetch from the same API | Add a repository | A composable that wraps `$fetch` |
| Multiple form fields validate similarly | Add a `useForm` builder | A `shared/utils/<form>-schema.ts` |
| Components share a list of options | Add an enum class with behaviour | A const array in `shared/utils/` |
| Pages share chrome | (Already covered by layouts) | — |

Add structure when the cost of not having it outweighs the cost of adding it. On a public site that threshold is high.

---

## Anti-patterns

- ❌ Adopting a three-layer base/ui/x-ui architecture for a brochure site
- ❌ A `models/` directory because "every Nuxt app has one"
- ❌ Wrapping a single `$fetch` call in a repository class
- ❌ A "feature module" for a 3-page flow
- ❌ Using `useFetch` for static content that belongs in a const array

## Related

- **[structure.md](./structure.md)** — admin-app shape (counterpoint to this file)
- **[nuxt-pages/rendering-strategies.md](../../nuxt-pages/references/rendering-strategies.md)** — prerender for marketing, SPA for the private flow
- **[nuxt-components/design-system-primitives.md](../../nuxt-components/references/design-system-primitives.md)** — primitive-layer rules
- **[nuxt-composables/multi-step-state.md](../../nuxt-composables/references/multi-step-state.md)** — the one stateful composable a public site usually needs
