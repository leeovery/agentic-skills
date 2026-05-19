# Design System Primitives

The reach-systems marketing site uses a small set of *semantic
layout/typography primitives* — not domain components, not UI library
wrappers. These cover 90 % of the page composition surface.

This file documents that pattern: what each primitive is for, how they
compose, and the `tailwind-variants` factory idiom that powers their
prop APIs.

## The primitives

| Component | Role |
| --- | --- |
| `<Display>` | Fluid display heading (h1/h2-style) with size variants |
| `<MonoLabel>` | Uppercase mono caps label (meta info, eyebrows) with tone variants |
| `<SectionMarker>` | Numbered section header with optional accent dot + caption |
| `<PageShell>` | Max-width container (sets the site's editorial measure) |
| `<ReachSection>` | Section wrapper with `tone` and `spacing` variants, wraps `PageShell` |

These are auto-imported via Nuxt's component scan. Reach for them
before reaching for raw heading tags or div wrappers.

---

## `<Display>` — fluid display heading

```vue
<!-- Display.vue -->
<script setup lang="ts">
import { tv } from 'tailwind-variants'

type Size = 'xl' | 'l' | 'm' | 's'

const props = withDefaults(defineProps<{
  size?: Size
  as?: string
}>(), {
  size: 'xl',
  as: 'h2'
})

const display = tv({
  base: 'font-display',
  variants: {
    size: {
      xl: 'text-display-xl',
      l:  'text-display-l',
      m:  'text-display-m',
      s:  'text-display-s'
    }
  }
})
</script>

<template>
  <component :is="as" :class="display({ size: props.size })">
    <slot />
  </component>
</template>
```

Key points:

- `as` prop lets the caller choose the underlying tag (`h1` for hero,
  `h2` for section heads, `h3` for sub-blocks) without changing the
  visual size. **Semantic markup and visual hierarchy are independent
  knobs.**
- Sizes map to `text-display-*` tokens registered in `main.css` — those
  tokens carry the paired line-height, tracking, weight (see
  `nuxt-design-tokens`).
- `tv()` (tailwind-variants) returns a function that takes the variant
  selection and returns the class string. The `base` is always applied.

## `<MonoLabel>` — uppercase mono caps

```vue
<script setup lang="ts">
import { tv } from 'tailwind-variants'

type Tone = 'muted' | 'strong' | 'accent'

const props = withDefaults(defineProps<{
  tone?: Tone
  as?: string
}>(), {
  tone: 'muted',
  as: 'span'
})

const mono = tv({
  base: 'font-mono text-xs leading-none uppercase tracking-widest',
  variants: {
    tone: {
      muted:  'text-muted',
      strong: 'text-highlighted',
      accent: 'text-primary'
    }
  }
})
</script>

<template>
  <component :is="as" :class="mono({ tone: props.tone })">
    <slot />
  </component>
</template>
```

Use cases: eyebrows above headings, meta info (`STEP 2 OF 4`), table
labels, microcopy that needs to feel like an editorial caption rather
than running text.

## `<SectionMarker>` — numbered section header

```vue
<script setup lang="ts">
withDefaults(defineProps<{
  label: string
  caption?: string
  accent?: boolean
}>(), {
  accent: false
})
</script>

<template>
  <div class="mb-12 flex items-center justify-between border-b border-default pb-3">
    <MonoLabel tone="strong" class="inline-flex items-center gap-2">
      <span
        class="inline-block size-2"
        :class="accent ? 'bg-primary' : 'bg-current'"
      />
      {{ label }}
    </MonoLabel>
    <MonoLabel v-if="caption">
      {{ caption }}
    </MonoLabel>
  </div>
</template>
```

Notice the composition: `SectionMarker` is built from `MonoLabel`s,
not from raw Tailwind classes. Primitives compose primitives. When
you change `MonoLabel`'s tracking, `SectionMarker` follows.

## `<ReachSection>` — section wrapper with tone

```vue
<script setup lang="ts">
import { tv } from 'tailwind-variants'

type Tone = 'default' | 'dark'
type Spacing = 'default' | 'tight'

const props = withDefaults(defineProps<{
  tone?: Tone
  spacing?: Spacing
  as?: string
  id?: string
}>(), {
  tone: 'default',
  spacing: 'default',
  as: 'section'
})

const section = tv({
  base: 'relative',
  variants: {
    tone: {
      default: 'bg-default',
      dark:    'reach-section-dark dark bg-default text-default'
    },
    spacing: {
      default: 'py-24 md:py-28',
      tight:   'py-16 md:py-20'
    }
  }
})
</script>

<template>
  <component :is="as" :id="id" :class="section({ tone: props.tone, spacing: props.spacing })">
    <PageShell>
      <slot />
    </PageShell>
  </component>
</template>
```

`tone="dark"` adds three classes:
- `reach-section-dark` — the contextual override hook (see
  `nuxt-design-tokens/references/contextual-overrides.md`)
- `dark` — Tailwind class that flips child semantic classes to dark
  mode regardless of page mode
- `bg-default text-default` — semantic classes that now resolve to the
  dark palette

The result: a dark slab on a light page, *and* a still-distinct slab
on a dark page (via the contextual override). One prop, both modes
correct.

## The `tv()` factory pattern

```typescript
const variant = tv({
  base: 'always-on-classes',
  variants: {
    propName: {
      value1: 'classes for value1',
      value2: 'classes for value2'
    },
    otherProp: {
      a: '...',
      b: '...'
    }
  },
  defaultVariants: {
    propName: 'value1'
  },
  compoundVariants: [
    { propName: 'value1', otherProp: 'a', class: 'extra-classes-for-this-combo' }
  ]
})

variant({ propName: 'value1', otherProp: 'b' })  // → class string
```

When to use it:

- Component has 2+ variant axes (size × tone, variant × color)
- You'd otherwise write a long ternary chain or computed
- You want a single source of truth for the variant API

When NOT to use it:

- Single boolean prop that toggles one class → just a `:class` binding
- No variants, just a wrapper → inline classes are fine
- You're tempted to put domain logic in `compoundVariants` — that
  belongs in the component, not the variant factory

## Composition rules

1. **Primitives compose primitives.** `SectionMarker` is built from
   `MonoLabel`. `ReachSection` is built from `PageShell`. Don't reach
   past the primitive into raw Tailwind classes when a primitive
   exists.
2. **`as` prop > new component.** Need an `h1` instead of an `h2`?
   `<Display as="h1">`, not a `<DisplayLarge>` component.
3. **One prop axis per concern.** `tone` is one axis (default/dark);
   `spacing` is another (default/tight). Don't merge them into a
   single `variant` prop.
4. **Semantic classes only inside primitives.** The whole point of
   primitives is to wrap the implementation so consumers don't think
   about palette. Internal raw classes (`text-stone-900`) leak the
   palette into the primitive and defeat the abstraction.
5. **No business logic in primitives.** A primitive doesn't know
   about pages, sections of the site, or content. It knows tone, size,
   spacing — visual attributes only.

## When to add a new primitive

Sign that you need a new primitive:

- Three+ files repeat the same block of Tailwind classes
- The block has variants (different sizes, tones, states) that you'd
  otherwise express via inline class ternaries
- The visual concept has a name you'd put in copy ("the eyebrow", "the
  display heading")

Sign you DON'T need a primitive:

- Used once; a section-specific component is fine
- The "variant axis" is just a prop someone might want — wait for the
  second occurrence
- It's actually a Nuxt UI component with a different name — use
  `<UButton variant="ghost">` instead of `<GhostButton>`

## Anti-patterns

```vue
<!-- ❌ raw heading with inline display classes — bypasses Display primitive -->
<h2 class="font-display text-display-xl">…</h2>

<!-- ✔ -->
<Display>…</Display>

<!-- ❌ section with inline padding + container; bypasses ReachSection + PageShell -->
<section class="py-24 md:py-28 bg-default">
  <div class="mx-auto w-full max-w-[1320px] px-7 md:px-10">…</div>
</section>

<!-- ✔ -->
<ReachSection>…</ReachSection>

<!-- ❌ a "DisplayXl" / "DisplayLarge" / "DisplayMedium" component family -->
<DisplayXl>…</DisplayXl>

<!-- ✔ -->
<Display size="xl">…</Display>
```

## Related

- **[nuxt-design-tokens](../../nuxt-design-tokens/SKILL.md)** — the
  `text-display-*` tokens and semantic class system that these
  primitives consume
- **[components.md](./components.md)** — script-setup order convention
  and other component-shape rules
