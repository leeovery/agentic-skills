# Action Patterns

Actions orchestrate mutations and provide user feedback. They're the glue between UI and data operations.

## Core Pattern

```typescript
export default function {verb}{Entity}ActionFactory() {
  const mutation = {verb}{Entity}MutationFactory()
  const flash = useFlash()
  const { handleActionError } = useHandleActionError()

  return async (data: {Verb}{Entity}Data): Promise<{Entity}> => {
    try {
      const result = await mutation(data)
      flash.success('{Entity} {verb}d successfully.')
      return result
    } catch (error) {
      throw handleActionError(error, {
        entity: '{entity}',
        operation: '{verb}',
      })
    }
  }
}
```

---

## Complete Action Examples

### Create Post Action

```typescript
// app/features/posts/actions/create-post-action.ts
import createPostMutationFactory, { type CreatePostData } from '../mutations/create-post-mutation'
import type Post from '~/models/Post'

export default function createPostActionFactory() {
  const createPost = createPostMutationFactory()
  const flash = useFlash()
  const { handleActionError } = useHandleActionError()

  return async (data: CreatePostData): Promise<Post> => {
    try {
      const post = await createPost(data)
      flash.success('Post created successfully.')
      return post
    } catch (error) {
      throw handleActionError(error, {
        entity: 'post',
        operation: 'create',
      })
    }
  }
}
```

### Update Post Action

```typescript
// app/features/posts/actions/update-post-action.ts
import updatePostMutationFactory, { type UpdatePostData } from '../mutations/update-post-mutation'
import type Post from '~/models/Post'

export default function updatePostActionFactory() {
  const updatePost = updatePostMutationFactory()
  const flash = useFlash()
  const { handleActionError } = useHandleActionError()

  return async (ulid: string, data: UpdatePostData): Promise<Post> => {
    try {
      const post = await updatePost(ulid, data)
      flash.success('Post updated successfully.')
      return post
    } catch (error) {
      throw handleActionError(error, {
        entity: 'post',
        operation: 'update',
      })
    }
  }
}
```

### Delete Post Action

```typescript
// app/features/posts/actions/delete-post-action.ts
import deletePostMutationFactory from '../mutations/delete-post-mutation'
import type Post from '~/models/Post'

export default function deletePostActionFactory() {
  const deletePost = deletePostMutationFactory()
  const flash = useFlash()
  const { handleActionError } = useHandleActionError()

  return async (post: Post): Promise<void> => {
    try {
      await deletePost(post.ulid)
      flash.success('Post deleted successfully.')
    } catch (error) {
      throw handleActionError(error, {
        entity: 'post',
        operation: 'delete',
      })
    }
  }
}
```

---

## Action Responsibilities

| Do | Don't |
|----|-------|
| Call mutations | Call repositories directly |
| Show success messages | Manage loading states (mutation does this) |
| Show error messages | Define data types (mutation does this) |
| Trigger confirmations | Swallow errors silently |
| Navigate after success | Make direct API calls |
| Orchestrate multiple mutations | |

---

## useHandleActionError

Standardized error handling with user-friendly messages:

```typescript
// app/composables/useHandleActionError.ts
export function useHandleActionError() {
  const flash = useFlash()

  const handleActionError = (
    error: unknown,
    context: { entity: string; operation: string }
  ) => {
    const message = `Failed to ${context.operation} ${context.entity}.`

    if (error instanceof ValidationError) {
      flash.error(message, error.message)
    } else {
      flash.error(message, 'An unexpected error occurred.')
    }

    return error  // Re-throw for component handling
  }

  return { handleActionError }
}
```

### Usage

```typescript
catch (error) {
  throw handleActionError(error, {
    entity: 'post',
    operation: 'create',
  })
}
// Shows: "Failed to create post. [error details]"
```

---

## Action with Confirmation

