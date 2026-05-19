# SEO: Meta, OG, Sitemap, Structured Data

SEO is page-level work, so this skill owns it. The default Nuxt stack for serious SEO is `@nuxtjs/seo` — a meta-module that pulls in `@nuxtjs/sitemap`, `@nuxtjs/robots`, `nuxt-og-image`, `nuxt-schema-org`, and `nuxt-seo-experiments` in one install.

## Setup

```bash
npm i @nuxtjs/seo
```

```typescript
// nuxt.config.ts
export default defineNuxtConfig({
  modules: ['@nuxtjs/seo'],

  site: {
    url: 'https://example.com',          // required — used by every submodule
    name: 'Example',
    description: 'Default description fallback',
    defaultLocale: 'en'
  }
})
```

`site.url` is the canonical origin. Every absolute URL in OG tags, sitemap entries, and canonical links is derived from it. Set it explicitly per environment (`NUXT_PUBLIC_SITE_URL` for staging vs prod) — without it, you get `localhost:3000` in production canonical tags.

## Page-level meta with `useSeoMeta`

```vue
<!-- pages/about.vue -->
<script setup lang="ts">
useSeoMeta({
  title: 'About',
  description: 'How we got here.',
  ogTitle: 'About',
  ogDescription: 'How we got here.',
  twitterCard: 'summary_large_image'
})
</script>
```

Rules:

- **`title` only** — not `title` *and* `head: { title: ... }`. Pick one. `useSeoMeta` is idiomatic.
- **`og*` fallbacks**: if you don't set `ogTitle`/`ogDescription`, `useSeoMeta` falls back to `title`/`description`. So for most pages, setting `title` + `description` is enough.
- **Twitter card**: `summary` (default) shows a small thumbnail; `summary_large_image` shows the full OG image. Pick once in `app.vue`; override per-page only if you need to.
- **Static values**: prefer hardcoded strings over interpolation. SEO meta should be predictable on a per-route basis, not derived from runtime state.

### Root-level defaults in `app.vue`

```vue
<!-- app.vue -->
<script setup lang="ts">
const title = 'Site name — value prop'
const description = 'One-sentence description of what the site does.'

useSeoMeta({
  title,
  description,
  ogTitle: title,
  ogDescription: description,
  twitterCard: 'summary_large_image'
})
</script>

<template>
  <UApp>
    <NuxtLayout><NuxtPage /></NuxtLayout>
  </UApp>
</template>
```

Page-level `useSeoMeta` calls **override** the root-level ones. Set sensible defaults in `app.vue` and override only what differs per page.

### Template-bound titles

Set a title template once:

```typescript
// app.vue
useHead({ titleTemplate: (title) => title ? `${title} · Site name` : 'Site name' })
```

Page-level `title: 'About'` then renders as `About · Site name`. Skip the template if you'd rather write the full title per page (more flexible, more typing).

## Open Graph images via `nuxt-og-image`

The submodule lets you define dynamic OG images per route. Two flavours:

### Per-page static image

```vue
<script setup lang="ts">
defineOgImage({ url: '/og/about.png' })
</script>
```

Just sets `og:image` to the URL. Use when you have hand-designed PNG assets in `public/og/`.

### Dynamic image from a Vue component

```vue
<!-- components/OgImage/Default.vue -->
<template>
  <div class="flex h-[630px] w-[1200px] items-center justify-center bg-stone-900 text-white">
    <h1 class="text-display-l">{{ title }}</h1>
  </div>
</template>

<script setup lang="ts">
defineProps<{ title: string }>()
</script>
```

```vue
<!-- pages/about.vue -->
<script setup lang="ts">
defineOgImage({ component: 'Default', title: 'About' })
</script>
```

The module renders the component to PNG at build time (for prerendered routes) or on-demand (for SSR). Useful when you have many pages; tedious to maintain for a small site.

For a marketing site with ~5 pages, hand-designed PNGs are easier.

## Sitemap

`@nuxtjs/sitemap` auto-emits `/sitemap.xml` based on:

- Every static route in `pages/`
- Every prerendered route in `routeRules: { ...: { prerender: true } }`
- Dynamic routes added via the sitemap hook

```typescript
// nuxt.config.ts
sitemap: {
  exclude: [
    '/wizard/**',         // private flow, don't index
    '/wizard/done'
  ]
}
```

A multi-step wizard / private flow shouldn't be in the sitemap — no SEO value, and `/done`-style success pages would be confusing if crawled. Exclude explicitly.

### Dynamic sitemap entries

If you have content that isn't represented as static pages (a CMS, dynamic blog posts):

