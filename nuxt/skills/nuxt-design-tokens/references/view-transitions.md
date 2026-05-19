# View Transitions for Theme Toggle

The View Transitions API gives you a free cross-fade between two DOM
states. For a theme toggle, you can shape the fade as a radial wipe
originating from the click point — the classic "ink spreading from the
sun/moon icon" effect.

## The CSS

```css
/* main.css */
::view-transition { pointer-events: none; }

::view-transition-old(root),
::view-transition-new(root) {
  animation: none;
  mix-blend-mode: normal;
  pointer-events: none;
}

::view-transition-group(root),
::view-transition-image-pair(root) { pointer-events: none; }

::view-transition-old(root) { z-index: 1; animation: none; opacity: 1; }
::view-transition-new(root) {
  z-index: 2;
  animation: theme-wipe 900ms var(--ease-out-expo) forwards;
}

@keyframes theme-wipe {
  from { clip-path: circle(0     at var(--wipe-x, 100%) var(--wipe-y, 0)); }
  to   { clip-path: circle(150%  at var(--wipe-x, 100%) var(--wipe-y, 0)); }
}
```

What this does:

- `::view-transition-new(root)` is the post-toggle DOM, painted on top.
- The `clip-path: circle()` animates from `0` to `150 %` (just past the
  diagonal corner-to-corner radius), revealing the new theme.
- `--wipe-x` / `--wipe-y` are set by the click handler so the circle
  originates at the toggle button.
- `pointer-events: none` everywhere on the transition pseudo-elements
  prevents accidental clicks landing on the animating layer instead of
  the live DOM.

## The Vue component

```vue
<!-- ThemeToggle.vue -->
<script setup lang="ts">
const colorMode = useColorMode()
const isDark = computed(() => colorMode.value === 'dark')

function onClick(e: MouseEvent) {
  const target = e.currentTarget as HTMLElement
  const rect = target.getBoundingClientRect()
  const doc = document as Document & { startViewTransition?: (cb: () => void) => unknown }

  document.documentElement.style.setProperty('--wipe-x', `${rect.left + rect.width / 2}px`)
  document.documentElement.style.setProperty('--wipe-y', `${rect.top + rect.height / 2}px`)

  const next = isDark.value ? 'light' : 'dark'
  const apply = () => { colorMode.preference = next }

  if (doc.startViewTransition) {
    doc.startViewTransition(apply)
  } else {
    apply()
  }
}
</script>

<template>
  <ClientOnly>
    <button
      type="button"
      :aria-label="`Switch to ${isDark ? 'light' : 'dark'} theme`"
      class="inline-flex size-9 items-center justify-center rounded-full border border-muted text-default transition-[border-color,transform] duration-200 ease-out-expo hover:rotate-[15deg] hover:border-default"
      @click="onClick"
    >
      <UIcon :name="isDark ? 'i-lucide-moon' : 'i-lucide-sun'" class="size-4" />
    </button>
    <template #fallback>
      <div class="size-9" />
    </template>
  </ClientOnly>
</template>
```

## Why each piece

- **`<ClientOnly>` + `<template #fallback>`** — `useColorMode()` returns
  a different value on the server than the client (server has no
  `localStorage`). Without the wrapper, hydration mismatches. The
  fallback is the same size as the button so layout doesn't jump.
- **Type assertion for `startViewTransition`** — the API isn't in the
  default `Document` lib types yet; cast inline rather than augmenting
  the global Document interface (cleaner).
- **Feature-detect, don't polyfill** — Firefox/older Safari fall back to
  an instant toggle. The radial wipe is progressive enhancement.
- **CSS vars set on `documentElement`, not the button** — the transition
  pseudo-elements are siblings of `<html>`, so the vars must inherit
  from there to be in scope.

## Common pitfalls

- Forgetting `pointer-events: none` — the animating layer eats clicks
  for the duration of the wipe; the page feels frozen.
- Setting the CSS var after calling `startViewTransition` — the snapshot
  is taken synchronously when the callback fires, so the var must be set
  first.
- Animating any other root-level transitions at the same time — they'll
  fight the wipe. Keep this transition exclusive.
- Wrapping the entire app in `<view-transition-name>` containers — not
  needed for a global wipe. The `root` keyword targets the implicit
  outer transition.

## Beyond theme toggles

Same pattern works for any "two-state" transition that needs a visual
hand-off (landing → app shell, sign-in → dashboard). The View
Transitions API also supports named element transitions across routes
— see MDN for details. For most marketing sites, a single root-level
wipe is all you need.
