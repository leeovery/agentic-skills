# Configuration Patterns

## nuxt.config.ts

Build-time configuration for Nuxt:

```typescript
// nuxt.config.ts
import tailwindcss from '@tailwindcss/vite'

export default defineNuxtConfig({
  // SPA mode - SSR disabled
  ssr: false,

  // Extend shared layers
  extends: [
    '../../../nuxt-layers/base',
    '../../../nuxt-layers/nuxt-ui',
    '../../../nuxt-layers/x-ui',
  ],

  // Compatibility
  compatibilityDate: '2025-07-15',

  // Modules
  modules: [
    'nuxt-auth-sanctum',
    'reka-ui/nuxt',
    '@nuxt/ui',
  ],

  // Dev tools
  devtools: { enabled: true },

  // Component auto-import without path prefix
  components: [{ path: 'components', pathPrefix: false }],

  // Sanctum authentication
  sanctum: {
    baseUrl: process.env.NUXT_PUBLIC_API_URL,
    endpoints: {
      login: '/login',
      user: '/user',
      csrf: '/csrf-cookie',
      logout: '/logout',
    },
    csrf: {
      cookie: 'XSRF-TOKEN',
      header: 'X-XSRF-TOKEN',
    },
    redirect: {
      onAuthOnly: '/auth/login',
      onGuestOnly: '/',
      onLogin: '/',
      onLogout: '/auth/login',
    },
  },

  // Icon configuration
  icon: {
    serverBundle: 'remote',
    clientBundle: {
      scan: true,
      includeCustomCollections: true,
    },
    customCollections: [
      {
        prefix: 'custom',
        dir: './app/assets/icons',
      },
    ],
  },

  // Runtime configuration
  runtimeConfig: {
    public: {
      // API URLs
      apiUrl: undefined,

      // Repository-specific configurations
      repositories: {
        leads: { fetchOptions: { baseURL: undefined } },
        contacts: { fetchOptions: { baseURL: undefined } },
      },

      // External service URLs
      externalService: { urls: { api: undefined } },
    },
  },

  // UI configuration
  ui: {
    colorMode: false,
    theme: {
      colors: ['primary', 'secondary', 'success', 'info', 'warning', 'error', 'neutral'],
    },
  },

  // Tailwind CSS
  css: ['~/assets/css/main.css'],
  vite: {
    plugins: [tailwindcss()],
  },
})
```

---

## app.config.ts

Runtime application configuration:

```typescript
// app.config.ts
import LeadRepository from '~/repositories/LeadRepository'
import ContactRepository from '~/repositories/ContactRepository'
import SecureLinkRepository from '~/repositories/SecureLinkRepository'
import appendSource from '~/interceptors/request/append-source'
import errorHandler from '~/interceptors/response/error-handler'
import { ValidationError } from '#layers/base/app/errors/validation-error'

export default defineAppConfig({
  // Repository registration
  repositories: {
    // Simple format (uses global apiUrl)
    leads: LeadRepository,
    contacts: ContactRepository,
    secureLinks: SecureLinkRepository,

    // Extended format with custom fetch options
    externalService: {
      repository: ExternalRepository,
      fetchOptions: {
        baseURL: 'https://external-api.example.com',
        timeout: 30000,
      },
    },
  },

  // HTTP interceptors
  interceptors: {
    request: [appendSource],
    response: [errorHandler],
  },

  // Error handlers by status code
  errorHandlers: {
    401: async ({ response, flash }) => {
      flash.error('Session expired. Please log in again.')
      return navigateTo('/auth/login')
    },
    403: async ({ flash }) => {
      flash.error('Access denied.')
    },
    404: '/not-found',  // String = redirect path
    422: async ({ response }) => {
      return Promise.reject(new ValidationError(response))
    },
    429: async ({ response }) => {
      return Promise.reject(new TooManyRequestsError(response))
    },
    500: async ({ flash }) => {
      flash.error('Server error. Please try again later.')
    },
  },

  // Permission system
  permissions: {
    before: (permission) => {
      const { user } = useUser()
      if (user.value?.isAdmin) return true
      return null  // Check normally
    },
  },

  // Shadow cache settings
  shadowCache: {
    maxSize: 100,
    defaultTtl: 5 * 60 * 1000,  // 5 minutes
  },

  // Query settings
  useQuery: {
    staleTime: 30 * 1000,  // 30 seconds
  },
})
```

