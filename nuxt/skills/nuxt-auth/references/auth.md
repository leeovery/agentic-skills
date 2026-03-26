# Authentication & Authorization Patterns

## Sanctum Configuration

```typescript
// nuxt.config.ts
export default defineNuxtConfig({
  modules: ['nuxt-auth-sanctum'],

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
      onAuthOnly: '/auth/login',     // Redirect when auth required
      onGuestOnly: '/',              // Redirect when already authenticated
      onLogin: '/',                  // Redirect after login
      onLogout: '/auth/login',       // Redirect after logout
    },
  },
})
```

---

## Authentication Flow

### 1. Plugin Initialization

```typescript
// app/plugins/init.ts
export default defineNuxtPlugin(async (nuxtApp) => {
  const { init: fetchUser, user: sanctumUser, isAuthenticated } = useSanctumAuth()
  const { setUser, clearUser } = useUser()
  const { startSessionWatcher, stopSessionWatcher } = useSessionWatcher()

  const initUser = async () => {
    try {
      await fetchUser()
      setUser(sanctumUser.value!.data)
      startSessionWatcher()
    } catch (error) {
      // Auth check failed - middleware will redirect if needed
    }
  }

  // Watch for logout
  watch(isAuthenticated, (newValue, oldValue) => {
    if (newValue === false && oldValue === true) {
      clearUser()
      closeAllSlideovers()
      stopSessionWatcher()
    }
  })

  // Handle login event
  nuxtApp.hook('sanctum:login', async () => {
    await initUser()
  })

  // Initial load
  await initUser()
})
```

### 2. Login Page

```vue
<!-- app/pages/auth/login.vue -->
<script lang="ts" setup>
definePageMeta({
  layout: 'auth',
  middleware: 'guest',
})

const { login } = useSanctumAuth()
const router = useRouter()
const flash = useFlash()

const credentials = ref({
  email: '',
  password: '',
})
const loading = ref(false)

const onSubmit = async () => {
  loading.value = true
  try {
    await login(credentials.value)
    router.push('/')
  } catch (error) {
    flash.error('Invalid credentials')
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <XCard title="Login">
    <form @submit.prevent="onSubmit">
      <div class="space-y-4">
        <UFormField label="Email" name="email">
          <UInput v-model="credentials.email" type="email" />
        </UFormField>

        <UFormField label="Password" name="password">
          <UInput v-model="credentials.password" type="password" />
        </UFormField>

        <UButton type="submit" class="w-full" :loading="loading">
          Login
        </UButton>
      </div>
    </form>
  </XCard>
</template>
```

### 3. Logout

```typescript
const { logout } = useSanctumAuth()

const handleLogout = async () => {
  await logout()
  // Sanctum config handles redirect to /auth/login
}
```

---

## Permission System

### Registration

```typescript
// In User model's booted() hook
public override booted() {
  if (this.permissions) {
    registerPermissions(this.permissions)
  }
}

// Or in plugin
const { registerPermissions } = usePermissions()
registerPermissions(['posts.list', 'posts.create', 'posts.update'])
```

### Permission Checking

```typescript
const { can, cannot, before } = usePermissions()

// Check single permission
if (can('posts.create')) {
  // User can create posts
}

// Check if user cannot
if (cannot('posts.delete')) {
  // User cannot delete posts
}

// Check multiple (any of)
if (can(['posts.create', 'posts.update'])) {
  // User can create OR update
}
```

### Admin Bypass

```typescript
// In app.config.ts
export default defineAppConfig({
  permissions: {
    before: (permission) => {
      const { user } = useUser()
      if (user.value?.isAdmin) return true  // Admin can do anything
      return null  // Check normally
    },
  },
})
```

---

## Page-Level Authorization

### Single Permission

```typescript
// In page component
definePageMeta({
  permissions: 'posts.list',
})
```

### Multiple Permissions (Any)

```typescript
definePageMeta({
  permissions: ['posts.list', 'authors.list'],
})
```

### With Middleware

```typescript
definePageMeta({
  middleware: ['auth', 'verified'],
  permissions: 'posts.list',
})
```

---

## Component-Level Authorization

### Conditional Rendering

