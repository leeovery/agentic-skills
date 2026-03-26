# Page Patterns

## File-Based Routing

Nuxt generates routes from the `pages/` directory structure:

| File | Route |
|------|-------|
| `pages/index.vue` | `/` |
| `pages/profile.vue` | `/profile` |
| `pages/posts/index.vue` | `/posts` |
| `pages/posts/[ulid].vue` | `/posts/:ulid` |
| `pages/auth/login.vue` | `/auth/login` |

### Dynamic Routes

```
pages/
├── posts/
│   ├── index.vue          # /posts
│   └── [ulid].vue         # /posts/:ulid
└── users/
    ├── index.vue          # /users
    └── [ulid]/
        ├── index.vue      # /users/:ulid
        └── edit.vue       # /users/:ulid/edit
```

---

## Page Meta

### Permissions

```typescript
// Single permission
definePageMeta({
  permissions: 'posts.list',
})

// Multiple permissions (any of)
definePageMeta({
  permissions: ['posts.list', 'users.list'],
})
```

### Layouts

```typescript
// Use specific layout
definePageMeta({
  layout: 'auth',
})

// Disable layout
definePageMeta({
  layout: false,
})
```

### Middleware

```typescript
// Apply middleware
definePageMeta({
  middleware: ['auth', 'verified'],
})
```

---

## Complete List Page

```vue
<!-- app/pages/posts/index.vue -->
<script lang="ts" setup>
import getPostsQueryFactory, { type GetPostsFilters } from '~/features/posts/queries/get-posts-query'
import deletePostActionFactory from '~/features/posts/actions/delete-post-action'
import { ListPosts, CreatePost } from '~/constants/permissions'
import type { Row } from '@tanstack/vue-table'

// Page meta
definePageMeta({
  permissions: ListPosts,
})

// Page header
const { setAppHeader } = useAppHeader()
setAppHeader({
  title: 'Posts',
  icon: 'lucide:file-text',
})

// Breadcrumbs
const { setBreadcrumbs } = useBreadcrumbs()
setBreadcrumbs([
  { label: 'Content' },
  { label: 'Posts' },
])

// Reactive filters
const { filters, hasFilters, resetFilters } = useReactiveFilters<GetPostsFilters>({
  status: undefined,
  isDraft: undefined,
  page: 1,
  size: 25,
}, {
  syncWithUrl: true,
})

// Query
const getPostsQuery = getPostsQueryFactory()
const { data: posts, refresh, isLoading, isFetching, pagination } = getPostsQuery(filters)

// Actions
const deletePostAction = deletePostActionFactory()

// Slideovers
const { open: openCreateSlideover } = useSlideover('create-post')
const { open: openUpdateSlideover } = useSlideover('update-post')

// Delete handling
const deletePosts = async (items: Post[]) => {
  for (const post of items) {
    await deletePostAction(post)
  }
  refresh()
}

// Row actions
const tableRowActions = computed(() => (row: Row<Post>) => [
  { label: 'View post', to: `/posts/${row.original.ulid}` },
  { label: 'View author', to: `/users/${row.original.author.ulid}` },
  { label: 'Edit post', onSelect: () => openUpdateSlideover({ post: row.original }) },
  { label: 'Delete post', onSelect: () => deletePosts([row.original]) },
])
</script>

<template>
  <div>
    <!-- Toolbar -->
    <div class="flex items-center gap-4 mb-4">
      <UInput
        v-model="filters.search"
        placeholder="Search..."
        icon="i-heroicons-magnifying-glass"
      />

      <USelect
        v-model="filters.status"
        :options="PostStatus.values()"
        placeholder="Status"
        option-attribute="text"
      />

      <UButton
        v-if="hasFilters"
        variant="ghost"
        @click="resetFilters"
      >
        Clear filters
      </UButton>

      <div class="flex-1" />

      <UButton
        v-if="can(CreatePost)"
        icon="i-heroicons-plus"
        label="Create Post"
        @click="openCreateSlideover()"
      />
    </div>

    <!-- Loading indicator -->
    <LoadingLine v-if="isFetching" />

    <!-- Table -->
    <PostsTable
      :posts="posts?.data || []"
      :loading="isLoading"
      :fetching="isFetching"
      :row-actions="tableRowActions"
      @delete="deletePosts"
    />

    <!-- Pagination -->
    <XPagination
      v-if="pagination"
      v-model:page="filters.page"
      :pagination="pagination"
      class="mt-4"
    />

    <!-- Slideovers -->
    <CreatePostSlideover @close="refresh" />
    <UpdatePostSlideover @close="refresh" />
  </div>
</template>
```

---

## Complete Detail Page

