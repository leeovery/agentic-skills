# Contextual CSS Overrides

When a section/container needs to shift the palette of its children
without breaking the semantic system, rebind the underlying `--ui-*`
variables in a single CSS rule. One rule, all child semantic classes
follow.

## When you reach for this

- A "dark tone" section inside an otherwise light page that should also
  feel elevated when the whole page is already dark
- A "highlighted card" that needs a different surface tone than the
  generic `bg-elevated`
- A feature region that uses an alternate accent without re-skinning the
  whole site

If you find yourself thinking "I want this section to feel one step
heavier", that's the signal.

## The pattern

```css
/* main.css */

/* Dark-tone section override — subtle elevation when page is already dark.
   In light mode the section's `.dark` class inverts the palette.
   In dark mode the `.dark` class is redundant, so the section would blend
   into the page. Rebinding --ui-bg within the section gives it a step up. */
html.dark .reach-section-dark {
  --ui-bg: color-mix(in oklab, var(--color-stone-900), var(--color-stone-800) 50%);
}
```

```vue
<!-- consuming Vue component -->
<template>
  <section :class="tone === 'dark' ? 'reach-section-dark dark bg-default text-default' : 'bg-default'">
    <slot />
  </section>
</template>
```

What this does:

- In light mode, the `dark` Tailwind class on the wrapper inverts the
  palette for children — semantic classes now resolve as if dark mode
  was on. Section looks like a dark slab on a light page.
- In dark mode, the parent already has `html.dark`, so the section's
  own `dark` class is redundant. The section would blend into the page.
  The single CSS rule rebinds `--ui-bg` to a colour `color-mix`'d one
  step lighter than the page background, restoring the alternation.

## Rules of engagement

1. **One rule per context.** If you write more than one CSS rule for a
   single context, you're probably reaching beyond the design-token
   system — consider adding a token instead.
2. **Override `--ui-*`, not semantic classes.** Don't write
   `.my-section .text-default { color: red }`. Override the variable
   that `text-default` reads from, or use a different semantic class.
3. **Light + dark separately.** A contextual override that only handles
   one mode will look broken in the other. Always think through both.
4. **Document the rule in `main.css`.** Include a comment explaining
   what the rule fixes and why a simpler approach (Tailwind class,
   token) doesn't work. These rules are subtle and easy to delete by
   accident.
5. **Never touch `--color-*` Tailwind vars in a context override.** Those
   are the palette source. Override `--ui-*` (Nuxt UI's semantic layer)
   instead so the system stays composable.

## Anti-patterns

```css
/* ❌ — overrides the global Tailwind colour scale; affects everything */
.my-section { --color-stone-900: red; }

/* ❌ — overrides a Tailwind utility; breaks dark-mode flipping */
.my-section .text-default { color: red; }

/* ❌ — only one mode covered, looks broken in the other */
.my-section { --ui-bg: black; }

/* ✔ — scoped, semantic-layer override, both modes considered */
html .my-section       { --ui-bg: ...; }
html.dark .my-section  { --ui-bg: ...; }
```

## Limits

Contextual overrides are right for **palette shifts within a known
container**. They're wrong for:

- Per-component variants — use `tailwind-variants` (`tv()`) on the
  component instead
- Site-wide re-skinning — use `app.config.ts` colour config
- Component-specific styling — use the `ui` prop or slot overrides

The rule of thumb: if the override would need to follow the content
around (across multiple containers), it's not a context override —
it's a component variant.