---

## Environment Variables

### Standard Variables

```bash
# .env
# API Configuration
NUXT_PUBLIC_API_URL=https://api.example.com

# External services
NUXT_PUBLIC_EXTERNAL_SERVICE_URLS_API=https://external.example.com

# WebSocket (Laravel Echo)
NUXT_PUBLIC_ECHO_KEY=your-pusher-key
NUXT_PUBLIC_ECHO_HOST=echo.example.com
NUXT_PUBLIC_ECHO_SCHEME=https
NUXT_PUBLIC_ECHO_PORT=443
```

### Per-Repository Overrides

```bash
# Override specific repository base URLs
NUXT_PUBLIC_REPOSITORIES_LEADS_FETCH_OPTIONS_BASE_URL=https://leads-api.example.com
NUXT_PUBLIC_REPOSITORIES_CONTACTS_FETCH_OPTIONS_BASE_URL=https://contacts-api.example.com
```

### Environment Files

```
.env           # Default environment
.env.local     # Local overrides (not committed)
.env.live      # Production environment
```

---

## Runtime Config Access

```typescript
// In components/composables
const config = useRuntimeConfig()

// Public config
const apiUrl = config.public.apiUrl

// Repository config
const leadsBaseUrl = config.public.repositories.leads.fetchOptions.baseURL
```

---

## App Config Access

```typescript
// In components/composables
const appConfig = useAppConfig()

// Access repositories config
const repoConfig = appConfig.repositories

// Access error handlers
const handlers = appConfig.errorHandlers
```

---

## tsconfig.json

```json
{
  "extends": "./.nuxt/tsconfig.json",
  "compilerOptions": {
    "strictPropertyInitialization": false
  }
}
```

---

## eslint.config.mjs

```javascript
import withNuxt from './.nuxt/eslint.config.mjs'

export default withNuxt({
  rules: {
    'vue/multi-word-component-names': 'off',
    'vue/no-v-html': 'off',
    '@typescript-eslint/no-unused-vars': ['error', { argsIgnorePattern: '^_' }],
    'import/order': 'off',
  },
})
```

---

## Tailwind CSS Configuration

```css
/* app/assets/css/main.css */
@import "tailwindcss";

@theme {
  /* Custom colors */
  --color-primary-50: oklch(0.97 0.02 250);
  --color-primary-500: oklch(0.55 0.2 250);
  --color-primary-900: oklch(0.25 0.1 250);

  /* Custom fonts */
  --font-sans: 'Inter', sans-serif;
}
```

---

## Configuration Hierarchy

```
nuxt.config.ts       → Build-time, modules, layers
    ↓
app.config.ts        → Runtime, repositories, handlers
    ↓
.env                 → Environment-specific values
    ↓
runtimeConfig        → Merged and available at runtime
```

---

## Module Augmentation

Extend TypeScript types for config:

```typescript
// In app.config.ts
declare module 'nuxt/schema' {
  interface AppConfigInput {
    repositories?: Record<string, RepositoryClass | RepositoryConfig>
    errorHandlers?: Record<number, ErrorHandler>
    permissions?: { before?: (permission: string) => boolean | null }
  }
}

export default defineAppConfig({
  // TypeScript now knows these types
})
```

---

## Related Skills

- **[nuxt-layers](../../nuxt-layers/SKILL.md)** - Layer configuration
- **[nuxt-repositories](../../nuxt-repositories/SKILL.md)** - Repository registration
- **[nuxt-errors](../../nuxt-errors/SKILL.md)** - Error handlers
