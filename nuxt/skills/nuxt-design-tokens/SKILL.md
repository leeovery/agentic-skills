---
name: nuxt-design-tokens
description: Design tokens and theming via Tailwind 4 `@theme static` and Nuxt UI semantic classes. Use when registering custom type scales, motion curves, brand palettes, or contextual CSS overrides; also when building dark-mode-correct surfaces and View Transitions.
---

# Nuxt Design Tokens

Token-driven design with Tailwind 4 `@theme static` + Nuxt UI semantic
classes. The point: **brand and dark-mode behaviour live in CSS variables,
not Tailwind utility classes**. Templates stay semantic; the variables do
the work.

## When to use this skill

- Registering custom type scales (display sizes, fluid sizes)
- Adding motion curves, brand palettes, or font stacks as tokens
- Building dark-mode-correct surfaces without raw palette classes
- Adding contextual CSS overrides (e.g. an elevated tone within a section)
- Wiring View Transitions for theme toggles
- Configuring component-wide defaults via `app.config.ts`

## Core rules

1. **No raw palette classes in templates.** Use `text-default`, `bg-elevated`,
   `border-muted`. Never `text-gray-800` or `bg-stone-50`. Dark mode flips
   the semantic class via Nuxt UI; raw classes don't flip.
2. **Tokens live in `@theme static`, not inline.** Custom type sizes, motion
   curves, font stacks belong in `main.css`. Tailwind picks them up as
   utilities (`text-display-xl`, `ease-out-expo`).
3. **Brand palette lives in `app.config.ts`.** Override `primary` and
   `neutral` on the Nuxt UI palette; never hand-set the colour token unless
   you need a non-Tailwind hue.
4. **Arbitrary values are a sharp tool.** Prefer the scale even at ±1-2 px.
   Reserve `[clamp(...)]`, `[52ch]`, `[var(--foo)]` for things the scale
   genuinely cannot express.
5. **One contextual CSS rule beats five utility overrides.** When a
   container needs to shift the palette of its children, write a single
   `html.dark .my-context { --ui-bg: ... }` rule.

## Reference files

- **[tokens.md](references/tokens.md)** — `@theme static` registration,
  fluid type scales with paired weight/tracking/line-height, motion curves,
  font stacks
- **[semantic-colors.md](references/semantic-colors.md)** — Nuxt UI semantic
  class catalogue, dark-mode auto-flip, brand palette via `app.config.ts`,
  component default variants
- **[contextual-overrides.md](references/contextual-overrides.md)** — when
  to rebind `--ui-*` vars in a section, the `.reach-section-dark` pattern,
  scoping rules
- **[view-transitions.md](references/view-transitions.md)** — theme-toggle
  radial wipe via View Transitions API, fallback handling, `ClientOnly`
  wrapper pattern

## Quick decision table

| Need | Where it lives |
| --- | --- |
| Brand primary colour | `app.config.ts` → `ui.colors.primary` |
| Custom type scale | `main.css` → `@theme static { --text-* }` |
| Motion curve | `main.css` → `@theme static { --ease-* }` |
| Default form input size | `app.config.ts` → `<component>.defaultVariants.size` |
| Section-scoped palette tweak | `main.css` → single `.my-class { --ui-* }` rule |
| Smooth theme toggle | `ThemeToggle.vue` + `::view-transition-*` keyframes |

## Anti-patterns

- ❌ `text-gray-500` / `bg-stone-100` in templates — breaks dark mode
- ❌ Defining `--ui-primary-500` directly in CSS — use `app.config.ts`
  instead so Nuxt UI computes the full scale
- ❌ One-off `text-[15px]` everywhere — register a token if you use it twice
- ❌ Putting brand colour decisions in `nuxt.config.ts` — wrong file
- ❌ Tailwind plugins for custom utilities — Tailwind 4 expects tokens via
  CSS, not JS config

## Related skills

- **[nuxt-ui](../nuxt-ui/SKILL.md)** — component API, the `ui` prop, slot
  overrides
- **[nuxt-components](../nuxt-components/SKILL.md)** — `tailwind-variants`
  for component-level variant APIs; consumes the tokens defined here
- **[nuxt-config](../nuxt-config/SKILL.md)** — `app.config.ts` placement
