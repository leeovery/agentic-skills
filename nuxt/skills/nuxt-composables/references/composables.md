# Creating Composables

## When to Create a Composable

Create a composable when you need:
- **Shared state** across multiple components
- **Reusable logic** with reactive state
- **Encapsulated complexity** for a specific feature
- **Cleanup handling** for subscriptions or timers

Don't create a composable for:
- Simple one-off logic (use inline code)
- Pure utility functions without state (use `utils/`)
- Data fetching (use queries in `features/`)

---

## Singleton Pattern (Shared State)

State persists across all components. Use for app-wide state.

```typescript
// app/composables/useUser.ts
let user = ref<User>()  // Outside function = singleton

export default function useUser() {
  const getUser = () => user

  const setUser = (data: BaseEntity) => {
    user.value = User.hydrate(data)
  }

  const clearUser = () => {
    user.value = undefined
  }

  return { user, getUser, setUser, clearUser }
}
```

### Usage

```typescript
// Component A
const { setUser } = useUser()
setUser(userData)

// Component B - sees the same user
const { user } = useUser()
console.log(user.value?.name)  // Same instance
```

---

## Factory Pattern (Fresh State)

New state per call. Use for component-local state with reusable logic.

```typescript
// app/composables/useToggle.ts
export default function useToggle(initial = false) {
  const value = ref(initial)  // Inside function = fresh per call

  const toggle = () => {
    value.value = !value.value
  }

  const setTrue = () => { value.value = true }
  const setFalse = () => { value.value = false }

  return { value, toggle, setTrue, setFalse }
}
```

### Usage

```typescript
// Component A
const sidebar = useToggle(true)

// Component B - separate instance
const modal = useToggle(false)
```

---

## useState Pattern (SSR-Safe Singleton)

For state that needs SSR hydration:

```typescript
// app/composables/useSettings.ts
export default function useSettings() {
  const settings = useState<Settings>('app-settings', () => ({
    theme: 'light',
    language: 'en',
  }))

  const setTheme = (theme: string) => {
    settings.value.theme = theme
  }

  return { settings, setTheme }
}
```

---

## Composable with Dependencies

Composables that use other composables or repositories:

```typescript
// app/composables/useCategories.ts
export function useCategories() {
  const categoryApi = useRepository('categories')
  const categories = ref<Category[]>([])
  const loading = ref(false)

  const fetchCategories = async () => {
    loading.value = true
    try {
      const { data } = await categoryApi.list()
      categories.value = data
    } finally {
      loading.value = false
    }
  }

  const getCategoryTree = computed(() => {
    // Build hierarchical tree from flat array
    return buildTree(categories.value)
  })

  return { categories, loading, fetchCategories, getCategoryTree }
}
```

---

## Composable with Cleanup

For subscriptions, timers, or event listeners:

```typescript
// app/composables/usePolling.ts
export default function usePolling(callback: () => void, interval = 5000) {
  const isPolling = ref(false)
  let timer: NodeJS.Timeout | null = null

  const start = () => {
    if (isPolling.value) return
    isPolling.value = true
    timer = setInterval(callback, interval)
  }

  const stop = () => {
    if (timer) {
      clearInterval(timer)
      timer = null
    }
    isPolling.value = false
  }

  // Auto-cleanup on unmount
  onUnmounted(stop)

  return { isPolling, start, stop }
}
```

---

## Composable with Real-time

Encapsulating WebSocket subscriptions:

```typescript
// app/composables/useTaskNotifications.ts
import { Tasks, TaskCreated, TaskCompleted } from '~/constants'

export default function useTaskNotifications() {
  const pendingCount = ref(0)
  const taskApi = useRepository('tasks')
  const { privateChannel, leaveChannel } = useRealtime()

  const fetchCount = async () => {
    const { data } = await taskApi.list({ filter: { status: 'pending' } })
    pendingCount.value = data.length
  }

  const init = () => {
    fetchCount()

    privateChannel(Tasks).on(
      [TaskCreated, TaskCompleted],
      fetchCount
    )
  }

  onUnmounted(() => {
    leaveChannel(Tasks)
  })

  return { pendingCount, init }
}
```