```typescript
// app/features/posts/actions/delete-post-with-confirm-action.ts
export default function deletePostWithConfirmActionFactory() {
  const deletePost = deletePostMutationFactory()
  const flash = useFlash()
  const { trigger } = useConfirmationToast()
  const { handleActionError } = useHandleActionError()

  return (post: Post): Promise<boolean> => {
    return new Promise((resolve) => {
      trigger({
        title: 'Delete Post?',
        description: `Are you sure you want to delete "${post.title}"?`,
        confirmLabel: 'Delete',
        cancelLabel: 'Cancel',
        onConfirm: async () => {
          try {
            await deletePost(post.ulid)
            flash.success('Post deleted successfully.')
            resolve(true)
          } catch (error) {
            handleActionError(error, { entity: 'post', operation: 'delete' })
            resolve(false)
          }
        },
        onCancel: () => resolve(false),
      })
    })
  }
}
```

---

## Action with Navigation

```typescript
// app/features/posts/actions/create-and-navigate-action.ts
export default function createAndNavigateActionFactory() {
  const createPost = createPostMutationFactory()
  const flash = useFlash()
  const router = useRouter()
  const { handleActionError } = useHandleActionError()

  return async (data: CreatePostData): Promise<Post> => {
    try {
      const post = await createPost(data)
      flash.success('Post created successfully.')

      // Navigate to new post
      await router.push(`/posts/${post.ulid}`)

      return post
    } catch (error) {
      throw handleActionError(error, {
        entity: 'post',
        operation: 'create',
      })
    }
  }
}
```

---

## Action Orchestrating Multiple Mutations

```typescript
// app/features/posts/actions/publish-post-action.ts
export default function publishPostActionFactory() {
  const updatePost = updatePostMutationFactory()
  const notifySubscribers = notifySubscribersMutationFactory()
  const flash = useFlash()
  const { handleActionError } = useHandleActionError()

  return async (post: Post): Promise<Post> => {
    try {
      // Update post status
      const updatedPost = await updatePost(post.ulid, {
        status: 'published',
        publishedAt: new Date().toISOString(),
      })

      // Notify subscribers
      await notifySubscribers(post.ulid)

      flash.success('Post published successfully.')
      return updatedPost
    } catch (error) {
      throw handleActionError(error, {
        entity: 'post',
        operation: 'publish',
      })
    }
  }
}
```

---

## Bulk Actions

```typescript
// app/features/posts/actions/bulk-delete-action.ts
export default function bulkDeletePostsActionFactory() {
  const deletePost = deletePostMutationFactory()
  const flash = useFlash()
  const { handleActionError } = useHandleActionError()

  return async (posts: Post[]): Promise<{ success: number; failed: number }> => {
    let success = 0
    let failed = 0

    for (const post of posts) {
      try {
        await deletePost(post.ulid)
        success++
      } catch {
        failed++
      }
    }

    if (failed === 0) {
      flash.success(`${success} post(s) deleted successfully.`)
    } else {
      flash.warning(`${success} deleted, ${failed} failed.`)
    }

    return { success, failed }
  }
}
```

---

## Using Actions in Components

```vue
<script lang="ts" setup>
import createPostActionFactory from '~/features/posts/actions/create-post-action'
import type { CreatePostData } from '~/features/posts/mutations/create-post-mutation'

// Create action instance
const createPostAction = createPostActionFactory()

// Form data
const formData = ref<CreatePostData>({
  title: '',
  content: '',
  authorId: '',
  publishedAt: '',
  isDraft: true,
})

// Handle submission
const onSubmit = async (data: CreatePostData) => {
  const post = await createPostAction(data)
  // Action handles success message
  emits('close', true)
}
</script>
```

---

## Naming Conventions

| Convention | Example |
|------------|---------|
| File name | `{verb}-{entity}-action.ts` |
| Factory function | `{verb}{Entity}ActionFactory` |

Common patterns:
- `create-post-action.ts` → `createPostActionFactory`
- `update-post-action.ts` → `updatePostActionFactory`
- `delete-post-action.ts` → `deletePostActionFactory`
- `bulk-delete-posts-action.ts` → `bulkDeletePostsActionFactory`
- `publish-post-action.ts` → `publishPostActionFactory`

---

## Related Skills

- **[nuxt-features](../../nuxt-features/SKILL.md)** - Overview
- **[nuxt-composables](../../nuxt-composables/SKILL.md)** - useFlash, useConfirmationToast
- **[nuxt-errors](../../nuxt-errors/SKILL.md)** - Error handling patterns
