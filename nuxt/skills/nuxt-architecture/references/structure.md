# Project Structure

## Complete Directory Layout

```
project-root/
├── app/                              # Main application code
│   ├── assets/
│   │   ├── css/main.css             # Global styles (Tailwind)
│   │   └── images/
│   ├── components/                   # Vue components by type
│   │   ├── Common/                  # Shared/generic (Logo.vue)
│   │   ├── Detail/                  # Detail views (PostDetail.vue)
│   │   ├── Form/                    # Form inputs (AuthorEmailInput.vue)
│   │   ├── Modals/                  # Modal dialogs (DeletePostModal.vue)
│   │   ├── Nav/                     # Navigation (UserMenu.vue)
│   │   ├── Slideovers/              # Slideout panels (CreatePostSlideover.vue)
│   │   ├── TabSections/             # Tab content (PostCommentsTab.vue)
│   │   └── Tables/                  # Table components (PostsTable.vue)
│   ├── composables/                 # Custom Vue composables
│   │   ├── useUser.ts
│   │   ├── useCategories.ts
│   │   └── useHandleActionError.ts
│   ├── constants/                   # App-wide constants
│   │   ├── channels.ts              # WebSocket channel names
│   │   ├── events.ts                # Event names
│   │   ├── permissions.ts           # Permission strings
│   │   └── symbols.ts               # Vue injection symbols
│   ├── enums/                       # TypeScript enums with behavior
│   │   ├── PostStatus.ts
│   │   ├── CommentStatus.ts
│   │   └── UserRole.ts
│   ├── errors/                      # Custom error classes
│   │   └── (optional app-specific)
│   ├── features/                    # Domain-based feature modules
│   │   ├── posts/
│   │   │   ├── queries/
│   │   │   │   ├── get-posts-query.ts
│   │   │   │   └── get-post-query.ts
│   │   │   ├── mutations/
│   │   │   │   ├── create-post-mutation.ts
│   │   │   │   ├── update-post-mutation.ts
│   │   │   │   └── delete-post-mutation.ts
│   │   │   └── actions/
│   │   │       ├── create-post-action.ts
│   │   │       ├── update-post-action.ts
│   │   │       └── delete-post-action.ts
│   │   ├── authors/
│   │   ├── comments/
│   │   └── tags/
│   ├── interceptors/                # HTTP interceptors
│   │   ├── request/                 # Request interceptors
│   │   │   └── append-source.ts
│   │   └── response/                # Response interceptors
│   │       └── error-handler.ts
│   ├── layouts/                     # Page layouts
│   │   ├── auth.vue                 # Auth layout (login pages)
│   │   └── default.vue              # Main app layout
│   ├── models/                      # Domain models
│   │   ├── Post.ts
│   │   ├── Author.ts
│   │   ├── Comment.ts
│   │   ├── Tag.ts
│   │   └── User.ts
│   ├── pages/                       # File-based routing
│   │   ├── index.vue                # Dashboard/redirect
│   │   ├── profile.vue
│   │   ├── auth/
│   │   │   └── login.vue
│   │   ├── posts/
│   │   │   ├── index.vue            # List view
│   │   │   └── [ulid].vue           # Detail view
│   │   └── authors/
│   │       ├── index.vue
│   │       └── [ulid].vue
│   ├── plugins/                     # Nuxt plugins
│   │   ├── fetch.ts                 # Register fetch provider
│   │   ├── init.ts                  # Initialize user/app state
│   │   └── session.ts               # Session expiry handling
│   ├── repositories/                # Data access layer
│   │   ├── PostRepository.ts
│   │   ├── AuthorRepository.ts
│   │   └── CommentRepository.ts
│   ├── tables/                      # Table column configurations
│   │   ├── posts.ts
│   │   └── authors.ts
│   ├── types/                       # TypeScript definitions
│   │   └── index.ts
│   ├── utils/                       # Utility functions
│   │   └── createColumnBuilder.ts
│   ├── values/                      # Value objects
│   │   └── DateValue.ts
│   └── app.vue                      # Root component
├── public/                          # Static assets
├── .env                             # Environment variables
├── nuxt.config.ts                   # Nuxt configuration
├── app.config.ts                    # App configuration
├── package.json
└── tsconfig.json
```

## Directory Purposes

### `/app/components/`

Organized by **UI pattern**, not domain:

| Folder | Purpose | Example |
|--------|---------|---------|
| `Common/` | Shared utilities | `Copyable.vue`, `LoadingLine.vue` |
| `Detail/` | Entity detail views | `PostDetail.vue`, `AuthorDetail.vue` |
| `Form/` | Reusable form inputs | `AuthorEmailInput.vue` |
| `Modals/` | Confirmation/action modals | `DeletePostModal.vue` |
| `Nav/` | Navigation elements | `UserMenu.vue`, `Sidebar.vue` |
| `Slideovers/` | Slideout panels | `CreatePostSlideover.vue` |
| `TabSections/` | Tab content sections | `PostCommentsTab.vue` |
| `Tables/` | Data tables | `PostsTable.vue` |

### `/app/features/`

Organized by **domain**:

```
features/{domain}/
├── queries/      # Read operations (useFilterQuery)
├── mutations/    # Write operations (API calls)
└── actions/      # Business logic + UI
```

Each domain maps to a model/resource. Feature modules are the primary pattern for new Nuxt apps.

### `/app/constants/`

Static values shared across the app:

```typescript
// channels.ts - WebSocket channels
export const Posts = 'posts'
export const Post = 'post.{post}'

// events.ts - Event names
export const PostCreated = 'PostCreated'
export const PostUpdated = 'PostUpdated'

// permissions.ts - Permission strings
export const ListPosts = 'posts.list'
export const CreatePost = 'posts.create'

// symbols.ts - Vue injection keys
export const SlideoverKey = Symbol('slideover')
```

## Configuration Files

### `nuxt.config.ts`

```typescript
export default defineNuxtConfig({
  ssr: false,  // SPA mode

  extends: [
    '../../../nuxt-layers/base',
    '../../../nuxt-layers/nuxt-ui',
    '../../../nuxt-layers/x-ui',
  ],

  modules: [
    'nuxt-auth-sanctum',
    '@nuxt/ui',
  ],

  components: [{ path: 'components', pathPrefix: false }],
})
```

### `app.config.ts`

```typescript
export default defineAppConfig({
  repositories: {
    posts: PostRepository,
    authors: AuthorRepository,
  },

  interceptors: {
    request: [appendSource],
    response: [errorHandler],
  },

  errorHandlers: {
    401: async ({ flash }) => { /* ... */ },
    422: async ({ response }) => new ValidationError(response),
  },
})
```

## Layer Imports

Use layer aliases for base layer imports:

```typescript
// Import from layers
import Model from '#layers/base/app/models/Model'
import type { Castable } from '#layers/base/app/types'
import { BaseRepository } from '#layers/base/app/repositories/base-repository'

// Import from app (use ~ alias)
import Post from '~/models/Post'
import { ListPosts } from '~/constants/permissions'
```

## New Feature Checklist

When adding a new domain feature:

1. Create model in `models/`
2. Create enum(s) in `enums/` if needed
3. Create repository in `repositories/`
4. Register repository in `app.config.ts`
5. Create feature module in `features/{domain}/`
   - `queries/get-{domain}s-query.ts`
   - `mutations/create-{domain}-mutation.ts`
   - `actions/create-{domain}-action.ts`
6. Create table config in `tables/`
7. Create components in `components/`
8. Create pages in `pages/`