```typescript
// server/api/__sitemap__/urls.ts
export default defineSitemapEventHandler(async () => {
  const posts = await fetchPostsFromCms()
  return posts.map(p => ({
    loc: `/blog/${p.slug}`,
    lastmod: p.updatedAt,
    changefreq: 'weekly',
    priority: 0.7
  }))
})
```

The handler runs at sitemap-generation time (during build for prerendered output, or on request for SSR'd `/sitemap.xml`).

## `robots.txt`

`@nuxtjs/robots` auto-generates `/robots.txt`:

```typescript
// nuxt.config.ts
robots: {
  disallow: ['/wizard'],
  sitemap: 'https://example.com/sitemap.xml'
}
```

For production: allow all by default, deny the private flow paths. For staging: deny everything (`disallow: ['/']`) so search engines don't index pre-launch content.

```typescript
robots: {
  disallow: process.env.NUXT_PUBLIC_SITE_URL?.includes('staging') ? ['/'] : ['/wizard'],
  sitemap: `${process.env.NUXT_PUBLIC_SITE_URL}/sitemap.xml`
}
```

## Canonical URLs

`useSeoMeta({ canonical: '...' })` works, but the better default is to let `@nuxtjs/seo` derive canonicals from `site.url + route.path`. Override only when the page is reachable via multiple URLs and you need to pick the primary.

```vue
<!-- pages/old-path.vue (kept for backwards-compat link rot) -->
<script setup lang="ts">
useSeoMeta({ canonical: 'https://example.com/new-path' })
</script>
```

Better still: redirect via a route rule instead of keeping a duplicate page.

## Structured data (`nuxt-schema-org`)

JSON-LD schema for rich results in search. Component-based API:

```vue
<script setup lang="ts">
useSchemaOrg([
  defineOrganization({
    name: 'Example',
    url: 'https://example.com',
    logo: '/logo.png',
    sameAs: ['https://linkedin.com/company/example']
  })
])
</script>
```

```vue
<!-- pages/blog/[slug].vue -->
<script setup lang="ts">
useSchemaOrg([
  defineArticle({
    headline: post.title,
    datePublished: post.publishedAt,
    dateModified: post.updatedAt,
    author: post.authors
  })
])
</script>
```

Each `define*` helper returns a JSON-LD blob; `useSchemaOrg` renders them as `<script type="application/ld+json">`. Validate against [search.google.com/test/rich-results](https://search.google.com/test/rich-results) before relying on them.

Don't add schema for every page. Schema is for content types Google has rich-result templates for: Articles, Products, FAQs, Events, Recipes, Organisation. Adding it to a "404" page is wasted.

## Prerender + SEO

For prerendered pages (`routeRules: { '/': { prerender: true } }`), all SEO meta is baked into the static HTML at build time. Crawlers see it without running JS. This is the right shape for marketing sites.

For SSR pages, meta is rendered on each request — also fine for crawlers (Google does run JS, but slowly; SSR-rendered meta hits in the first request).

For SPA pages (`ssr: false`), meta is set client-side after JS executes. **Crawlers running without JS see only the empty HTML shell.** Don't put SEO-critical content behind `ssr: false`.

Verify by inspecting the raw HTML response in production, not just the browser-rendered DOM:

```bash
curl -s https://example.com/ | grep -E '<title>|<meta property="og:'
```

See [nuxt-testing/e2e-and-api.md](../../nuxt-testing/references/e2e-and-api.md#testing-prerendered-output) for automated assertions on prerendered SEO output.

## Anti-patterns

- ❌ Setting meta from runtime data (`useFetch` results) on prerendered pages — the meta is computed at build, runtime data doesn't yet exist
- ❌ Calling `useSeoMeta` inside a `watch` or `onMounted` — late updates don't make it into the prerendered HTML
- ❌ Setting `ogImage` to a relative URL — must be absolute for Slack/Twitter/Facebook to fetch it; `@nuxtjs/seo` does this conversion if `site.url` is set
- ❌ Indexing the application flow — `routeRules.sitemap.exclude` and `robots.disallow` both
- ❌ Skipping `site.url` config — every absolute URL produced by the SEO modules depends on it
- ❌ Putting SEO content behind `<ClientOnly>` — crawlers see the fallback, not the content

## Related

- **[rendering-strategies.md](./rendering-strategies.md)** — prerender vs SSR vs SPA per-route
- **[layouts.md](./layouts.md)** — where root-level `useSeoMeta` lives (`app.vue`)
- **[nuxt-testing/e2e-and-api.md](../../nuxt-testing/references/e2e-and-api.md)** — testing prerendered SEO output
