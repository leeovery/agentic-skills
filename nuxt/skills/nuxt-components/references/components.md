# Component Patterns

## Script Setup Order Convention

Always follow this order in `<script setup>`:

1. **Imports** - External and internal imports
2. **Props & Emits** - Component interface definitions
3. **Composables** - Composable calls and definitions
4. **Injections** - `inject()` calls
5. **Refs (template)** - `useTemplateRef()` calls
6. **Toggles** - Simple boolean refs
7. **Reactive props** - Complex reactive state (`ref`, `reactive`)
8. **Computed** - Computed properties
9. **Queries** - Data fetching (queries, useAsyncData)
10. **Builders** - Action/mutation factory calls
11. **Watchers** - `watch()` and `watchEffect()`
12. **Methods** - Component functions
13. **Real-time** - Channel subscriptions
14. **Provides** - `provide()` calls
15. **Lifecycles** - `onMounted`, `onUnmounted`, etc.

### Example

```vue
<script lang="ts" setup>
// 1. Imports
import createPostActionFactory from '~/features/posts/actions/create-post-action'
import type { CreatePostData } from '~/features/posts/mutations/create-post-mutation'

// 2. Props & Emits
const props = defineProps<{ author?: Author }>()
const emits = defineEmits<{
  close: [success: boolean]
  update: [post: Post]
}>()

// 3. Composables and definitions
const flash = useFlash()
const { filters } = useReactiveFilters()
const { start, stop, waitingFor } = useWait()

// 4. Injections
const slideover = inject(SlideoverKey)

// 5. Refs (template refs)
const formRef = useTemplateRef('formRef')

// 6. Toggles (boolean refs)
const isOpen = ref(false)
const showAdvanced = ref(false)

// 7. Reactive props (complex state)
const formData = ref<CreatePostData>({
  title: '',
  content: '',
})
const selectedAuthor = ref<Author>()

// 8. Computed props
const canSubmit = computed(() =>
  formData.value.title && formData.value.content
)
const isEditing = computed(() => !!props.post)

// 9. Fetch + associated calls (queries)
const getPostsQuery = getPostsQueryFactory()
const { data: posts, refresh, isLoading } = getPostsQuery(filters)

// 10. Builders (action/mutation factories)
const createPostAction = createPostActionFactory()
const deletePostAction = deletePostActionFactory()

// 11. Watchers
watch(selectedAuthor, (author) => {
  formData.value.authorId = author?.ulid
})

watch(() => props.post, (post) => {
  if (post) populateForm(post)
}, { immediate: true })

// 12. Methods
const onSubmit = async (data: CreatePostData) => {
  await createPostAction(data)
  emits('close', true)
}

const resetForm = () => {
  formData.value = { title: '', content: '' }
}

// 13. Real-time listeners
const { privateChannel } = useRealtime()
privateChannel(Posts).on(PostCreated, refresh)
privateChannel(Posts).on(PostUpdated, refresh)

// 14. Provides
provide(SlideoverKey, { isOpen })

// 15. Lifecycles
onMounted(() => {
  // Initialize component
})

onUnmounted(() => {
  // Cleanup
})
</script>
```

---

## Directory Organization

Components organized by **UI pattern**, not domain:

```
components/
├── Common/              # Shared generic components
│   ├── AppLogo.vue
│   ├── Copyable.vue
│   └── LoadingLine.vue
├── Detail/              # Entity detail view components
│   ├── PostDetail.vue
│   └── AuthorDetail.vue
├── Form/                # Reusable form input components
│   ├── AuthorSelect.vue
│   └── TagInput.vue
├── Modals/              # Modal dialogs
│   ├── DeletePostModal.vue
│   ├── DeleteAuthorModal.vue
│   └── ConfirmActionModal.vue
├── Nav/                 # Navigation components
│   ├── UserMenu.vue
│   └── Sidebar.vue
├── Slideovers/          # Slideout panel components
│   ├── CreatePostSlideover.vue
│   └── UpdatePostSlideover.vue
├── TabSections/         # Tab content components
│   ├── PostCommentsTab.vue
│   └── AuthorPostsTab.vue
└── Tables/              # Table components
    ├── PostsTable.vue
    └── AuthorsTable.vue
```

---

## Component Patterns

### Slideover Component

```vue
<!-- app/components/Slideovers/CreatePostSlideover.vue -->
<script lang="ts" setup>
import createPostActionFactory from '~/features/posts/actions/create-post-action'
import type { CreatePostData } from '~/features/posts/mutations/create-post-mutation'

// Props & Emits
const props = defineProps<{ author?: Author }>()
const emits = defineEmits<{ close: [success: boolean] }>()

// Refs
const formRef = useTemplateRef('formRef')
const selectedAuthor = ref<Author>()

// Form data
const formData = ref<CreatePostData>({
  title: '',
  content: '',
  authorId: props.author?.ulid || '',
  publishedAt: '',
  isDraft: true,
})

// Watchers
watch(selectedAuthor, (author) => {
  formData.value.authorId = author?.ulid || ''
})

// Methods
const createPostAction = createPostActionFactory()

const onSubmit = (data: CreatePostData) => createPostAction(data)
const onSuccess = () => emits('close', true)
</script>

<template>
  <USlideover title="Create Post">
    <XForm
      ref="formRef"
      url="/api/posts"
      method="POST"
      :data="formData"
      @submit="onSubmit"
      @success="onSuccess"
    >
      <div class="space-y-4">
        <AuthorSelect v-model="selectedAuthor" />

        <UFormField label="Title" name="title">
          <UInput v-model="formData.title" />
        </UFormField>

        <UFormField label="Content" name="content">
          <UTextarea v-model="formData.content" />
        </UFormField>

        <UCheckbox v-model="formData.isDraft" label="Save as draft" />
      </div>

      <template #actions>
        <UButton type="submit" label="Create Post" />
      </template>
    </XForm>
  </USlideover>
</template>
```

