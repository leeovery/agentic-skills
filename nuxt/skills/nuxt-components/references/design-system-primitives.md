# Design System Primitives

Build a small layer of *visual primitives* — components that know nothing about your domain, only about typography and layout. Templates compose primitives; primitives never compose pages.

## Rules

1. **One concern per primitive.** A `Heading` primitive owns sizing, weight, and tracking. It doesn't know about hero sections, page titles, or marketing copy.
2. **`as` prop > new component.** Need different markup for the same visual? `<Heading as="h1">` not `<HeadingH1>`.
3. **One prop axis per concern.** `size` and `tone` are two axes, not one merged `variant` prop.
4. **Semantic classes only inside primitives.** Raw palette classes (`text-stone-900`) leak the colour system into the abstraction. Use `text-default`, `bg-elevated`, etc.
5. **Primitives compose primitives.** A section-header primitive built on a label primitive inherits changes when the label changes. Don't reach past primitives into raw utility classes.
6. **No business logic in primitives.** Tone, size, spacing — visual attributes only. `<NewsletterHero>` is a page component, not a primitive.

## The `tv()` factory pattern

`tailwind-variants` (`tv()`) gives components a typed, declarative variant API:

```typescript
import { tv } from 'tailwind-variants'

const heading = tv({
  base: 'font-display',
  variants: {
    size: {
      xl: 'text-5xl tracking-tight',
      l:  'text-4xl tracking-tight',
      m:  'text-2xl',
      s:  'text-xl'
    }
  },
  defaultVariants: { size: 'm' }
})

heading({ size: 'xl' })   // → class string
```

Minimal Vue component using it:

```vue
<script setup lang="ts">
import { tv } from 'tailwind-variants'

type Size = 'xl' | 'l' | 'm' | 's'

const props = withDefaults(defineProps<{
  size?: Size
  as?:   string
}>(), { size: 'm', as: 'h2' })

const heading = tv({
  base: 'font-display',
  variants: { size: { xl: 'text-5xl', l: 'text-4xl', m: 'text-2xl', s: 'text-xl' } }
})
</script>

<template>
  <component :is="as" :class="heading({ size: props.size })">
    <slot />
  </component>
</template>
```

### When to use `tv()`

- Component has 2+ variant axes (`size × tone`, `variant × color`)
- You'd otherwise write a ternary chain or computed in the template
- You want a single source of truth for the variant API

### When NOT to use `tv()`

- Single boolean prop that toggles one class → `:class` binding is shorter
- No variants, just a styled wrapper → inline classes
- Tempted to encode domain rules in `compoundVariants` → put them in the consuming component

## Anti-patterns

```vue
<!-- ❌ inline display classes — bypasses the primitive -->
<h2 class="font-display text-5xl tracking-tight">…</h2>

<!-- ✔ -->
<Heading size="xl">…</Heading>

<!-- ❌ a component per size -->
<HeadingXl>…</HeadingXl>

<!-- ✔ -->
<Heading size="xl">…</Heading>

<!-- ❌ raw palette inside a primitive's template -->
<div class="bg-stone-100 text-stone-900">…</div>

<!-- ✔ -->
<div class="bg-elevated text-default">…</div>
```

## When to add a primitive

Add when **all** apply:
- Three+ files repeat the same block of classes
- The block has variants (sizes, tones, states)
- The visual concept has a name a designer would use ("eyebrow", "display heading")

Don't add when:
- Used once — section-specific component is fine
- The "variant" is one prop someone *might* want — wait for the second occurrence
- A Nuxt UI component covers it under a different name — use `<UButton variant="ghost">` not `<GhostButton>`

## Related

- **[nuxt-design-tokens](../../nuxt-design-tokens/SKILL.md)** — semantic classes, custom token registration (the layer primitives consume)
- **[components.md](./components.md)** — script-setup order and other component-shape rules
