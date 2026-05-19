# Shared State Across Routes

When form state, navigation context, or anything else needs to survive route transitions, put it in `useState`. This file covers the rules — composable hidden in `app/composables/`, consumed from any page or layout.

## Rule 1: `useState` for SSR-safe shared state

`useState(key, init)` is Nuxt's keyed reactive primitive: same key, same ref across every caller. Server-side it's request-scoped (no cross-request leakage); client-side it's module-scoped (shared across components).

```typescript
// composables/useFormFlow.ts
interface FormFlow {
  name:  string
  email: string
  // …
}

function empty(): FormFlow {
  return { name: '', email: '' }
}

export function useFormFlow() {
  const data = useState<FormFlow>('form-flow', empty)
  function reset() { data.value = empty() }
  return { data, reset }
}
```

**Don't use a module-scope `ref`** as a "singleton":

```typescript
// ❌ module-scope ref — leaks across requests on the server
const data = ref<FormFlow>(empty())
export function useFormFlow() { return { data } }
```

On the server this leaks one user's state into another user's response. On the client it works fine, but you're then writing SPA-only code without realising it. `useState` is the right default; only fall back to module-scope if you've measured a real performance issue (you haven't).

**`init` must be a function, not a value.** `useState('key', empty)` calls `empty()` lazily once per request. `useState('key', empty())` calls it at module load and shares the same object across requests — same leak as above.

---

## Rule 2: Derive flow state from the route, not an internal counter

If a step / tab / phase is reflected in the URL, derive it from `route.path` (or `route.params`). Don't keep a parallel `currentStep` ref.

```typescript
const STEPS = ['profile', 'review', 'done'] as const

export function useFlowStep() {
  const route = useRoute()
  const slug = computed(() => route.path.split('/').filter(Boolean).pop() ?? STEPS[0])
  const index = computed(() => STEPS.indexOf(slug.value as typeof STEPS[number]))
  return { slug, index, total: STEPS.length }
}
```

Why this matters:

- **Deep links work.** A user landing on `/checkout/review` directly sees step "review".
- **Back/forward works.** Browser nav and step nav don't desync — they're the same thing.
- **One source of truth.** Re-render is driven by route changes, no extra wiring.

`router.push('/checkout/review')` *is* the step transition. Don't fight it.

---

## Rule 3: Submit composables don't own `loading` state

When a composable exposes a `submit()` function, **don't** toggle `loading` inside it if the consuming UI also does so via `:disabled` on a button. They'll fight.

```typescript
// composable
async function submit() {
  errorState.value = ''
  try {
    await $fetch('/api/...', { method: 'POST', body: data.value })
    await navigateTo('/thanks')
  } catch (err) {
    const statusMessage = (err as { data?: { statusMessage?: string } })?.data?.statusMessage
    errorState.value = statusMessage ?? (err instanceof Error ? err.message : 'Something went wrong.')
  }
}
```

```vue
<!-- consumer -->
<UButton :loading :disabled="loading" @click="onClick">Submit</UButton>

<script setup lang="ts">
const loading = ref(false)
async function onClick() {
  if (loading.value) return
  loading.value = true
  try { await submit() } finally { loading.value = false }
}
</script>
```

Caller owns `loading`. The composable just throws or completes.

Side effects inside `submit()` (`navigateTo`, `reset()`, clearing a token) are fine — they're part of the submission, not the UI state.

---

## Rule 4: Capture `statusMessage` from `$fetch` errors

When `$fetch` rejects on a non-2xx response, it throws a `FetchError` carrying the server's `statusMessage` on `err.data.statusMessage`. Surface it; don't fall back to the generic `err.message` ("Bad Request").

```typescript
catch (err) {
  const statusMessage = (err as { data?: { statusMessage?: string } })?.data?.statusMessage
  errorState.value = statusMessage ?? (err instanceof Error ? err.message : 'Something went wrong.')
}
```

The server controls what the user sees by throwing `createError({ statusMessage: '...' })`.

---

## Rule 5: Split state into multiple keys when lifetimes differ

A single composable can hold several `useState` calls:

```typescript
const data        = useState<FormFlow>('form-flow', empty)
const submitState = useState<{ loading: boolean; error: string }>('form-flow-submit', () => ({ loading: false, error: '' }))
const uiState     = useState<{ visitedSteps: string[] }>('form-flow-ui', () => ({ visitedSteps: [] }))
```

Split when:
- `reset()` should clear some but not all
- One piece is UI-only and shouldn't be in the submitted payload
- One piece has independent reset triggers (close-modal vs submit-success)

Two or three keys is normal. Five+ is a sign the composable is doing too much.

---

## Reset pattern

```typescript
function empty(): FormFlow { return { name: '', email: '' } }

const data = useState<FormFlow>('form-flow', empty)

function reset() {
  data.value = empty()
}
```

`empty()` is a function so each call returns a fresh object. Avoids accidental shared-reference bugs if you later mutate a nested array.

Pass `empty` (no parens) to `useState` so it's called lazily.

---

## When to use this pattern vs alternatives

✓ State that survives route transitions (multi-step flow, navigation context)
✓ State shared between a page and its layout
✓ Cross-component state without prop drilling

When NOT to use:

✗ Single-page form — `reactive({...})` in the component is simpler
✗ Server-fetched data — `useFetch` / `useAsyncData` already cache by key
✗ Genuinely global state (user, theme) — those usually have their own composables (`useUser`, `useColorMode`) that wrap `useState`

## Related

- **[composables.md](./composables.md)** — singleton vs factory patterns, naming
- **[nuxt-pages/rendering-strategies.md](../../nuxt-pages/references/rendering-strategies.md)** — `ssr: false` route rules that pair with cross-route state
- **[nuxt-forms/marketing-forms.md](../../nuxt-forms/references/marketing-forms.md)** — public-form patterns that consume a state composable
