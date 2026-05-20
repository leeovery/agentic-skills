# Semantic Colors

Nuxt UI ships a semantic colour system: `text-default`, `bg-elevated`,
`border-muted`. The semantic name is the contract; the underlying CSS
variable is the implementation. Templates only reference the contract.

## Why semantic-only

- Dark mode flips automatically. `text-default` resolves to `--color-stone-900`
  in light and a near-white in dark. No `dark:` modifier needed.
- The brand palette can be swapped in `app.config.ts` without touching
  templates. Re-skinning is a one-file change.
- Templates communicate intent, not implementation. `bg-elevated` says
  "this is one layer up from the surface" — readable.

## The catalogue

### Text

| Class | Use for |
| --- | --- |
| `text-default` | Body text, default tone |
| `text-highlighted` | Headings, emphasised inline text |
| `text-muted` | Secondary text, meta info |
| `text-dimmed` | Tertiary text, placeholder-like |
| `text-inverted` | Text on inverted surfaces (dark badge on light page) |
| `text-primary` | Brand-coloured text (links, accents) |
| `text-error` / `text-success` / `text-warning` / `text-info` | Status text |

### Background

| Class | Use for |
| --- | --- |
| `bg-default` | Page background |
| `bg-muted` | One step up from default (cards on page) |
| `bg-elevated` | Two steps up (modal over page) |
| `bg-accented` | Highlighted surface |
| `bg-inverted` | Inverse-mode surfaces |
| `bg-primary` | Brand colour fill |

### Border

| Class | Use for |
| --- | --- |
| `border-default` | Standard divider |
| `border-muted` | Subtle divider |
| `border-accented` | Stronger divider |
| `border-primary` | Brand-coloured border |

### Anti-patterns

```html
<!-- ❌ raw palette — won't flip in dark mode -->
<p class="text-stone-700 dark:text-stone-300">…</p>

<!-- ✔ semantic — flips automatically -->
<p class="text-default">…</p>

<!-- ❌ dark: modifiers everywhere -->
<div class="bg-white dark:bg-stone-900">…</div>

<!-- ✔ semantic -->
<div class="bg-default">…</div>
```

You should rarely write `dark:` in templates. If you do, it's a signal
that either (a) the semantic system doesn't cover your case (rare), or
(b) you should be using a different semantic class.

## Brand palette via `app.config.ts`

Set `primary` and `neutral` on `ui.colors`. Nuxt UI computes the full
50–950 scale from the named Tailwind colour.

```typescript
// app/app.config.ts
export default defineAppConfig({
  ui: {
    colors: {
      primary: 'orange',   // any Tailwind colour name
      neutral: 'stone'
    }
  }
})
```

This drives every semantic class that touches brand/neutral surfaces.
Changing `primary: 'orange'` → `primary: 'blue'` re-skins the entire site.

### Custom hues (rarely)

If the brand colour isn't in Tailwind's named palette, define the scale
under `@theme` and reference it:

```css
@theme {
  --color-brand-50:  oklch(0.97 0.02 30);
  --color-brand-500: oklch(0.60 0.18 30);
  --color-brand-950: oklch(0.20 0.08 30);
}
```

```typescript
ui: { colors: { primary: 'brand' } }
```

Prefer the named-Tailwind path — `orange` is fine 90 % of the time and
saves you maintaining your own oklch ladder.

## Component default variants

Set component-wide defaults in `app.config.ts` so you don't repeat the
same size/variant on every instance.

```typescript
export default defineAppConfig({
  ui: {
    colors: { primary: 'orange', neutral: 'stone' },

    // Make form inputs visually heavier across the site
    input:      { defaultVariants: { size: 'xl' } },
    textarea:   { defaultVariants: { size: 'xl' } },
    selectMenu: { defaultVariants: { size: 'xl' } },
    select:     { defaultVariants: { size: 'xl' } }
  }
})
```

Templates then just use `<UInput v-model="…">` — no `size="xl"` repeated
20 times.

This is the right place for defaults that are global, not per-feature.
Per-feature overrides still happen via the `ui` prop or `:ui` slot on
the component itself.

## Reading semantic class definitions

The generated theme files show every slot, variant, and default class:

- Nuxt: `.nuxt/ui/<component>.ts`
- Vue (Vite): `node_modules/.nuxt-ui/ui/<component>.ts`

When you're debugging "why is this `text-default` resolving to stone-700",
the answer is in those files. Treat them as read-only docs — never edit
the generated output.

## `<UColorModeButton>` vs custom toggle

Nuxt UI ships `<UColorModeButton>` — fine for prototypes. Replace it
with a custom button when you want View Transitions or a brand-specific
hover treatment. See [view-transitions.md](./view-transitions.md).
