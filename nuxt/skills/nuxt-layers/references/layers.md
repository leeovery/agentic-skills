# Nuxt Layers Architecture

## Overview

The layer system provides shared functionality across Nuxt applications. Layers extend each other in a stack:

```
Your App
    ↓ extends
x-ui          → Extended UI components
    ↓ extends
nuxt-ui       → UI primitives + overlay management
    ↓ extends
base          → Core infrastructure
```

## Base Layer (`/nuxt-layers/base`)

Foundation layer providing core patterns and utilities.

### Composables (23 total)

| Composable | Purpose |
|------------|---------|
| `useRepository(name)` | Get typed repository instance |
| `useQuery(key, fetcher, options)` | Cached async data fetching |
| `useFilterQuery(key, fetcher, filters)` | Reactive filtered queries |
| `useWait()` | Global loading state management |
| `useFlash()` | Toast notification system |
| `usePermissions()` | Permission checking (can/cannot) |
| `useForm(url, method, data)` | Form state management |
| `useFormBuilder()` | Fluent form configuration |
| `useReactiveFilters(defaults)` | URL-synced reactive filters |
| `useRealtime()` | WebSocket channel subscriptions |
| `useShadowCache()` | In-memory LRU cache with TTL |
| `useErrorHandler()` | Centralized error handling |
| `useCreateContext()` | Typed Vue context creation |
| `useJsonSpec()` | JSON:API query builder |

### Models & Repositories

```typescript
// Model base class
import Model from '#layers/base/app/models/Model'

// Repository base class
import { BaseRepository } from '#layers/base/app/repositories/base-repository'
import { ModelHydrator } from '#layers/base/app/repositories/hydrators/model-hydrator'
```

### Error Classes

```typescript
import { ValidationError } from '#layers/base/app/errors/validation-error'
import { ConflictError } from '#layers/base/app/errors/conflict-error'
import { TooManyRequestsError } from '#layers/base/app/errors/too-many-requests-error'
```

### Type Definitions

```typescript
import type {
  Castable,
  DataResponse,
  CollectionResponse,
  PaginatedResponse,
  GenericQueryParams,
  Filters,
  FormError,
} from '#layers/base/app/types'
```

### Utilities (49 functions)

Organized by category:

```typescript
// Array utilities
import { wrap, unique, flatten } from '#layers/base/app/utils/array'

// String utilities
import { capitalize, truncate, formatBytes } from '#layers/base/app/utils/string'

// Object utilities
import { pick, combine, transformKeys, removeEmptyProperties } from '#layers/base/app/utils/object'

// Async utilities
import { sleep, retry } from '#layers/base/app/utils/async'

// Date utilities
import { formatDate, parseDate } from '#layers/base/app/utils/date'
```

### App Config Extensions

Base layer expects these in your `app.config.ts`:

```typescript
export default defineAppConfig({
  // Repository registration
  repositories: {
    posts: PostRepository,
    authors: AuthorRepository,
  },

  // HTTP interceptors
  interceptors: {
    request: [appendSourceInterceptor],
    response: [errorHandlerInterceptor],
  },

  // Error handlers by status code
  errorHandlers: {
    401: async ({ flash }) => navigateTo('/auth/login'),
    422: async ({ response }) => Promise.reject(new ValidationError(response)),
    500: async ({ flash }) => flash.error('Server error'),
  },

  // Permission system config
  permissions: {
    before: (permission) => {
      // Admin bypass
      if (user.isAdmin) return true
      return null
    },
  },

  // Shadow cache settings
  shadowCache: {
    maxSize: 100,
    defaultTtl: 5 * 60 * 1000,
  },
})
```

### Runtime Config

Environment variable mapping:

```bash
# Per-repository API base URLs
NUXT_PUBLIC_REPOSITORIES_POSTS_FETCH_OPTIONS_BASE_URL=https://api.example.com
NUXT_PUBLIC_REPOSITORIES_AUTHORS_FETCH_OPTIONS_BASE_URL=https://api.example.com

# Global settings
NUXT_PUBLIC_APP_URL=https://app.example.com
NUXT_PUBLIC_API_URL=https://api.example.com
```

---

## Nuxt-UI Layer (`/nuxt-layers/nuxt-ui`)

UI primitives built on Nuxt UI Pro.

### Composables (9 total)

| Composable | Purpose |
|------------|---------|
| `useModal()` | Programmatic modal control |
| `useSlideover()` | Drawer/panel management |
| `useConfirmationToast()` | Toast with confirm/cancel |
| `useOverlayManager()` | Stack management for overlays |
| `useTabs(items)` | Tab state with badges |
| `useDropdown(items)` | Dropdown with conditions |
| `useAppHeader()` | App header state |
| `useBreadcrumbs()` | Breadcrumb navigation |
| `useNavigation()` | Navigation utilities |

### Modal Usage