### Table Component

```vue
<!-- app/components/Tables/PostsTable.vue -->
<script lang="ts" setup>
import { postsColumnBuilder } from '~/tables/posts'
import type { Row } from '@tanstack/vue-table'

const props = defineProps<{
  posts: Post[]
  loading?: boolean
  fetching?: boolean
}>()

const emits = defineEmits<{
  edit: [post: Post]
  delete: [posts: Post[]]
}>()

// Get columns from builder
const columns = postsColumnBuilder.all()

// Row actions
const rowActions = computed(() => (row: Row<Post>) => [
  { label: 'View', to: `/posts/${row.original.ulid}` },
  { label: 'Edit', onSelect: () => emits('edit', row.original) },
  { label: 'Delete', onSelect: () => emits('delete', [row.original]) },
])
</script>

<template>
  <XTable
    :data="posts"
    :columns="columns"
    :loading="loading"
    :fetching="fetching"
    :row-actions="rowActions"
    row-id="ulid"
  />
</template>
```

### Detail Component

```vue
<!-- app/components/Detail/PostDetail.vue -->
<script lang="ts" setup>
const props = defineProps<{ post: Post }>()

const emits = defineEmits<{
  edit: []
  delete: []
}>()
</script>

<template>
  <XCard>
    <div class="space-y-4">
      <div class="flex justify-between">
        <h2 class="text-lg font-semibold">{{ post.title }}</h2>
        <UBadge :color="post.status.color()">
          {{ post.status.text }}
        </UBadge>
      </div>

      <dl class="grid grid-cols-2 gap-4">
        <div>
          <dt class="text-sm text-gray-500">Author</dt>
          <dd>{{ post.author.name }}</dd>
        </div>
        <div>
          <dt class="text-sm text-gray-500">Content</dt>
          <dd>{{ post.content }}</dd>
        </div>
        <div>
          <dt class="text-sm text-gray-500">Created</dt>
          <dd>{{ post.createdAt.format() }}</dd>
        </div>
      </dl>

      <div class="flex gap-2">
        <UButton @click="emits('edit')">Edit</UButton>
        <UButton color="error" @click="emits('delete')">Delete</UButton>
      </div>
    </div>
  </XCard>
</template>
```

### Modal Component

```vue
<!-- app/components/Modals/DeletePostModal.vue -->
<script lang="ts" setup>
import deletePostActionFactory from '~/features/posts/actions/delete-post-action'

const props = defineProps<{ post: Post }>()
const emits = defineEmits<{ close: [deleted: boolean] }>()

const deletePostAction = deletePostActionFactory()
const { is, waitingFor } = useWait()

const onConfirm = async () => {
  await deletePostAction(props.post)
  emits('close', true)
}
</script>

<template>
  <UModal title="Delete Post">
    <p>Are you sure you want to delete this post?</p>
    <p class="text-sm text-gray-500">{{ post.title }}</p>

    <template #footer>
      <UButton variant="ghost" @click="emits('close', false)">
        Cancel
      </UButton>
      <UButton
        color="error"
        :loading="is(waitingFor.post.deleting(post.ulid))"
        @click="onConfirm"
      >
        Delete
      </UButton>
    </template>
  </UModal>
</template>
```

---

## Props & Emits

### Typed Props

```typescript
// Simple props
const props = defineProps<{
  post: Post
  loading?: boolean
}>()

// With defaults
const props = withDefaults(defineProps<{
  post: Post
  loading?: boolean
}>(), {
  loading: false,
})
```

### Typed Emits

```typescript
// Object syntax with types
const emits = defineEmits<{
  close: [success: boolean]
  update: [post: Post]
  delete: [posts: Post[]]
}>()

// Usage
emits('close', true)
emits('update', updatedPost)
```

---

## Template Refs

```vue
<script lang="ts" setup>
// Modern way (Nuxt 3.13+)
const formRef = useTemplateRef('formRef')

// Access
formRef.value?.submit()
</script>

<template>
  <XForm ref="formRef" />
</template>
```

---

## Naming Conventions

| Type | Convention | Example |
|------|------------|---------|
| File | PascalCase | `CreatePostSlideover.vue` |
| Component | PascalCase | `<CreatePostSlideover />` |
| Props | camelCase | `:loading="isLoading"` |
| Emits | camelCase | `@close="handleClose"` |

---

## Common Patterns

### Conditional Rendering

```vue
<template>
  <!-- v-if for expensive components -->
  <PostDetail v-if="post" :post="post" />

  <!-- v-show for frequent toggles -->
  <div v-show="showFilters">...</div>
</template>
```

### Loading States

```vue
<template>
  <!-- Global loading -->
  <LoadingLine v-if="isFetching" />

  <!-- Skeleton -->
  <USkeleton v-if="isLoading" class="h-64" />

  <!-- Content -->
  <PostsTable v-else :posts="posts" />
</template>
```

### Permission-Based

```vue
<template>
  <UButton v-if="can(CreatePost)" @click="openCreate">
    Create Post
  </UButton>
</template>
```

---

## Related Skills

- **[nuxt-forms](../../nuxt-forms/SKILL.md)** - Form patterns
- **[nuxt-tables](../../nuxt-tables/SKILL.md)** - Table patterns
- **[nuxt-pages](../../nuxt-pages/SKILL.md)** - Page-level patterns
