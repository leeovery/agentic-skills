# Multi-step Form State (SSR-safe, cross-route)

A composable that holds form state across several route-level pages.
Each page mounts/unmounts as the user navigates, but the state
survives — because it lives in `useState`, not in the component.

Pattern from `reach-systems-app/app/composables/useApplyForm.ts`.

---

## Why `useState`, not `ref`

`useState(key, init)` is Nuxt's SSR-safe shared-state primitive. Two
properties matter here:

1. **Keyed by string** — calling `useState('apply-form')` from any
   component returns the same reactive ref. State follows the key,
   not the component instance.
2. **SSR-safe** — server and client read the same value. No hydration
   mismatch when the page is prerendered or SSR'd.

A plain `ref` in the composable's module scope would *also* be shared
— but it would leak across requests on the server (one user's data
could surface in another's response). `useState` is request-scoped on
the server and module-scoped on the client.

For an SPA-only route (`ssr: false` via `routeRules`) you could get
away with `ref`, but `useState` is the right default. Don't optimise
prematurely.

---

## Shape

```typescript
// composables/useApplyForm.ts

interface ApplyFormData {
  name: string
  email: string
  business: string
  services: string[]
  // …
  turnstileToken: string
}

interface SubmitState {
  loading: boolean
  error: string
}

const STEP_META: Record<string, { step: number; label: string }> = {
  'fit-check':   { step: 1, label: 'Step 1 of 4' },
  'business':    { step: 2, label: 'Step 2 of 4' },
  'growth':      { step: 3, label: 'Step 3 of 4' },
  'partnership': { step: 4, label: 'Step 4 of 4' },
  'thanks':      { step: 4, label: 'Application received' }
}

function emptyForm(): ApplyFormData {
  return { name: '', email: '', business: '', services: [], turnstileToken: '' /* … */ }
}

export function useApplyForm() {
  const form        = useState<ApplyFormData>('apply-form', emptyForm)
  const submitState = useState<SubmitState>('apply-submit', () => ({ loading: false, error: '' }))
  const fitAnswers  = useState<boolean[]>('apply-fit', () => Array(5).fill(false))

  const fitCount     = computed(() => fitAnswers.value.filter(Boolean).length)
  const fitQualifies = computed(() => fitCount.value >= 3)

  // Derive current step from the route, not from a tracked index. Means a
  // user landing on /apply/growth via URL gets the correct step state.
  const route = useRoute()
  const stepMeta = computed(() => {
    const slug = route.path.split('/').filter(Boolean).pop() ?? 'fit-check'
    return STEP_META[slug] ?? STEP_META['fit-check']!
  })

  function reset() {
    form.value        = emptyForm()
    fitAnswers.value  = Array(5).fill(false)
    submitState.value = { loading: false, error: '' }
  }

  async function submit() { /* see below */ }

  return { form, fitAnswers, fitCount, fitQualifies, stepMeta, submit, submitState, reset }
}
```

---

## Step detection from `route.path`

The composable derives the current step from `route.path`, not from
an internal counter. Why this matters:

- **Deep-linking works.** A user with a saved URL hits `/apply/growth`
  and sees step 3, with the form state empty if they haven't filled
  earlier steps.
- **Back/forward browser nav works.** No internal state to keep in
  sync with the URL.
- **Single source of truth.** The URL is canonical.

```typescript
const route = useRoute()
const stepMeta = computed(() => {
  const slug = route.path.split('/').filter(Boolean).pop() ?? 'fit-check'
  return STEP_META[slug] ?? STEP_META['fit-check']!
})
```

`STEP_META` is a static record mapping route slugs to metadata
(step number, display label). Add the meta to the map, not to each
page component, so the navigation flow is defined once.

---

## Submit with side effects

```typescript
async function submit() {
  submitState.value.error = ''

  const payload = { ...form.value, fitCount: fitCount.value }

  const parsed = applyPayloadSchema.safeParse(payload)
  if (!parsed.success) {
    submitState.value.error = parsed.error.issues[0]?.message ?? 'Please check your answers.'
    return
  }

  try {
    await $fetch('/api/apply', { method: 'POST', body: parsed.data })
    reset()
    await navigateTo('/apply/thanks')
  } catch (err) {
    const statusMessage = (err as { data?: { statusMessage?: string } })?.data?.statusMessage
    submitState.value.error = statusMessage || (err instanceof Error ? err.message : 'Something went wrong.')
    form.value.turnstileToken = ''   // force fresh token on retry
  }
}
```

Notes:

- **Caller owns `submitState.loading`.** The submit button's
  `:disabled="submitState.loading"` already blocks re-entry; toggling
  it inside `submit()` would fight the UI. The page's click handler
  sets it.
- **Reset → navigate after success.** Form is cleared before the
  navigation; thanks page loads with empty state.
- **Capture `statusMessage` from server.** `$fetch` rejects with a
  FetchError that carries the server's `statusMessage` on
  `err.data.statusMessage`. Surface it to the user.
- **Clear the Turnstile token on failure.** Tokens are single-use; the
  next attempt needs a fresh one.

---

## Multi-key state for related-but-independent pieces

The composable splits state across multiple `useState` keys:

- `apply-form` — the form data
- `apply-submit` — submit loading/error
- `apply-fit` — the fit-check checkboxes (separate because they're
  visual state, not part of the submitted payload)

Why split: each piece has different lifetime + reset behaviour.
`reset()` clears all three; an individual error message can update
without touching the form data; the fit checkboxes don't get
serialised into the API payload.

Don't over-split — three keys here is the natural shape, not five.

---

## Reset pattern

`reset()` returns each piece of state to its initial value:

```typescript
function reset() {
  form.value        = emptyForm()
  fitAnswers.value  = Array(5).fill(false)
  submitState.value = { loading: false, error: '' }
}
```

`emptyForm()` is a function (not a constant) so each call returns a
fresh object — guards against accidental shared-reference bugs if you
later mutate a nested array.

Pass `emptyForm` (no parens) into `useState` as the init function.
`useState` calls it lazily, only when the key isn't yet populated.

---

## When to use this pattern

✓ Multi-step form spanning multiple routes
✓ A wizard with shared progress across pages
✓ Any cross-route state that should survive navigation

When NOT to use:

✗ Single-page form — `reactive({...})` in the component is fine
✗ Genuinely global app state (current user, theme) — those have their
  own composables (`useUser`, `useColorMode`)
✗ Server-fetched data — use `useFetch` / `useAsyncData` instead

---

## Anti-patterns

```typescript
// ❌ module-scoped ref — leaks across requests on the server
const form = ref({ name: '', email: '' })
export function useApplyForm() { return { form } }

// ❌ internal step counter — drifts from the URL
const currentStep = ref(1)
function next() { currentStep.value++ ; router.push(...) }

// ❌ submit fully owns loading; UI's :disabled does too — they fight
async function submit() {
  submitState.value.loading = true   // ← page already set this
  // …
  submitState.value.loading = false
}

// ❌ reset doesn't clear submitState — error persists across submissions
function reset() {
  form.value = emptyForm()
}
```

---

## Related

- **[nuxt-forms](../../nuxt-forms/references/marketing-forms.md)** —
  the per-step `<UForm>` that consumes this composable, plus Turnstile
  + honeypot + server route
- **[nuxt-pages](../../nuxt-pages/references/rendering-strategies.md)** —
  the `ssr: false` route rule that makes SPA navigation between steps
  cheap
- **[composables.md](./composables.md)** — composable patterns
  (singleton vs factory, naming, exports)
