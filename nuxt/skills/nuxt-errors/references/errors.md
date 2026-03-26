# Error Handling Patterns

## Error Classes

### Base Error Class

```typescript
// From #layers/base/app/errors/error-response.ts
export class ErrorResponse<T = any> {
  constructor(
    public response: FetchResponse<T>,
    public status: number
  ) {}
}
```

### ValidationError (422)

```typescript
// From #layers/base/app/errors/validation-error.ts
export class ValidationError<T = any> extends ErrorResponse<T> {
  public errors: Record<string, string[]>
  public message: string

  // Map to form errors format
  mapToFormErrors(): FormError[] {
    return Object.entries(this.errors).flatMap(([field, messages]) =>
      messages.map(message => ({ path: field, message }))
    )
  }
}
```

Usage:

```typescript
if (error instanceof ValidationError) {
  // Access error details
  error.message              // "The given data was invalid."
  error.errors               // { email: ['The email has already been taken.'] }
  error.errors.email         // ['The email has already been taken.']

  // Map to form errors
  const formErrors = error.mapToFormErrors()
  // [{ path: 'email', message: 'The email has already been taken.' }]
}
```

### ConflictError (409)

```typescript
// From #layers/base/app/errors/conflict-error.ts
export class ConflictError<T = any> extends ErrorResponse<T> {}
```

### TooManyRequestsError (429)

```typescript
// From #layers/base/app/errors/too-many-requests-error.ts
export class TooManyRequestsError<T = any> extends ErrorResponse<T> {}
```

---

## Error Handler Configuration

```typescript
// app.config.ts
import { ValidationError } from '#layers/base/app/errors/validation-error'
import { TooManyRequestsError } from '#layers/base/app/errors/too-many-requests-error'

export default defineAppConfig({
  errorHandlers: {
    // 401: Session expired - redirect to login
    401: async ({ response, flash }) => {
      flash.error('Session expired. Please log in again.')
      return navigateTo('/auth/login')
    },

    // 403: Access denied
    403: async ({ flash }) => {
      flash.error('Access denied.')
    },

    // 404: Not found - redirect to error page
    404: '/not-found',  // String = redirect path

    // 422: Validation error - throw typed error
    422: async ({ response }) => {
      return Promise.reject(new ValidationError(response))
    },

    // 429: Rate limited
    429: async ({ response }) => {
      return Promise.reject(new TooManyRequestsError(response))
    },

    // 500: Server error
    500: async ({ flash }) => {
      flash.error('Server error. Please try again later.')
    },
  },
})
```

### Handler Signature

```typescript
type ErrorHandler = (context: {
  response: FetchResponse
  flash: FlashInstance
}) => Promise<any> | string

// String = redirect path
// Promise.reject() = throw error
// Promise.resolve() = handled, continue
```

---

## Response Interceptors

```typescript
// app/interceptors/response/error-handler.ts
import type { NuxtApp } from '#app'
import type { FetchContext } from 'ofetch'
import type { InterceptorResult } from '#layers/base/app/types'

export default async function handleResponseErrors(
  app: NuxtApp,
  { response }: FetchContext
): Promise<InterceptorResult> {
  const { handleError } = useErrorHandler()

  if (response) {
    return handleError(response)
  }
}
```

### Registration

```typescript
// app.config.ts
import errorHandler from '~/interceptors/response/error-handler'

export default defineAppConfig({
  interceptors: {
    response: [errorHandler],
  },
})
```

---

## useHandleActionError

Standardized action error handling:

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
    } else if (error instanceof ConflictError) {
      flash.error(message, 'A conflict occurred. Please refresh and try again.')
    } else if (error instanceof TooManyRequestsError) {
      flash.error(message, 'Too many requests. Please wait and try again.')
    } else {
      flash.error(message, 'An unexpected error occurred.')
    }

    return error  // Re-throw for further handling
  }

  return { handleActionError }
}
```

### Usage in Actions

```typescript
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

---

## Form Error Display

### In XForm Component

```vue
<script setup>
const formRef = useTemplateRef('formRef')
</script>

<template>
  <XForm ref="formRef" @error="onError">
    <UFormField
      label="Email"
      name="email"
      :error="formRef?.form?.errors.first('email')"
    >
      <UInput v-model="formData.email" />
    </UFormField>
  </XForm>
</template>
```

### Manual Error Handling

```typescript
const onError = ({ error }: { error: unknown }) => {
  if (error instanceof ValidationError) {
    // Errors automatically mapped to form fields
    // Access via formRef.form.errors
  }
}
```

---

## Error Boundaries

### Global Error Page

```vue
<!-- app/error.vue -->
<script setup>
const props = defineProps<{ error: any }>()

const handleError = () => clearError({ redirect: '/' })
</script>

<template>
  <div class="min-h-screen flex items-center justify-center">
    <div class="text-center">
      <h1 class="text-4xl font-bold">{{ error.statusCode }}</h1>
      <p class="mt-2">{{ error.message }}</p>
      <UButton @click="handleError" class="mt-4">
        Go Home
      </UButton>
    </div>
  </div>
</template>
```

### Throw Errors in Pages

```typescript
// In page component
if (!data.value) {
  throw createError({
    statusCode: 404,
    message: 'Post not found',
  })
}
```

---

## Custom Error Classes

```typescript
// app/errors/BusinessError.ts
export class BusinessError extends Error {
  constructor(
    message: string,
    public code: string,
    public details?: Record<string, any>
  ) {
    super(message)
    this.name = 'BusinessError'
  }
}

// Usage
throw new BusinessError(
  'Post cannot be deleted',
  'POST_HAS_ACTIVE_COMMENTS',
  { commentCount: 3 }
)
```

---

## Error Logging

```typescript
// In action or composable
const { handleActionError } = useHandleActionError()

try {
  await riskyOperation()
} catch (error) {
  // Log error for debugging
  console.error('Operation failed:', error)

  // Handle for user
  throw handleActionError(error, {
    entity: 'operation',
    operation: 'perform',
  })
}
```

---

## Best Practices

### 1. Always Use Typed Errors

```typescript
// DON'T
catch (error) {
  if (error.status === 422) { ... }
}

// DO
catch (error) {
  if (error instanceof ValidationError) { ... }
}
```

### 2. Handle in Actions, Not Mutations

```typescript
// Mutation: throw error
async (data) => {
  const result = await api.create(data)
  return result
}

// Action: handle error
async (data) => {
  try {
    return await mutation(data)
  } catch (error) {
    throw handleActionError(error, context)
  }
}
```

### 3. User-Friendly Messages

```typescript
// DON'T
flash.error(error.message)

// DO
flash.error('Failed to create post.', error.message)
```

---

## Related Skills

- **[nuxt-config](../../nuxt-config/SKILL.md)** - Error handler configuration
- **[nuxt-features](../../nuxt-features/SKILL.md)** - Error handling in actions
- **[nuxt-forms](../../nuxt-forms/SKILL.md)** - Validation error display
