# Layouts

Layouts are flat in Nuxt 4. One layout per file in `app/layouts/`, picked per-page via `definePageMeta({ layout: '<name>' })`. The default is `default`.

## Anatomy

```vue
<!-- app/layouts/default.vue -->
<template>
  <div class="flex min-h-screen flex-col">
    <AppHeader />
    <main class="flex-1"><slot /></main>
    <AppFooter />
  </div>
</template>
```

Every layout has a single root `<slot />` — that's where `<NuxtPage />` content lands. No more, no less.

The page content is wrapped once at the root:

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

`<NuxtLayout>` reads each page's `definePageMeta({ layout: ... })` and resolves to the matching file.

## Picking the layout per page

```vue
<script setup lang="ts">
definePageMeta({ layout: 'app' })   // pages/app/*.vue all use 'app' layout
</script>
```

```vue
<script setup lang="ts">
// no definePageMeta → falls back to 'default'
</script>
```

The layout name is the filename without extension, kebab-cased (`auth.vue` → `'auth'`, `checkout-step.vue` → `'checkout-step'`).

## When to add a layout

Add when two or more pages share chrome that differs from the default:

| Chrome shape | Typical layout name |
| --- | --- |
| Site header + footer | `default` |
| Sidebar + topbar | `app` / `dashboard` |
| Centred card on neutral bg | `auth` |
| Minimal nav + narrow main | `flow` (multi-step forms, focused tasks) |
| No chrome | `blank` (OG image generation, embeds, fullscreen errors) |

Don't add a layout for a single page — chrome in the page itself is fine. Layouts are for *shared* chrome.

## Layouts can use composables

Layouts are full Vue components — `<script setup>` works.

```vue
<!-- app/layouts/app.vue -->
<script setup lang="ts">
const { user } = useUser()
</script>

<template>
  <div class="flex min-h-screen">
    <AppSidebar :user="user" />
    <main class="flex-1"><slot /></main>
  </div>
</template>
```

This is how the layout reads shared state without prop-drilling — same composable the page uses. Don't pass page-derived state into the layout via slot props; use a composable.

## `<NuxtPage>` persistence

`<NuxtPage>` mounts/unmounts on every route change (including query-param changes by default). Keep state that should survive route changes in the **layout**, not the page:

```vue
<!-- app/layouts/app.vue -->
<template>
  <AppSidebar />  <!-- mounted once; survives navigation -->
  <slot />        <!-- this re-renders per route -->
</template>
```

## Named slots in layouts (rare, last resort)

Pages can't fill named slots directly — `<NuxtPage>` sits between them and the layout. Workaround via `<Teleport>`:

```vue
<!-- app/layouts/with-sidebar.vue -->
<template>
  <div class="grid grid-cols-[1fr_320px]">
    <main><slot /></main>
    <aside id="sidebar-slot" />
  </div>
</template>
```

```vue
<!-- pages/some-page.vue -->
<template>
  <PageContent />
  <Teleport to="#sidebar-slot"><PageSidebar /></Teleport>
</template>
```

Clunky. Prefer separate layouts when pages need different chrome.

## Programmatic switching (avoid)

`setPageLayout('auth')` works but a static `definePageMeta({ layout: 'auth' })` is clearer. If you find yourself flipping layouts at runtime, the routing is probably wrong — put the page under a path that maps to the right layout statically.

## Anti-patterns

- ❌ `WithHeader.vue` and `WithoutHeader.vue` differing only by header — use a prop or a conditional header component
- ❌ Multiple `<slot />` in one layout — Vue uses the last one, others ignored silently
- ❌ `<UApp>` inside a layout — goes in `app.vue` once; layout switches will tear down toasts/modals otherwise
- ❌ Importing `NuxtPage` into a layout — `<slot />` is the integration point
- ❌ Filename mismatch — kebab-case the filename to match the `layout:` value

## Related

- **[pages.md](./pages.md)** — `definePageMeta` and file-based routing
- **[rendering-strategies.md](./rendering-strategies.md)** — layouts under prerender / SSR / SPA modes
