# Form Patterns

## XForm Component

The XForm component from x-ui layer handles form state, submission, and validation:

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
  @before="onBefore"
  @after="onAfter"
  @finish="onFinish"
>
  <!-- Form content -->

  <template #actions>
    <UButton type="submit" />
  </template>
</XForm>
```

### Props

| Prop | Type | Description |
|------|------|-------------|
| `url` | `string` | API endpoint |
| `method` | `string` | HTTP method (POST, PUT, PATCH, DELETE) |
| `data` | `object` | Form data (reactive) |
| `waiting` | `WaitRef` | Loading state ref |
| `resetOnSuccess` | `boolean` | Reset form after success |

### Events

| Event | Payload | Description |
|-------|---------|-------------|
| `@submit` | `data` | Before submission (can be async) |
| `@before` | `{ data }` | Before API call |
| `@success` | `{ response }` | On successful response |
| `@error` | `{ error }` | On error response |
| `@after` | `{ response?, error? }` | After API call (success or error) |
| `@finish` | - | After everything (cleanup) |

---

## Complete Form Example

```vue
<!-- app/components/Slideovers/CreatePostSlideover.vue -->
<script lang="ts" setup>
import createPostActionFactory from '~/features/posts/actions/create-post-action'
import type { CreatePostData } from '~/features/posts/mutations/create-post-mutation'

// Props & Emits
const props = defineProps<{ author?: Author }>()
const emits = defineEmits<{ close: [success: boolean] }>()

// Composables
const { is, waitingFor } = useWait()

// Refs
const formRef = useTemplateRef('formRef')
const existingAuthor = ref<Author>()

// Form data
const formData = ref<CreatePostData>({
  email: props.author?.email || '',
  name: props.author?.name || '',
  content: '',
  publishAt: '',
  isDraft: false,
  notifySubscribers: true,
})

// Watchers
watch(existingAuthor, (author) => {
  if (author) {
    formData.value.name = author.name
    formData.value.email = author.email
  }
})

// Methods
const createPostAction = createPostActionFactory()

const onSubmit = async (data: CreatePostData) => {
  return createPostAction(data)
}

const onSuccess = () => {
  emits('close', true)
}

const onError = ({ error }: { error: unknown }) => {
  // Error already handled by action
  // Additional handling if needed
}
</script>

<template>
  <USlideover title="Create Post">
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
      <div class="space-y-4">
        <!-- Author lookup -->
        <AuthorEmailInput
          v-model:email="formData.email"
          v-model:author="existingAuthor"
        />

        <!-- Name -->
        <UFormField label="Name" name="name">
          <UInput v-model="formData.name" />
        </UFormField>

        <!-- Content -->
        <UFormField label="Content" name="content">
          <UTextarea v-model="formData.content" rows="3" />
        </UFormField>

        <!-- Publish date -->
        <UFormField label="Publish At" name="publishAt">
          <UInput v-model="formData.publishAt" type="datetime-local" />
        </UFormField>

        <!-- Options -->
        <div class="space-y-2">
          <UCheckbox v-model="formData.isDraft" label="Save as draft" />
          <UCheckbox v-model="formData.notifySubscribers" label="Notify subscribers" />
        </div>
      </div>

      <template #actions>
        <UButton
          variant="ghost"
          @click="emits('close', false)"
        >
          Cancel
        </UButton>
        <UButton
          type="submit"
          label="Create Post"
          :loading="is(waitingFor.posts.creating)"
        />
      </template>
    </XForm>
  </USlideover>
</template>
```

---

## Validation Error Display

### Error Object Methods

```typescript
// Check if field has errors
form.errors.has('email')        // boolean

// Get first error for field
form.errors.first('email')      // string | undefined

// Get all errors for field
form.errors.get('email')        // string[] | undefined

// Check if any errors exist
form.errors.any()               // boolean

// Clear all errors
form.errors.clear()

// Clear specific field error
form.errors.forget('email')
```

### Display in Template

```vue
<!-- Using UFormField error prop -->
<UFormField
  label="Email"
  name="email"
  :error="form?.errors.first('email')"
>
  <UInput v-model="formData.email" />
</UFormField>

<!-- Manual error display -->
<div>
  <label>Email</label>
  <UInput v-model="formData.email" />
  <p v-if="form?.errors.has('email')" class="text-error text-sm">
    {{ form.errors.first('email') }}
  </p>
