# Query Patterns

Queries provide reactive data fetching with automatic refetching when filters change.

## Core Composables

```typescript
// Simple cached query
useQuery(key, fetcher, options)

// Query with reactive filters (auto-refetch)
useFilterQuery(key, fetcher, filters)
```

---

## Basic Query (No Filters)

```typescript
// app/features/posts/queries/get-post-query.ts
import type { MaybeRef } from 'vue'

export default function getPostQueryFactory() {
  const postApi = useRepository('posts')

  return (ulid: MaybeRef<string>) => {
    return useQuery(`post-${toValue(ulid)}`, () => {
      const params = useJsonSpec()
        .include('author', 'comments', 'tags')
        .build()

      return postApi.get(toValue(ulid), params)
    })
  }
}
```

### Usage

```typescript
const ulid = computed(() => route.params.ulid as string)
const getPostQuery = getPostQueryFactory()
const { data: post, isLoading, refresh } = getPostQuery(ulid)

// Access data
post.value?.data.author.name
```

---

## Filter Query (Auto-Refetch)

```typescript
// app/features/posts/queries/get-posts-query.ts
import type { Filters } from '#layers/base/app/types'
import type { MaybeRef } from 'vue'
import { KebabCase } from '#layers/base/app/utils'

// Define filter interface
export interface GetPostsFilters extends Pick<Filters, 'page' | 'size' | 'search'> {
  status?: string
  isDraft?: boolean
}

export default function getPostsQueryFactory() {
  const postApi = useRepository('posts')

  return (filters: MaybeRef<GetPostsFilters>) => {
    return useFilterQuery('posts', () => {
      // Build JSON:API spec params
      const params = useJsonSpec()
        .filters(filters, KebabCase)           // Apply filters
        .include('author', 'tags')              // Include relations
        .build()

      return postApi.list(params)
    }, filters)
  }
}
```

### Usage

```typescript
// Set up reactive filters
const { filters } = useReactiveFilters<GetPostsFilters>({
  status: undefined,
  isDraft: undefined,
  page: 1,
  size: 25,
})

// Create query
const getPostsQuery = getPostsQueryFactory()
const { data: posts, refresh, isLoading, isFetching, pagination } = getPostsQuery(filters)

// Data auto-refetches when filters change
filters.status = 'published'  // Triggers refetch
filters.page = 2              // Triggers refetch
```

---

## useJsonSpec Builder

Build JSON:API query parameters:

```typescript
const params = useJsonSpec()
  // Apply filters from reactive object
  .filters(filters, KebabCase)

  // Include relations
  .include('author', 'comments')

  // Or include as array
  .include(['author', 'comments'])

  // Sort
  .sort('-created_at', 'title')

  // Sparse fieldsets
  .fields('posts', ['ulid', 'title', 'created_at'])

  // Build final params object
  .build()
```

### Filter Transformation

```typescript
// KebabCase transforms filter keys: isDraft → is-draft
.filters(filters, KebabCase)

// No transformation
.filters(filters)

// Custom transformation
.filters(filters, (key) => key.toUpperCase())
```

---

## Query Return Values

```typescript
const {
  data,        // Reactive data (Ref<T>)
  isLoading,   // Initial load in progress
  isFetching,  // Any fetch in progress (including refetch)
  error,       // Error if failed
  refresh,     // Manual refresh function
  pagination,  // Pagination metadata (if paginated)
} = getPostsQuery(filters)
```

### Pagination Object

```typescript
pagination: {
  currentPage: number
  lastPage: number
  perPage: number
  total: number
  from: number
  to: number
}
```

---

## Complete Query Examples

### Posts by Author

```typescript
// app/features/posts/queries/get-posts-by-author-query.ts
export interface GetPostsByAuthorFilters extends Pick<Filters, 'page' | 'size'> {
  status?: string
}

export default function getPostsByAuthorQueryFactory() {
  const postApi = useRepository('posts')

  return (authorUlid: MaybeRef<string>, filters: MaybeRef<GetPostsByAuthorFilters>) => {
    return useFilterQuery(`posts-by-author-${toValue(authorUlid)}`, () => {
      const params = useJsonSpec()
        .filters(filters, KebabCase)
        .include('tags')
        .build()

      return postApi.listByAuthor(toValue(authorUlid), params)
    }, filters)
  }
}
```

### Users with Search

```typescript
// app/features/users/queries/get-users-query.ts
export interface GetUsersFilters extends Pick<Filters, 'page' | 'size' | 'search'> {}

export default function getUsersQueryFactory() {
  const userApi = useRepository('users')

  return (filters: MaybeRef<GetUsersFilters>) => {
    return useFilterQuery('users', () => {
      const params = useJsonSpec()
        .filters(filters, KebabCase)
        .include('posts')
        .build()

      return userApi.list(params)
    }, filters)
  }
}
```

### Comments by Status

```typescript
// app/features/comments/queries/get-comments-query.ts
export interface GetCommentsFilters extends Pick<Filters, 'page' | 'size'> {
  status?: string
  approved?: boolean
}

export default function getCommentsQueryFactory() {
  const commentApi = useRepository('comments')

  return (filters: MaybeRef<GetCommentsFilters>) => {
    return useFilterQuery('comments', () => {
      const params = useJsonSpec()
        .filters(filters, KebabCase)
        .sort('-created_at')
        .build()

      return commentApi.list(params)
    }, filters)
  }
}
```

---

## Using Queries in Pages

```vue
<script lang="ts" setup>
import getPostsQueryFactory, { type GetPostsFilters } from '~/features/posts/queries/get-posts-query'

// Set up filters
const { filters, hasFilters, resetFilters } = useReactiveFilters<GetPostsFilters>({
  status: undefined,
  isDraft: undefined,
  page: 1,
  size: 25,
}, {
  syncWithUrl: true,
})

// Create query
const getPostsQuery = getPostsQueryFactory()
const { data: posts, refresh, isLoading, isFetching, pagination } = getPostsQuery(filters)
</script>

<template>
  <div>
    <!-- Loading state -->
    <LoadingLine v-if="isFetching" />

    <!-- Filters -->
    <USelect v-model="filters.status" :options="statusOptions" />

    <!-- Data -->
    <PostsTable :posts="posts?.data || []" :loading="isLoading" />

    <!-- Pagination -->
    <XPagination v-if="pagination" v-model:page="filters.page" :pagination="pagination" />
  </div>
</template>
```

---

## Naming Conventions

| Convention | Example |
|------------|---------|
| File name | `get-{resource}-query.ts`, `get-{resource}s-query.ts` |
| Factory function | `get{Resource}QueryFactory`, `get{Resource}sQueryFactory` |
| Filter interface | `Get{Resource}Filters`, `Get{Resource}sFilters` |

---

## Related Skills

- **[nuxt-repositories](../../nuxt-repositories/SKILL.md)** - Repositories used in queries
- **[nuxt-composables](../../nuxt-composables/SKILL.md)** - useReactiveFilters details
