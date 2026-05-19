# Marketing Site Shape

When you're building a marketing site (landing + content pages + maybe a
wizard or contact form), the admin-app structure documented in
`structure.md` mostly doesn't apply. This file describes the shape that
*does*.

The defining trait: **a marketing site is page-centric, not
domain-centric.** Each page is a narrative composition, not a view
onto a domain model. There are no entities, no list-detail patterns,
no CRUD.

---

## What you DON'T need

| Admin-app concept | Marketing-site replacement |
| --- | --- |
| Models (`Model`, hydrators) | Nothing — public site has no API entities |
| Repositories | Nothing — or a single email client, a single DB driver |
| Features (`features/leads/...`) | Nothing — pages compose primitives directly |
| Enums with behaviour | Plain TS const arrays in `shared/utils/` |
| Tables (XTable) | Nothing — marketing doesn't display tabular data |
| Real-time / Echo | Nothing |
| Permission system | Nothing — site is public |
| Error handlers by status code | Inline `try/catch` on the one `$fetch` you have |
| Multi-tenant routing | Single host |

Don't import these from a base layer just because the layer exposes
them. If you find yourself reaching for `Model` on a marketing site,
stop and reconsider — you almost certainly don't need it.

---

## What you DO need

```
app/
├── components/
│   ├── AppHeader.vue              site chrome
│   ├── AppFooter.vue              site chrome
│   ├── Display.vue                primitive: fluid display heading
│   ├── MonoLabel.vue              primitive: uppercase mono caps
│   ├── SectionMarker.vue          primitive: numbered section header
│   ├── PageShell.vue              primitive: max-width container
│   ├── PageSection.vue            primitive: section wrapper with tone
│   ├── ThemeToggle.vue            site chrome (with View Transitions)
│   ├── About/                     page-specific composition pieces
│   ├── Wizard/                    page-specific (form chrome, primitives)
│   └── Landing/                   page-specific (Hero, Features, …)
├── composables/
│   └── useWizardForm.ts           the one stateful flow
├── layouts/
│   ├── default.vue                marketing chrome
│   └── wizard.vue                 minimal chrome
├── pages/
│   ├── index.vue                  landing
│   ├── about.vue
│   ├── pricing.vue
│   └── wizard/                    multi-step form pages
├── plugins/
│   └── reveal.client.ts           v-reveal directive
├── assets/css/main.css            tokens + base styles
├── app.config.ts                  Nuxt UI palette + defaults
└── app.vue                        UApp + NuxtLayout + NuxtPage

shared/
└── utils/
    └── wizard-schema.ts           Zod schema (client + server use this)

server/
├── api/
│   └── wizard.post.ts             form submission handler
├── db/
│   ├── schema.ts                  Drizzle schema (submissions table)
│   └── migrations/sqlite/         D1 migrations
└── utils/                         email helpers
```

Key observations:

- **`components/` is page-centric.** Subdirs match top-level pages
  (`Landing/`, `About/`, `Wizard/`). Auto-imports prefix the name
  (`<LandingHero />`, `<WizardChipGroup />`).
- **A few cross-cutting primitives at the top of `components/`.**
  These don't belong to any page; they're the design system.
- **`composables/` is sparse.** Marketing sites have one or two
  stateful flows at most. If you have ten composables, something
  is wrong.
- **`shared/utils/` for cross-cutting validation.** Same Zod schema
  used by client (`useWizardForm` imports it) and server (Nitro
  imports it). One source of truth.

---

## Primitives vs page components

The directory structure encodes two layers:

**Layer 1 — Primitives** (top of `components/`):

- Have no business knowledge
- Take props for visual variants (size, tone, spacing)
- Compose with other primitives
- See [nuxt-components/design-system-primitives.md](../../nuxt-components/references/design-system-primitives.md)

**Layer 2 — Page components** (subdirs):

- Know about specific page sections
- Compose primitives + UI library components
- Hold the copy and the layout decisions
- Usually used once

When a page component starts being reused across pages, promote it
to a primitive. When a primitive starts holding copy or business
decisions, demote it to a page component.