</div>
```

---

## useFormBuilder (Alternative)

Fluent API for form configuration:

```typescript
const form = useFormBuilder<CreatePostData>()
  .post('/api/posts')
  .data({
    name: '',
    email: '',
    content: '',
  })
  .waiting('posts.creating')
  .resetOnSuccess(true)
  .before(({ data }) => {
    // Transform data before submit
  })
  .success(({ response }) => {
    flash.success('Post created!')
    emits('close')
  })
  .error(({ error }) => {
    if (error instanceof ValidationError) {
      // Handle validation errors
    }
  })
  .build()

// Submit
await form.submit()

// Access state
form.processing     // boolean
form.errors         // FormErrors
form.data           // reactive data
```

---

## Form Input Components

### Text Input

```vue
<UFormField label="Name" name="name">
  <UInput v-model="formData.name" placeholder="Enter name" />
</UFormField>
```

### Textarea

```vue
<UFormField label="Description" name="description">
  <UTextarea v-model="formData.description" rows="4" />
</UFormField>
```

### Select

```vue
<UFormField label="Status" name="status">
  <USelect
    v-model="formData.status"
    :options="PostStatus.values()"
    option-attribute="text"
  />
</UFormField>
```

### Checkbox

```vue
<UCheckbox v-model="formData.isDraft" label="Save as draft" />
```

### Date/Time

```vue
<UFormField label="Scheduled" name="scheduledAt">
  <UInput v-model="formData.scheduledAt" type="datetime-local" />
</UFormField>
```

### Custom Input Component

```vue
<!-- app/components/Form/AuthorEmailInput.vue -->
<script lang="ts" setup>
const email = defineModel<string>('email')
const author = defineModel<Author>('author')

const authorApi = useRepository('authors')
const searching = ref(false)
const results = ref<Author[]>([])

const search = useDebounceFn(async () => {
  if (!email.value || email.value.length < 3) {
    results.value = []
    return
  }

  searching.value = true
  try {
    const { data } = await authorApi.search(email.value)
    results.value = data
  } finally {
    searching.value = false
  }
}, 300)

watch(email, search)

const selectAuthor = (a: Author) => {
  author.value = a
  email.value = a.email
  results.value = []
}
</script>

<template>
  <div>
    <UFormField label="Email" name="email">
      <UInput v-model="email" type="email" />
    </UFormField>

    <div v-if="results.length" class="mt-2 border rounded">
      <button
        v-for="a in results"
        :key="a.ulid"
        class="block w-full p-2 text-left hover:bg-gray-100"
        @click="selectAuthor(a)"
      >
        {{ a.name }} ({{ a.email }})
      </button>
    </div>
  </div>
</template>
```

---

## Edit Form Pattern

```vue
<script lang="ts" setup>
const props = defineProps<{ post: Post }>()
const emits = defineEmits<{ close: [success: boolean] }>()

// Initialize form with existing data
const formData = ref<UpdatePostData>({
  name: props.post.author.name,
  content: props.post.content,
  isDraft: props.post.isDraft,
})

// Watch for prop changes
watch(() => props.post, (post) => {
  formData.value = {
    name: post.author.name,
    content: post.content,
    isDraft: post.isDraft,
  }
}, { immediate: true })

const updatePostAction = updatePostActionFactory()

const onSubmit = async (data: UpdatePostData) => {
  return updatePostAction(props.post.ulid, data)
}
</script>

<template>
  <USlideover title="Edit Post">
    <XForm
      :url="`/api/posts/${post.ulid}`"
      method="PUT"
      :data="formData"
      @submit="onSubmit"
      @success="emits('close', true)"
    >
      <!-- Form fields -->
    </XForm>
  </USlideover>
</template>
```

---

## Naming Conventions

| Type | Convention |
|------|------------|
| Form data type | `Create{Entity}Data`, `Update{Entity}Data` |
| Form ref | `formRef` |
| Submit handler | `onSubmit` |
| Success handler | `onSuccess` |

---

## Related Skills

- **[nuxt-components](../../nuxt-components/SKILL.md)** - Component patterns
- **[nuxt-features](../../nuxt-features/SKILL.md)** - Actions for form submission
- **[nuxt-errors](../../nuxt-errors/SKILL.md)** - Error handling
