# Mutation Patterns

Mutations handle pure API operations with loading state management. No UI feedback - that's actions' job.

## Core Pattern

```typescript
export default function create{Entity}MutationFactory() {
  const api = useRepository('{entity}')
  const { start, stop, waitingFor } = useWait()

  return async (data: Create{Entity}Data): Promise<{Entity}> => {
    try {
      start(waitingFor.{entity}.creating)
      const { data: result } = await api.create(data)
      return result
    } finally {
      stop(waitingFor.{entity}.creating)
    }
  }
}
```

---

## Complete Mutation Examples

### Create Post

```typescript
// app/features/posts/mutations/create-post-mutation.ts
import type Post from '~/models/Post'

export type CreatePostData = {
  title: string
  content: string
  authorId: string
  publishedAt: string
  isDraft: boolean
}

export default function createPostMutationFactory() {
  const postApi = useRepository('posts')
  const { start, stop, waitingFor } = useWait()

  return async (data: CreatePostData): Promise<Post> => {
    try {
      start(waitingFor.posts.creating)
      const { data: post } = await postApi.create(data)
      return post
    } finally {
      stop(waitingFor.posts.creating)
    }
  }
}
```

### Update Post

```typescript
// app/features/posts/mutations/update-post-mutation.ts
import type Post from '~/models/Post'

export type UpdatePostData = Partial<{
  title: string
  content: string
  publishedAt: string
  isDraft: boolean
}>

export default function updatePostMutationFactory() {
  const postApi = useRepository('posts')
  const { start, stop, waitingFor } = useWait()

  return async (ulid: string, data: UpdatePostData): Promise<Post> => {
    try {
      start(waitingFor.post.updating(ulid))
      const { data: post } = await postApi.update(ulid, data)
      return post
    } finally {
      stop(waitingFor.post.updating(ulid))
    }
  }
}
```

### Delete Post

```typescript
// app/features/posts/mutations/delete-post-mutation.ts
export default function deletePostMutationFactory() {
  const postApi = useRepository('posts')
  const { start, stop, waitingFor } = useWait()

  return async (ulid: string): Promise<void> => {
    try {
      start(waitingFor.post.deleting(ulid))
      await postApi.delete(ulid)
    } finally {
      stop(waitingFor.post.deleting(ulid))
    }
  }
}
```

---

## useWait Pattern

Global loading state management:

```typescript
const { start, stop, is, waitingFor } = useWait()

// Global operations (no ID)
waitingFor.posts.creating       // Boolean ref: creating any post
waitingFor.posts.listing        // Boolean ref: listing posts

// Per-item operations (with ID)
waitingFor.post.loading(ulid)   // Boolean ref: loading specific post
waitingFor.post.updating(ulid)  // Boolean ref: updating specific post
waitingFor.post.deleting(ulid)  // Boolean ref: deleting specific post
```

### Starting/Stopping

```typescript
// Start waiting state
start(waitingFor.posts.creating)

// Stop waiting state
stop(waitingFor.posts.creating)

// Always use try/finally to ensure stop is called
try {
  start(waitingFor.posts.creating)
  // ... do work
} finally {
  stop(waitingFor.posts.creating)
}
```

### Checking State

```typescript
// Check if operation in progress
const isCreating = is(waitingFor.posts.creating)

// Use in templates
<UButton :loading="is(waitingFor.posts.creating)" />
```

---

## Mutation with Custom Repository Method

```typescript
// app/features/comments/mutations/create-comment-for-post-mutation.ts
import type Comment from '~/models/Comment'

export default function createCommentForPostMutationFactory() {
  const commentApi = useRepository('comments')
  const { start, stop, waitingFor } = useWait()

  return async (postUlid: string, content: string): Promise<Comment> => {
    try {
      start(waitingFor.comments.creating)
      const { data: comment } = await commentApi.createForPost(postUlid, { content })
      return comment
    } finally {
      stop(waitingFor.comments.creating)
    }
  }
}
```

---

## Mutation with Multiple API Calls

```typescript
// app/features/posts/mutations/create-post-with-image-mutation.ts
import type Post from '~/models/Post'

export default function createPostWithImageMutationFactory() {
  const postApi = useRepository('posts')
  const imageApi = useRepository('images')
  const { start, stop, waitingFor } = useWait()

  return async (data: CreatePostData, imageFile?: File): Promise<Post> => {
    try {
      start(waitingFor.posts.creating)

      // Create post
      const { data: post } = await postApi.create(data)

      // Upload image if provided
      if (imageFile) {
        await imageApi.uploadForPost(post.ulid, imageFile)
      }

      // Refetch with relations
      const { data: fullPost } = await postApi.get(post.ulid)
      return fullPost
    } finally {
      stop(waitingFor.posts.creating)
    }
  }
}
```

---

## Mutation Responsibilities

| Do | Don't |
|----|-------|
| Call repository methods | Show toast messages |
| Manage loading state | Handle UI confirmations |
| Define data types | Navigate to pages |
| Return results | Catch errors for display |
| Throw errors on failure | Log errors |

Mutations are **pure data operations**. UI concerns belong in actions.

---

## Data Types

Export types for use in actions and components:

```typescript
// Export data type for action/component use
export type CreatePostData = {
  title: string
  content: string
  authorId: string
  // ...
}

// Partial type for updates
export type UpdatePostData = Partial<CreatePostData>
```

---

## Error Handling

Mutations **throw errors** - don't catch and handle:

```typescript
// CORRECT: Let error propagate to action
return async (data: CreatePostData): Promise<Post> => {
  try {
    start(waitingFor.posts.creating)
    const { data: post } = await postApi.create(data)
    return post
  } finally {
    stop(waitingFor.posts.creating)  // Always stop wait state
  }
}

// WRONG: Catching and logging in mutation
return async (data: CreatePostData): Promise<Post | null> => {
  try {
    start(waitingFor.posts.creating)
    const { data: post } = await postApi.create(data)
    return post
  } catch (error) {
    console.error('Failed:', error)  // Don't do this
    return null
  } finally {
    stop(waitingFor.posts.creating)
  }
}
```

---

## Naming Conventions

| Convention | Example |
|------------|---------|
| File name | `{verb}-{entity}-mutation.ts` |
| Factory function | `{verb}{Entity}MutationFactory` |
| Data type | `{Verb}{Entity}Data` |

Common verbs: `create`, `update`, `delete`, `publish`, `archive`, `restore`

---

## Related Skills

- **[nuxt-features](../../nuxt-features/SKILL.md)** - Overview
- **[nuxt-repositories](../../nuxt-repositories/SKILL.md)** - Repository methods
- **[nuxt-composables](../../nuxt-composables/SKILL.md)** - useWait details