```vue
<template>
  <!-- Show button only if user can create -->
  <UButton v-if="can('posts.create')" @click="createPost">
    Create Post
  </UButton>

  <!-- Show disabled button if cannot delete -->
  <UButton
    v-if="cannot('posts.delete')"
    disabled
    title="No permission to delete"
  >
    Delete
  </UButton>
</template>

<script setup>
const { can, cannot } = usePermissions()
</script>
```

### Action Protection

```typescript
const createPostAction = createPostActionFactory()
const { can } = usePermissions()

const handleCreate = async () => {
  if (!can('posts.create')) {
    flash.error('You do not have permission to create posts')
    return
  }

  await createPostAction(data)
}
```

---

## Permission Constants

```typescript
// app/constants/permissions.ts
// Auto-generated from Laravel backend

// Post permissions
export const ListPosts = 'posts.list'
export const ShowPost = 'posts.show'
export const CreatePost = 'posts.create'
export const UpdatePost = 'posts.update'
export const DeletePost = 'posts.delete'

// Author permissions
export const ListAuthors = 'authors.list'
export const ShowAuthor = 'authors.show'
export const CreateAuthor = 'authors.create'
export const UpdateAuthor = 'authors.update'
export const DeleteAuthor = 'authors.delete'

// Permission groups
export const PostPermissions = [
  ListPosts,
  ShowPost,
  CreatePost,
  UpdatePost,
  DeletePost,
]

export const AuthorPermissions = [
  ListAuthors,
  ShowAuthor,
  CreateAuthor,
  UpdateAuthor,
  DeleteAuthor,
]
```

### Using Constants

```typescript
import { ListPosts, CreatePost } from '~/constants/permissions'

// In page meta
definePageMeta({
  permissions: ListPosts,
})

// In component
if (can(CreatePost)) { /* ... */ }
```

---

## Session Management

### Session Expiry Plugin

```typescript
// app/plugins/session.ts
export default defineNuxtPlugin((nuxtApp) => {
  const { trigger } = useConfirmationToast()
  const flash = useFlash()
  const { logout, refreshSession } = useSanctumAuth()

  // Pre-expiry warning
  nuxtApp.hook('session-watcher:pre-expire', async () => {
    trigger({
      title: 'Session Expiring',
      description: 'Your session will expire soon. Stay logged in?',
      confirmLabel: 'Stay Logged In',
      cancelLabel: 'Logout',
      onConfirm: () => refreshSession(),
      onCancel: () => logout(),
    })
  })

  // Session expired
  nuxtApp.hook('session-watcher:expire', async () => {
    flash.info('Session expired', 'You have been logged out.')
    await logout()
  })
})
```

---

## User State Management

```typescript
// app/composables/useUser.ts
let user = ref<User>()

export default function useUser() {
  const getUser = () => user

  const setUser = (userData: BaseEntity) => {
    user.value = User.hydrate(userData)
  }

  const clearUser = () => {
    user.value = undefined
  }

  return { user, getUser, setUser, clearUser }
}
```

### Usage

```typescript
const { user } = useUser()

// Access user data
user.value?.name
user.value?.email
user.value?.isAdmin
```

---

## Auth Layouts

### Auth Layout (Login Page)

```vue
<!-- app/layouts/auth.vue -->
<template>
  <div class="min-h-screen flex items-center justify-center bg-gray-50">
    <div class="w-full max-w-md p-4">
      <slot />
    </div>
  </div>
</template>
```

### Default Layout (Authenticated)

```vue
<!-- app/layouts/default.vue -->
<script setup>
const { user } = useUser()
</script>

<template>
  <div class="flex min-h-screen">
    <aside class="w-64 border-r">
      <Sidebar />
    </aside>

    <main class="flex-1">
      <header class="border-b p-4">
        <UserMenu :user="user" />
      </header>

      <div class="p-4">
        <slot />
      </div>
    </main>
  </div>
</template>
```

---

## Related Skills

- **[nuxt-config](../../nuxt-config/SKILL.md)** - Sanctum configuration
- **[nuxt-pages](../../nuxt-pages/SKILL.md)** - Page meta permissions
- **[nuxt-composables](../../nuxt-composables/SKILL.md)** - usePermissions