```vue
<!-- app/pages/posts/[ulid].vue -->
<script lang="ts" setup>
import getPostQueryFactory from '~/features/posts/queries/get-post-query'
import deletePostActionFactory from '~/features/posts/actions/delete-post-action'
import { ShowPost, UpdatePost, DeletePost } from '~/constants/permissions'

// Page meta
definePageMeta({
  permissions: ShowPost,
})

// Route params
const route = useRoute()
const router = useRouter()
const ulid = computed(() => route.params.ulid as string)

// Query
const getPostQuery = getPostQueryFactory()
const { data: post, isLoading, refresh } = getPostQuery(ulid)

// Actions
const deletePostAction = deletePostActionFactory()

// Page header (reactive)
const { setAppHeader } = useAppHeader()
watch(post, (p) => {
  if (p) {
    setAppHeader({
      title: p.data.title,
      subtitle: p.data.author.name,
      icon: 'lucide:file-text',
    })
  }
}, { immediate: true })

// Breadcrumbs (reactive)
const { setBreadcrumbs } = useBreadcrumbs()
watch(post, (p) => {
  if (p) {
    setBreadcrumbs([
      { label: 'Content' },
      { label: 'Posts', to: '/posts' },
      { label: p.data.title },
    ])
  }
}, { immediate: true })

// Tabs
const tabs = computed(() => [
  { label: 'Details', slot: 'details' },
  {
    label: 'Comments',
    slot: 'comments',
    badge: post.value?.data.commentsCount,
  },
  {
    label: 'Tags',
    slot: 'tags',
    badge: post.value?.data.tagsCount,
  },
])

// Slideover
const { open: openEditSlideover } = useSlideover('edit-post')

// Delete
const handleDelete = async () => {
  if (!post.value) return
  await deletePostAction(post.value.data)
  router.push('/posts')
}

// Real-time updates
const { privateChannel } = useRealtime()
privateChannel(Post, ulid.value).on(PostUpdated, refresh)
</script>

<template>
  <div>
    <!-- Loading -->
    <USkeleton v-if="isLoading" class="h-96" />

    <!-- Content -->
    <div v-else-if="post">
      <!-- Actions -->
      <div class="flex gap-2 mb-4">
        <UButton
          v-if="can(UpdatePost)"
          @click="openEditSlideover({ post: post.data })"
        >
          Edit
        </UButton>
        <UButton
          v-if="can(DeletePost)"
          color="error"
          @click="handleDelete"
        >
          Delete
        </UButton>
      </div>

      <!-- Tabs -->
      <UTabs :items="tabs">
        <template #details>
          <PostDetail :post="post.data" />
        </template>

        <template #comments>
          <PostCommentsTab :post="post.data" />
        </template>

        <template #tags>
          <PostTagsTab :post="post.data" />
        </template>
      </UTabs>
    </div>

    <!-- Slideover -->
    <UpdatePostSlideover @close="refresh" />
  </div>
</template>
```

---

## Layouts

### Default Layout

```vue
<!-- app/layouts/default.vue -->
<script lang="ts" setup>
const { appHeader } = useAppHeader()
const { breadcrumbs } = useBreadcrumbs()
</script>

<template>
  <div class="flex min-h-screen">
    <!-- Sidebar -->
    <aside class="w-64 border-r">
      <Sidebar />
    </aside>

    <!-- Main content -->
    <main class="flex-1">
      <!-- Header -->
      <header class="border-b p-4">
        <div class="flex items-center gap-2">
          <UIcon v-if="appHeader.icon" :name="appHeader.icon" />
          <h1 class="text-xl font-semibold">{{ appHeader.title }}</h1>
        </div>
        <p v-if="appHeader.subtitle" class="text-sm text-gray-500">
          {{ appHeader.subtitle }}
        </p>
        <UBreadcrumb :items="breadcrumbs" class="mt-2" />
      </header>

      <!-- Page content -->
      <div class="p-4">
        <slot />
      </div>
    </main>
  </div>
</template>
```

### Auth Layout

```vue
<!-- app/layouts/auth.vue -->
<template>
  <div class="min-h-screen flex items-center justify-center">
    <div class="w-full max-w-md">
      <slot />
    </div>
  </div>
</template>
```

---

## Navigation Helpers

### useAppHeader

```typescript
const { setAppHeader, appHeader } = useAppHeader()

setAppHeader({
  title: 'Posts',
  subtitle: 'Manage your posts',
  icon: 'lucide:file-text',
})
```

### useBreadcrumbs

```typescript
const { setBreadcrumbs, breadcrumbs } = useBreadcrumbs()

setBreadcrumbs([
  { label: 'Content' },
  { label: 'Posts', to: '/posts' },
  { label: 'My Post' },
])
```

---

## Redirect Page

```vue
<!-- app/pages/index.vue -->
<script lang="ts" setup>
// Redirect to posts on root
definePageMeta({
  redirect: '/posts',
})
</script>
```

---

## Login Page

```vue
<!-- app/pages/auth/login.vue -->
<script lang="ts" setup>
definePageMeta({
  layout: 'auth',
  middleware: 'guest',  // Only for unauthenticated
})

const { login } = useSanctumAuth()
const router = useRouter()

const credentials = ref({
  email: '',
  password: '',
})

const onSubmit = async () => {
  await login(credentials.value)
  router.push('/')
}
</script>

<template>
  <XCard title="Login">
    <form @submit.prevent="onSubmit">
      <UFormField label="Email" name="email">
        <UInput v-model="credentials.email" type="email" />
      </UFormField>

      <UFormField label="Password" name="password">
        <UInput v-model="credentials.password" type="password" />
      </UFormField>

      <UButton type="submit" class="w-full mt-4">
        Login
      </UButton>
    </form>
  </XCard>
</template>
```

---

## Naming Conventions

| File | Convention |
|------|------------|
| List pages | `index.vue` in resource folder |
| Detail pages | `[param].vue` with param name |
| Nested routes | Folder structure matches URL |
| Layouts | kebab-case in `layouts/` |

---

## Related Skills

- **[nuxt-auth](../../nuxt-auth/SKILL.md)** - Page authorization
- **[nuxt-components](../../nuxt-components/SKILL.md)** - Component patterns
- **[nuxt-features](../../nuxt-features/SKILL.md)** - Queries for pages