```typescript
const { open, close } = useModal('my-modal')

// Open with props
open({ title: 'Confirm Delete', item: post })

// In component
const modal = useModal('my-modal')
watch(modal.isOpen, (open) => {
  if (open) console.log('Props:', modal.props)
})
```

### Slideover Usage

```typescript
const { open: openCreate } = useSlideover('create-post')
const { open: openEdit } = useSlideover('edit-post')

// Open slideover
openCreate()
openEdit({ post: selectedPost })
```

### Confirmation Toast

```typescript
const { trigger } = useConfirmationToast()

trigger({
  title: 'Delete Post?',
  description: 'This action cannot be undone.',
  confirmLabel: 'Delete',
  cancelLabel: 'Cancel',
  onConfirm: async () => {
    await deletePostAction(post)
  },
})
```

### Components

| Component | Purpose |
|-----------|---------|
| `Copyable` | Copy text to clipboard |
| `SearchInput` | Debounced search input |
| `SearchSelect` | Searchable dropdown |
| `Rating` | Star rating display/input |
| `LoadingLine` | Progress indicator |
| `Tooltipable` | Tooltip wrapper |

---

## X-UI Layer (`/nuxt-layers/x-ui`)

Extended UI components for applications.

### Components

| Component | Purpose |
|-----------|---------|
| `XTable` | Advanced table with TanStack Table |
| `XForm` | Form wrapper with validation |
| `XSlideover` | Enhanced slideover |
| `XCard` | Card container |
| `XActionDropdown` | Action button dropdown |
| `XPagination` | Pagination controls |
| `XDrillDownList` | Hierarchical navigation |
| `XDrillDownSelector` | Multi-level selector |

### XTable Usage

```vue
<XTable
  :data="posts"
  :columns="columns"
  :loading="isLoading"
  :fetching="isFetching"
  :row-actions="rowActions"
  row-id="ulid"
  @row-click="handleRowClick"
/>
```

### XForm Usage

```vue
<XForm
  ref="formRef"
  url="/api/posts"
  method="POST"
  :data="formData"
  :waiting="waitingFor.posts.creating"
  @submit="onSubmit"
  @success="onSuccess"
  @error="onError"
>
  <!-- Form fields -->
  <template #actions>
    <UButton type="submit" label="Create" />
  </template>
</XForm>
```

---

## Layer Configuration

### nuxt.config.ts Pattern

```typescript
// In your app's nuxt.config.ts
export default defineNuxtConfig({
  // Extend layers (order matters - later overrides earlier)
  extends: [
    '../../../nuxt-layers/base',
    '../../../nuxt-layers/nuxt-ui',
    '../../../nuxt-layers/x-ui',
  ],

  // SPA mode
  ssr: false,

  // Components without path prefix
  components: [{ path: 'components', pathPrefix: false }],

  // Modules
  modules: [
    'nuxt-auth-sanctum',
    '@nuxt/ui',
  ],
})
```

### Module Augmentation

Each layer declares TypeScript module augmentations:

```typescript
// In layer's app.config.ts
declare module 'nuxt/schema' {
  interface AppConfigInput {
    repositories?: Record<string, RepositoryClass>
    errorHandlers?: Record<number, ErrorHandler>
  }
}
```

---

## Creating New Layers

### Layer Structure

```
my-layer/
├── app/
│   ├── composables/     # Auto-imported
│   ├── components/      # Auto-imported
│   ├── utils/           # Auto-imported
│   └── types/
├── nuxt.config.ts
├── app.config.ts
└── package.json
```

### Layer nuxt.config.ts

```typescript
export default defineNuxtConfig({
  // Can extend other layers
  extends: ['../base'],

  // Auto-import directories
  imports: {
    dirs: ['app/composables', 'app/utils'],
  },

  // Component configuration
  components: [
    { path: 'app/components', pathPrefix: false },
  ],
})
```

### Publishing Layers

Layers can be:
1. **Local paths** - `../../../nuxt-layers/base`
2. **npm packages** - `@org/nuxt-layer-base`
3. **GitHub** - `github:org/nuxt-layers/base#v4.0.0`

---

## Common Patterns

### Accessing Layer Composables

All composables are auto-imported:

```typescript
// No import needed - auto-imported from layers
const postApi = useRepository('posts')
const { start, stop, waitingFor } = useWait()
const flash = useFlash()
const { can, cannot } = usePermissions()
```

### Overriding Layer Components

Components in your app override layer components with the same name:

```
layers/x-ui/app/components/XTable.vue    # Default
app/components/XTable.vue                 # Your override (takes precedence)
```

### Extending Layer Composables

```typescript
// Wrap a layer composable with additional logic
export function useExtendedFlash() {
  const flash = useFlash()

  return {
    ...flash,
    successWithSound: (message: string) => {
      playSound('success')
      flash.success(message)
    },
  }
}
```
