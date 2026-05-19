# Images via `@nuxt/image`

`@nuxt/image` optimises image delivery: responsive `srcset`, format negotiation (WebP/AVIF), lazy loading, provider-based CDN integration. For a marketing site, LCP is dominated by the hero image; this module is how you fix it.

## Setup

```bash
npm i @nuxt/image
```

```typescript
// nuxt.config.ts
modules: ['@nuxt/image'],

image: {
  // default provider — picks the right one based on the host
  // 'ipx' for self-hosted (default), 'cloudflare' for Cloudflare Images, etc.
  provider: 'ipx'
}
```

For Cloudflare Workers deploys, the IPX provider runs on the Worker — fine for small marketing sites but burns CPU time on each transform. For larger sites use Cloudflare Images or a CDN-backed provider.

## `<NuxtImg>` vs `<NuxtPicture>`

Two components, different use cases:

| Component | Use for | Renders |
| --- | --- | --- |
| `<NuxtImg>` | Single source format, responsive `srcset` | `<img>` with `srcset` + `sizes` |
| `<NuxtPicture>` | Modern formats with fallback | `<picture>` with `<source>` per format |

```vue
<!-- NuxtImg — modern format chosen at request time via Accept header -->
<NuxtImg
  src="/img/hero.jpg"
  alt="Team at work"
  sizes="100vw md:50vw lg:33vw"
  width="1200"
  height="800"
/>
```

```vue
<!-- NuxtPicture — AVIF + WebP + JPEG fallback baked into the HTML -->
<NuxtPicture
  src="/img/hero.jpg"
  alt="Team at work"
  sizes="100vw md:50vw lg:33vw"
  width="1200"
  height="800"
  formats={['avif', 'webp']}
/>
```

Default: use `<NuxtImg>`. The provider negotiates format via the `Accept` header — modern browsers get AVIF/WebP, older ones get JPEG. `<NuxtPicture>` is for when you want the format negotiation baked into the HTML (e.g. prerendered static assets where you can't read request headers).

## `sizes` — getting LCP right

The `sizes` attribute tells the browser which `srcset` variant to pick before layout. Wrong `sizes` = wrong image downloaded.

```vue
<!-- ✅ — image is full width on mobile, half on md+, third on lg+ -->
<NuxtImg sizes="100vw md:50vw lg:33vw" />

<!-- ❌ — defaults to "100vw" for everything, fetches the largest variant always -->
<NuxtImg src="/img/hero.jpg" />
```

Use the same breakpoint tokens as Tailwind. NuxtImage auto-generates a `srcset` covering 320, 640, 768, 1024, 1280, 1536 widths; the browser picks based on `sizes` + device pixel ratio.

For an above-the-fold hero, get `sizes` right or LCP suffers.

## LCP image hint

The hero image is almost always the LCP element. Tell the browser:

```vue
<NuxtImg
  src="/img/hero.jpg"
  sizes="100vw md:1200px"
  width="1200"
  height="800"
  preload                          
  loading="eager"                  
  fetchpriority="high"             
  alt="Team at work"
/>
```

- `preload` — emits `<link rel="preload" as="image">` in `<head>`
- `loading="eager"` — opts out of lazy loading (which is the default for `<img>` in modern browsers)
- `fetchpriority="high"` — browser priority hint

Set these only on the LCP image. Everything else: `loading="lazy"` (default) + no preload.

## `width` and `height` are non-optional

Always set them. Without them:

- Browser doesn't know the aspect ratio → reserves no space → layout shift on load → CLS penalty
- `srcset` selection may guess wrong → wrong-size image downloaded

If the image is fluid in CSS (`width: 100%`), still set `width` + `height` on the element — the browser uses them only for aspect-ratio reservation, not actual sizing.

```vue
<NuxtImg
  src="/img/portrait.jpg"
  width="800"
  height="1200"
  class="w-full h-auto"           
/>
```

`width="800" height="1200"` declares the intrinsic aspect ratio (2:3). `class="w-full h-auto"` lets the layout size it.

## Provider config

For self-hosted optimisation (default — IPX):

```typescript
image: {
  provider: 'ipx',
  dir: 'app/assets/images',       // optional — where unoptimised originals live
  domains: ['images.unsplash.com']  // whitelist external sources
}
```

For Cloudflare Images:

```typescript
image: {
  provider: 'cloudflare',
  cloudflare: { baseURL: 'https://imagedelivery.net/<account-hash>' }
}
```

For a Cloudflare Workers deploy specifically, the IPX provider runs **on the Worker** — every image transform costs CPU time per request. Fine for low-volume marketing sites; not fine for high-traffic / many-image sites. If transforms get expensive, switch to Cloudflare Images or pre-generate variants at build time.

## Modifiers

Per-image transformations via modifiers prop:

```vue
<NuxtImg
  src="/img/hero.jpg"
  :modifiers="{ blur: 4, quality: 80 }"
  width="1200"
  height="800"
/>
```

Common modifiers: `quality`, `format` (force a format), `fit` (`cover`/`contain`/`fill`), `blur`, `grayscale`. Provider-specific; check the docs.

Quality below 80 visibly degrades; below 70 is mush. Default (~75) is usually fine. The win is format negotiation, not aggressive quality reduction.

## Hero image checklist

For the above-the-fold hero on a marketing landing page:

- [ ] `<NuxtImg>` (not `<img>`)
- [ ] `width` + `height` set to intrinsic dimensions
- [ ] `sizes` declares the rendered width at each breakpoint
- [ ] `preload` + `loading="eager"` + `fetchpriority="high"`
- [ ] Image is in `WebP` or `AVIF` (let the provider handle it)
- [ ] Source file is ≤ 500 KB at the largest needed dimension
- [ ] `alt` text describes the image purpose, not "image of …"

That's the LCP win. Measure with Lighthouse before and after; should drop 1–3 seconds on cold mobile loads.

## Anti-patterns

- ❌ `<img src="/img/hero.jpg">` for any non-trivial image — no optimisation, no responsive
- ❌ Setting `width` + `height` to CSS sizes (e.g. `width="100%"`) — must be pixel intrinsic dimensions
- ❌ `loading="lazy"` on the LCP image — defers the LCP load by 100ms+
- ❌ `preload` on every image — preloads compete for bandwidth, LCP gets worse
- ❌ Skipping `sizes` — browser fetches the largest variant always
- ❌ Tiny `quality: 30` to "save bandwidth" — image looks terrible, savings are marginal vs format negotiation
- ❌ Animated GIFs — convert to MP4/WebM via `<NuxtVideo>` (separate module) or convert manually

## Related

- **[seo.md](./seo.md)** — OG images (different concern: those go to crawlers, not visitors)
- **[rendering-strategies.md](./rendering-strategies.md)** — prerender + `<NuxtImg>` works; SSR + `<NuxtImg>` works
- **[nuxt-design-tokens/fonts.md](../../nuxt-design-tokens/references/fonts.md)** — similar self-hosting story for fonts
