# Design Tokens via `@theme static`

Tailwind 4 reads custom CSS variables registered inside `@theme` (or
`@theme static` for build-time-resolved tokens) and exposes them as
utility classes. This is the canonical place for design tokens in a
Tailwind-4-on-Nuxt project — replaces the JS-based `tailwind.config.ts`
of v3.

## Layout

```css
/* app/assets/css/main.css */
@import "tailwindcss";
@import "@nuxt/ui";

@source "../../../app/**/*.{vue,ts,js}";

@theme static {
  /* font stacks */
  --font-sans:    "Geist", "Helvetica Neue", Helvetica, Arial, sans-serif;
  --font-mono:    "Geist Mono", "JetBrains Mono", ui-monospace, Menlo, monospace;
  --font-serif:   "Instrument Serif", Georgia, serif;
  --font-grotesk: "Space Grotesk", "Helvetica Neue", Helvetica, Arial, sans-serif;
  --font-display: var(--font-sans);

  /* motion curves */
  --ease-out-expo: cubic-bezier(0.22, 1, 0.36, 1);
  --ease-in-expo:  cubic-bezier(0.7,  0, 0.84, 0);

  /* fluid display type — value + paired typography props */
  --text-display-xl: clamp(3rem, 9vw, 8rem);
  --text-display-xl--line-height: 1;
  --text-display-xl--letter-spacing: -0.035em;
  --text-display-xl--font-weight: 700;

  --text-display-l: clamp(2.5rem, 7vw, 5.5rem);
  --text-display-l--line-height: 1;
  --text-display-l--letter-spacing: -0.03em;
  --text-display-l--font-weight: 700;
}
```

Tailwind picks these up and exposes:

- `font-sans`, `font-mono`, `font-serif`, `font-grotesk`, `font-display`
- `ease-out-expo`, `ease-in-expo`
- `text-display-xl`, `text-display-l`

## Paired typography properties

`--text-<name>` registers a font-size utility. Tailwind 4 also reads the
companion variables `--text-<name>--line-height`, `--text-<name>--letter-spacing`,
and `--text-<name>--font-weight` and emits them as part of the utility.

Why this matters: display sizes need different tracking and weight to the
body scale. Without paired props you'd write
`text-display-xl leading-[1] tracking-[-0.035em] font-bold` everywhere.
With paired props you write `text-display-xl` and the rest is implicit.

The line-height shifts with the size (1 at xl → 1.1 at s) because tight
display headings break visually if line-height inherits the body's 1.5.

## `@theme` vs `@theme static`

- `@theme { ... }` — tokens can be overridden at runtime by CSS variables
  on a parent element. Use when the value is conceptually theme-able
  (colors, sometimes radii).
- `@theme static { ... }` — tokens are resolved at build time only. Use
  when overrides don't make sense (type scales, motion curves, font
  stacks). Smaller output, no runtime indirection.

Default to `@theme static` for anything that isn't colour. Colours
typically come from Nuxt UI's `app.config.ts` palette, so you rarely
register colour tokens directly here.

## Font stacks + `@nuxt/fonts`

Declaring `--font-<name>` in `@theme static` is enough for Tailwind. To
have Nuxt actually load the webfont, list it under `fonts.families` in
`nuxt.config.ts`:

```typescript
// nuxt.config.ts
fonts: {
  families: [
    { name: 'Geist',            provider: 'google', weights: [400, 500, 600, 700] },
    { name: 'Geist Mono',       provider: 'google', weights: [400, 500] },
    { name: 'Instrument Serif', provider: 'google', weights: [400], styles: ['normal', 'italic'] },
    { name: 'Space Grotesk',    provider: 'google', weights: [400, 500, 600, 700] }
  ]
}
```

`@nuxt/fonts` self-hosts at build time (no FOIT, no third-party request
at runtime). The font name must match what you put in `--font-*` for it
to be picked up by `font-sans` etc.

## Motion curves

Cubic-bezier curves as tokens give you `ease-out-expo`, `ease-in-expo`
utilities. Use them on `transition-timing-function`:

```html
<button class="transition-transform duration-200 ease-out-expo hover:rotate-3">
```

Pair these with the View Transitions wipe in main.css so the same curve
is used everywhere; consistency beats per-component bezier tweaking.

## Arbitrary values: when justified

Tokens cover the 90 % case. The 10 % where arbitrary values are right:

| Pattern | Why arbitrary is right |
| --- | --- |
| `max-w-[52ch]` | Content measure, not a generic width |
| `text-[clamp(1.5rem,3vw,2rem)]` | One-off fluid size; register as `--text-*` if used twice |
| `rotate-[15deg]` | Specific design value, no Tailwind preset matches |
| `size-[72px]` | One-off fixed asset dimension |
| `bg-(--ui-bg)` | Reaching for a CSS var by name (Tailwind 4 shorthand) |

Use `bg-(--var)` shorthand instead of `bg-[var(--var)]` — the parens form
is the Tailwind 4 idiom, less noisy.

If you write the same arbitrary value twice, register it as a token. A
second occurrence is the signal that it's part of the system, not a
one-off.

## Don't

- ❌ Register colour tokens in `@theme` if Nuxt UI's palette covers them —
  duplicates the source of truth
- ❌ Use a `tailwind.config.ts` — Tailwind 4 + Nuxt UI expects tokens via
  CSS, not JS
- ❌ Add custom utility classes via `@layer utilities` for things a token
  could express — tokens compose better
- ❌ Use `@apply` in component CSS to bundle utilities — defeats the point
  of utility classes
