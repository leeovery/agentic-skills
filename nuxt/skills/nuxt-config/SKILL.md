---
name: nuxt-config
description: Nuxt and app configuration files. Use when configuring nuxt.config.ts, app.config.ts, environment variables, runtime config, or understanding how configuration flows through the application.
---

# Nuxt Configuration

Application configuration via nuxt.config.ts and app.config.ts.

## Core Concepts

**[config.md](references/config.md)** - Complete configuration patterns

**[cloudflare-deployment.md](references/cloudflare-deployment.md)** - Cloudflare Workers Builds + NuxtHub + D1: `routeRules` hybrid rendering, the wrangler.json binding reconciliation gotcha (vars wiped on deploy), secrets vs `nitro.cloudflare.wrangler.vars`, manual D1 migration flow, build-time vs runtime env vars, common build failures

## nuxt.config.ts

```typescript
export default defineNuxtConfig({
  ssr: false,  // SPA mode

  extends: [
    '../../../nuxt-layers/base',
    '../../../nuxt-layers/nuxt-ui',
    '../../../nuxt-layers/x-ui',
  ],

  modules: ['nuxt-auth-sanctum', '@nuxt/ui'],

  components: [{ path: 'components', pathPrefix: false }],

  sanctum: {
    baseUrl: process.env.NUXT_PUBLIC_API_URL,
    endpoints: { login: '/auth/login', user: '/auth/user' },
  },

  runtimeConfig: {
    public: {
      apiUrl: undefined,  // Set via NUXT_PUBLIC_API_URL
    },
  },
})
```

## app.config.ts

```typescript
export default defineAppConfig({
  repositories: {
    leads: LeadRepository,
    contacts: ContactRepository,
  },

  interceptors: {
    request: [appendSource],
    response: [errorHandler],
  },

  errorHandlers: {
    401: async ({ flash }) => navigateTo('/auth/login'),
    422: async ({ response }) => new ValidationError(response),
  },
})
```

## Environment Variables

```bash
NUXT_PUBLIC_API_URL=https://api.example.com
NUXT_PUBLIC_REPOSITORIES_LEADS_FETCH_OPTIONS_BASE_URL=https://leads-api.example.com
```
