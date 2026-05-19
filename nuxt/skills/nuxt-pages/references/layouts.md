# Layouts

Layouts are flat in Nuxt 4. One layout per file in `app/layouts/`, picked per-page via `definePageMeta({ layout: '<name>' })`. The default layout name is `default`.

This file covers when to add a layout, how to switch between them, and the slot-based composition pattern.

## Anatomy

```vue
<!-- app/layouts/default.vue -->
<template>
  <div class="flex min-h-screen flex-col">
    <AppHeader />
    <main class="flex-1">
      <slot />
    </main>
    <AppFooter />
  </div>
</template>
```

Every layout has a single root `<slot />` — that's where `<NuxtPage />` content lands. No more, no less.

The page content is wrapped in `<NuxtLayout>` once at the root:

```vue
<!-- app.vue -->
<template>
  <UApp>
    <NuxtLayout>
      <NuxtPage />
    </NuxtLayout>
  </UApp>
</template>
```

`<NuxtLayout>` reads each page's `definePageMeta({ layout: ... })` and resolves to the matching file in `app/layouts/`.

## Picking the layout per page

```vue
<!-- pages/apply/business.vue -->
<script setup lang="ts">
definePageMeta({ layout: 'apply' })
</script>
```

```vue
<!-- pages/about.vue -->
<script setup lang="ts">
// no definePageMeta needed — falls back to 'default'
</script>
```

The layout name is the filename without the extension, kebab-cased:

```
app/layouts/
├── default.vue       → layout: 'default'  (used when no override)
├── apply.vue         → layout: 'apply'
└── checkout-step.vue → layout: 'checkout-step'
```

## When to add a layout

Add a layout when **two or more pages share chrome** that differs from the default. Examples:

| Chrome shape | Layout name | Where it applies |
| --- | --- | --- |
| Site header + footer | `default` | Marketing / blog / docs pages |
| Minimal nav + form-centric main | `apply` | Multi-step form flow |
| Sidebar + topbar | `dashboard` | Admin / authenticated app |
| Centred card on neutral bg | `auth` | Login / signup / reset password |
| No chrome | `blank` | OG image generation, embeds, fullscreen errors |

Don't add a layout for a single page — just put the chrome in the page itself. Layouts are for shared chrome, not "I want to organise this page's wrapper somewhere else".

## Multi-area sites

A site with both marketing and an app surface usually has 2 layouts:

```
app/layouts/
├── default.vue   ← marketing chrome (AppHeader, AppFooter)
└── apply.vue     ← form-flow chrome (ApplyNav, ApplyFooter, narrow main)
```

```vue
<!-- pages/index.vue -->        — uses default (no override)
<!-- pages/about.vue -->        — uses default
<!-- pages/apply/business.vue --><script>definePageMeta({ layout: 'apply' })</script>
<!-- pages/apply/thanks.vue --> <script>definePageMeta({ layout: 'apply' })</script>
```

Apply pages share the `ApplyNav` (with progress indicator) and a centred, narrower main column. Marketing pages get the full-width chrome.

## Layouts can use composables

Layouts are full Vue components — they can run `<script setup>` and call composables:

```vue
<!-- app/layouts/apply.vue -->
<script setup lang="ts">
const { stepMeta } = useApplyForm()
</script>

<template>
  <div class="flex min-h-screen flex-col">
    <ApplyNav :step="stepMeta.step" :total-steps="4" :label="stepMeta.label" />
    <main class="flex flex-1 items-start justify-center px-7 py-20 md:px-10 md:py-28">
      <div class="w-full max-w-[640px]"><slot /></div>
    </main>
    <ApplyFooter />
  </div>
</template>
```

This is how the layout knows which step is active without prop drilling — it reads from the same `useState`-backed composable the pages use. Don't pass step state via slot props; use a shared composable.

## Named slots in layouts

`<slot />` (the default) is where `<NuxtPage />` lands. You can add **named** slots, but pages can't fill them directly (since `<NuxtPage />` is the only thing between the layout and your page).

If you need a named slot, the pattern is:

```vue
<!-- pages/about.vue -->
<script setup lang="ts">
definePageMeta({ layout: 'with-sidebar' })
</script>

<template>
  <div>
    <PageContent />
    <Teleport to="#sidebar-slot">
      <PageSidebar />
    </Teleport>
  </div>
</template>
```

```vue
<!-- app/layouts/with-sidebar.vue -->
<template>
  <div class="grid grid-cols-[1fr_320px]">
    <main><slot /></main>
    <aside id="sidebar-slot" />
  </div>
</template>
```

Honestly, this is rare and clunky. If your two pages need different chrome, write two layouts. Teleport is a last-resort tool.

## Programmatic layout switching

`setPageLayout` lets you change layout from runtime code. Avoid it; static `definePageMeta` is clearer. If you must:

```typescript
// in middleware or a watch
setPageLayout(isAuthed.value ? 'dashboard' : 'auth')
```

Use case: a page that flips chrome based on auth state. Better solution: put the page under `/dashboard/` (uses dashboard layout) or `/login` (uses auth layout) and let routing decide.

## `<NuxtPage>` and persistence

`<NuxtPage>` mounts/unmounts as the route changes — including when only a query param changes, by default. To keep a layout-mounted component alive across navigations, place it in the layout (not the page).

```vue
<!-- app/layouts/apply.vue -->
<template>
  <div>
    <ApplyNav /> <!-- stays mounted across /apply/business → /apply/growth -->
    <slot /> <!-- this re-renders -->
  </div>
</template>
```

This is why the apply-flow nav stays smoothly visible during step transitions — it's in the layout, not the page.

## Anti-patterns

- ❌ A `WithHeader.vue` layout and a `WithoutHeader.vue` layout differing only by header — make it a prop on the layout or move the conditional logic into a header component that knows when to render
- ❌ Multiple `<slot />` elements in one layout — Vue picks the last one; the others are ignored silently
- ❌ Putting `<UApp>` inside a layout — it goes in `app.vue` once; otherwise toasts/modals break on layout switches
- ❌ Calling `setPageLayout` to "fix" a stuck layout — that's a routing problem, fix the routing
- ❌ Importing `NuxtPage` into a layout — `<slot />` is the integration point
- ❌ Layout filename mismatched with the `layout:` value — kebab-case the filename to match

## Related

- **[pages.md](./pages.md)** — `definePageMeta` and file-based routing
- **[rendering-strategies.md](./rendering-strategies.md)** — layouts in prerender vs SSR vs SPA modes
- **[nuxt-architecture/marketing-site-shape.md](../../nuxt-architecture/references/marketing-site-shape.md)** — two-layout pattern for marketing + app