---

## The `shared/` directory

Nuxt auto-imports `shared/utils/` and `shared/types/` into both the
client (`app/`) and the server (`server/`). Use it for code that
must be identical on both sides:

- Zod schemas (validation logic must match)
- Type definitions (request/response shapes)
- Pure utility functions (date formatting, URL coercion)

DON'T use `shared/` for:

- DB models — those are server-only (`server/db/`)
- API clients — those are server-only (`server/utils/`)
- Vue composables — those go in `app/composables/`

If you find a "shared" function that imports `defineEventHandler` or
`useFetch`, it doesn't belong in `shared/`.

---

## Plugin-based client directives

A reveal-on-scroll pattern via a Vue directive:

```typescript
// app/plugins/reveal.client.ts
export default defineNuxtPlugin((nuxtApp) => {
  nuxtApp.vueApp.directive('reveal', {
    mounted(el: HTMLElement, binding) {
      const delay = (Number(binding.value) || 0) * 60
      if (delay) el.style.transitionDelay = `${delay}ms`

      const io = new IntersectionObserver(([entry]) => {
        if (entry?.isIntersecting) {
          el.classList.remove('opacity-0', 'translate-y-3')
          el.classList.add('opacity-100', 'translate-y-0')
          io.unobserve(el)
        }
      }, { threshold: 0.1, rootMargin: '0px 0px -10% 0px' })

      io.observe(el)
    }
  })
})
```

```vue
<!-- usage -->
<div v-reveal class="opacity-0 translate-y-3 transition-all duration-700">…</div>
<div v-reveal="2" class="opacity-0 translate-y-3 transition-all duration-700">…</div>
                  <!-- 2 = staggered delay (2 * 60 = 120ms) -->
```

Why a directive, not a component:

- No wrapping element — directive operates on the host
- No props beyond a stagger index — directive's `binding.value` is
  enough
- Reusable across any element type — heading, image, paragraph

The `.client.ts` suffix is mandatory — `IntersectionObserver` doesn't
exist server-side. Without `.client`, SSR throws.

---

## Layouts: marketing default + flow-specific

Two layouts cover most marketing-site shapes: `default` (header + footer chrome) and a flow-specific one (e.g. `wizard` — minimal nav, narrow main). Don't try to make one layout cover both via slots and conditionals — two layouts with clear roles are easier to reason about. See [nuxt-pages/layouts.md](../../nuxt-pages/references/layouts.md).

---

## When the site grows: stay flat as long as possible

Resist the urge to introduce admin-app patterns prematurely:

| Symptom | Wrong reaction | Right reaction |
| --- | --- | --- |
| Two pages fetch from the same API | Add a repository | Create a composable that wraps the fetch |
| Multiple form fields validate similarly | Add a `useForm` builder | A `shared/utils/<form>-schema.ts` is enough |
| Components share a list of options | Add an enum class | A const array in `shared/utils/` is enough |
| Pages share a header / footer | (Already covered by layouts) | — |

Add structure when the cost of NOT having it exceeds the cost of
adding it. For a marketing site, that threshold is high.

---

## Anti-patterns

- ❌ Adopting the base/nuxt-ui/x-ui three-layer architecture for a
  marketing site — overkill, drags in admin-app deps
- ❌ Creating a `models/` directory because "every Nuxt app has one"
- ❌ Wrapping the one `$fetch` call in a `WizardRepository.ts`
- ❌ Building a "feature module" for a 4-page wizard
- ❌ Using `useFetch` for static data that should be in a const array

---

## Related

- **[nuxt-components](../../nuxt-components/references/design-system-primitives.md)** —
  the primitive layer
- **[nuxt-composables](../../nuxt-composables/references/multi-step-state.md)** —
  the one stateful composable a marketing site usually needs
- **[nuxt-pages](../../nuxt-pages/references/rendering-strategies.md)** —
  prerender + SPA hybrid for marketing + app surface
- **[structure.md](./structure.md)** — the admin-app structure this
  file is the counterpoint to