---

## Composable with Provide/Inject

For scoped state within component trees:

```typescript
// app/composables/useFormContext.ts
import type { InjectionKey } from 'vue'

interface FormContext {
  errors: Ref<Record<string, string[]>>
  setError: (field: string, message: string) => void
  clearError: (field: string) => void
}

export const FormContextKey: InjectionKey<FormContext> = Symbol('form-context')

export function useProvideFormContext() {
  const errors = ref<Record<string, string[]>>({})

  const setError = (field: string, message: string) => {
    errors.value[field] = [message]
  }

  const clearError = (field: string) => {
    delete errors.value[field]
  }

  const context: FormContext = { errors, setError, clearError }
  provide(FormContextKey, context)

  return context
}

export function useFormContext() {
  const context = inject(FormContextKey)
  if (!context) {
    throw new Error('useFormContext must be used within a form provider')
  }
  return context
}
```

---

## Return Value Patterns

### Always Return Objects

```typescript
// GOOD: Object - extensible, clear names
return { user, setUser, clearUser }

// BAD: Array - position-dependent, unclear
return [user, setUser, clearUser]
```

### Consistent Naming

```typescript
return {
  // State - noun
  user,
  isLoading,
  error,

  // Actions - verb
  fetchUser,
  updateUser,
  clearError,

  // Computed - descriptive
  fullName,
  isAdmin,
}
```

---

## File Structure

```
app/
└── composables/
    ├── useUser.ts           # User state (singleton)
    ├── useCategories.ts     # Category data + tree
    ├── useToggle.ts         # Generic toggle (factory)
    ├── usePolling.ts        # Polling utility
    └── useFormContext.ts    # Form provide/inject
```

---

## Naming Conventions

| Element | Convention | Example |
|---------|------------|---------|
| File | camelCase with `use` prefix | `useUser.ts` |
| Function | `export default function use{Name}()` | `useUser()` |
| State refs | Descriptive nouns | `user`, `categories` |
| Boolean refs | `is` prefix | `isLoading`, `isOpen` |
| Actions | Verbs | `fetchUser`, `setTheme` |
| Computed | Descriptive | `fullName`, `categoryTree` |

---

## Best Practices

### 1. Single Responsibility

```typescript
// GOOD: One concern
export default function useUser() { /* user state only */ }
export default function useAuth() { /* auth flow only */ }

// BAD: Multiple concerns
export default function useUserAndAuth() { /* mixed */ }
```

### 2. Explicit Dependencies

```typescript
// GOOD: Dependencies as parameters
export default function useSearch(repository: string) {
  const api = useRepository(repository)
  // ...
}

// Less flexible: Hardcoded dependency
export default function useSearch() {
  const api = useRepository('posts')  // Always posts
  // ...
}
```

### 3. Type Everything

```typescript
interface UseUserReturn {
  user: Ref<User | undefined>
  setUser: (data: BaseEntity) => void
  clearUser: () => void
}

export default function useUser(): UseUserReturn {
  // ...
}
```

### 4. Handle Cleanup

```typescript
export default function useSubscription() {
  const unsubscribe = ref<(() => void) | null>(null)

  const subscribe = () => {
    unsubscribe.value = someService.subscribe()
  }

  onUnmounted(() => {
    unsubscribe.value?.()
  })

  return { subscribe }
}
```

---

## Related Skills

- **[nuxt-features](../../nuxt-features/SKILL.md)** - Queries/actions (prefer over data-fetching composables)
- **[nuxt-realtime](../../nuxt-realtime/SKILL.md)** - Real-time subscriptions
- **[nuxt-components](../../nuxt-components/SKILL.md)** - Using composables in components
