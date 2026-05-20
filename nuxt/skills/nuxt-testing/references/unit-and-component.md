# Unit & Component Tests

vitest + `@nuxt/test-utils/config` + `happy-dom`. Testing Library for components.

## Setup

```ts
// vitest.config.ts
import { defineVitestConfig } from '@nuxt/test-utils/config'

export default defineVitestConfig({
  test: { environment: 'happy-dom' }
})
```

```json
// package.json
"scripts": {
  "test": "vitest",
  "test:run": "vitest run"
}
```

**Why `defineVitestConfig`:** wires up Nuxt auto-imports, layer aliases (`#layers/base/...`), and the runtime stub so model/composable code under test resolves the same way it does in the app.

## File Placement

Co-locate tests next to the unit they cover:

```
app/models/User.ts
app/models/User.test.ts
app/composables/useFilters.ts
app/composables/useFilters.test.ts
app/components/Form/AuthorSelect.vue
app/components/Form/AuthorSelect.test.ts
```

Use `*.test.ts` (not `*.spec.ts`) to keep Playwright (`*.spec.ts`) and vitest distinct.

## Unit — Pure Logic

Model hydration, enums, error transformers — no DOM, no Nuxt runtime, no fixtures.

```ts
// app/models/User.test.ts
import { describe, it, expect } from 'vitest'
import User from './User'

describe('User model', () => {
  it('hydrates id, name, roles from a UserResource payload', () => {
    const user = User.hydrate({
      id: 1, name: 'Alice', email: 'a@x.test', roles: ['admin'], permissions: []
    })

    expect(user.id).toBe(1)
    expect(user.roles).toEqual(['admin'])
  })

  it('isAdmin returns false when roles is missing', () => {
    const user = User.hydrate({ id: 3, name: 'Carol', email: 'c@x.test' })
    expect(user.isAdmin).toBe(false)
  })
})
```

**Pattern:** one behavioural fact per `it`. Hydrate the minimum payload that triggers the behaviour — no shared fixtures, no `beforeEach` setup if a single line will do.

## Unit — Error / Plugin Contracts

Cross-layer contracts (e.g. `ValidationError.mapToFormErrors`) are pure-logic units. Set up the transformer in `beforeAll`, then drive cases through tiny payloads:

```ts
import { describe, it, expect, beforeAll } from 'vitest'
import { ValidationError } from '#layers/base/app/errors/validation-error'

beforeAll(() => {
  ValidationError.setValidationErrorsTransformer(r => ({
    message: r._data?.message ?? '',
    errors: r._data?.errors ?? {}
  }))
})

it('preserves dotted/nested keys verbatim', () => {
  const error = new ValidationError(makeResponse({
    errors: { 'profile.name': ['The profile name is required.'] }
  }))
  expect(error.mapToFormErrors()).toEqual([
    { name: 'profile.name', message: 'The profile name is required.' }
  ])
})
```

## Composable Tests

Three flavours.

### 1. Independent composable — call directly

No lifecycle hooks, no `inject`, no `provide`. Just call it.

```ts
import { describe, it, expect } from 'vitest'
import useCounter from '~/composables/useCounter'

it('increments count', () => {
  const { count, increment } = useCounter(5)
  increment()
  expect(count.value).toBe(6)
})
```

### 2. Lifecycle-dependent composable — `withSetup`

If the composable uses `onMounted`, `watch`, `onScopeDispose`, wrap it in a throwaway component so the reactive scope is real.

```ts
import { defineComponent, h } from 'vue'
import { mount } from '@vue/test-utils'

function withSetup<T>(fn: () => T): { result: T, unmount: () => void } {
  let result!: T
  const wrapper = mount(defineComponent({
    setup() { result = fn(); return () => h('div') }
  }))
  return { result, unmount: () => wrapper.unmount() }
}

it('clears state on unmount', () => {
  const { result, unmount } = withSetup(() => useDocumentTitle('Test'))
  expect(document.title).toBe('Test')
  unmount()
  expect(document.title).toBe('')
})
```

### 3. Inject-dependent composable — `useInjectedSetup`

If the composable calls `inject(Key)`, wire a provider parent.

```ts
import { defineComponent, h, provide } from 'vue'
import { mount } from '@vue/test-utils'

function useInjectedSetup<T>(provides: Record<symbol | string, unknown>, fn: () => T) {
  let result!: T
  mount(defineComponent({
    setup() {
      for (const [key, value] of Object.entries(provides)) provide(key as string, value)
      result = fn()
      return () => h('div')
    }
  }))
  return result
}

it('reads injected slideover context', () => {
  const slideover = { isOpen: ref(true) }
  const ctx = useInjectedSetup({ [SlideoverKey as unknown as string]: slideover }, () => useSlideover())
  expect(ctx.isOpen.value).toBe(true)
})
```

## Singleton Composable State

Module-scope refs (the singleton pattern from `nuxt-composables`) leak across tests. Reset explicitly:

```ts
import { describe, it, beforeEach } from 'vitest'
import useUser from '~/composables/useUser'

beforeEach(() => useUser().clearUser())
```

If a composable has no `clear` method, that's a smell — add one, or switch it to the factory pattern.

## Component Tests — Testing Library

Drive components through user-visible affordances, not internal state.

```ts
import { describe, it, expect } from 'vitest'
import { render, screen } from '@testing-library/vue'
import userEvent from '@testing-library/user-event'
import AuthorSelect from './AuthorSelect.vue'

it('emits selected author on click', async () => {
  const { emitted } = render(AuthorSelect, {
    props: { authors: [{ id: '1', name: 'Ada' }] }
  })
  await userEvent.click(screen.getByRole('option', { name: 'Ada' }))
  expect(emitted('select')[0]).toEqual([{ id: '1', name: 'Ada' }])
})
```

**Query priority** (highest signal first):

1. `getByRole` / `findByRole` — what a screen reader sees
2. `getByLabelText` — form fields
3. `getByPlaceholderText` — last resort for inputs without labels (rare)
4. `getByText` — non-interactive content
5. `getByTestId` — only when no a11y-meaningful selector exists

**Async:** use `findBy*` (returns a Promise) or `await waitFor(() => …)`. Never `setTimeout`.

## v-model in Component Tests

Drive from the user side. Don't fake the binding through props/events.

```ts
// ✅ types into the input, asserts on rendered output
const { getByLabelText, getByText } = render(NameField)
await userEvent.type(getByLabelText('Name'), 'Ada')
expect(getByText('Hello, Ada')).toBeInTheDocument()

// ❌ asserts on a prop you fed in
render(NameField, { props: { modelValue: 'Ada', 'onUpdate:modelValue': vi.fn() } })
```

## Network in Component Tests — MSW

Component tests should not hit real HTTP. Set up MSW handlers and let `$fetch` resolve against them.

```ts
import { setupServer } from 'msw/node'
import { http, HttpResponse } from 'msw'

const server = setupServer(
  http.get('/api/posts', () => HttpResponse.json([{ id: 1, title: 'Hi' }]))
)

beforeAll(() => server.listen({ onUnhandledRequest: 'error' }))
afterEach(() => server.resetHandlers())
afterAll(() => server.close())
```

`onUnhandledRequest: 'error'` is non-negotiable — silent passthrough hides missing handlers.

## Testing `useFetch` / `useAsyncData`

Nuxt's data composables wrap `$fetch` with cache keys, SSR payload hydration, and refresh semantics. Pure-`vi.mock('#app')` stubs won't exercise that — and you usually don't want to test Nuxt internals anyway. Test the *behaviour* that surrounds the fetch.

### Pattern: route the network through MSW, let the composable run normally

```ts
import { setupServer } from 'msw/node'
import { http, HttpResponse } from 'msw'
import { render, screen } from '@testing-library/vue'

const server = setupServer(
  http.get('/api/posts', () => HttpResponse.json({ data: [{ id: 1, title: 'Hi' }] }))
)

beforeAll(() => server.listen({ onUnhandledRequest: 'error' }))
afterEach(() => server.resetHandlers())
afterAll(() => server.close())

it('renders fetched posts', async () => {
  render(PostList)
  expect(await screen.findByText('Hi')).toBeInTheDocument()
})
```

The composable's cache key, error state, and `pending` flag all resolve through the real Nuxt runtime. The only thing stubbed is the network — which is what you'd be stubbing in production tests too.

### Cache key collisions between tests

`useFetch('/api/posts')` and `useAsyncData('posts', ...)` cache by key in the Nuxt payload. Within a single vitest worker, a cached payload from test A can satisfy test B's call → no MSW handler fires → silent stale data.

Clear between tests:

```ts
afterEach(() => {
  clearNuxtData()  // built-in; clears all useAsyncData / useFetch caches
})
```

Or scope explicitly: `clearNuxtData('posts')`.

If you see "MSW: handler set up but no request" or stale data across tests, this is almost always the cause.

### Don't try to assert on the SSR payload

The payload object is an implementation detail. Test what the user sees (`findByText`, `findByRole`) or what the composable returns (`data.value`, `error.value`, `pending.value`).

## Nuxt UI Component Quirks in Tests

Nuxt UI components are Reka UI under the hood — they render rich DOM, not native form elements. A few patterns that surprise newcomers:

### `USelectMenu` / `USelect`

There's no native `<select>`. The trigger is a button with the placeholder/value as visible text; the listbox is a portal'd popover.

```ts
// ✅ open the menu, then click the option by accessible name
await userEvent.click(screen.getByRole('button', { name: 'Select a range' }))
await userEvent.click(screen.getByRole('option', { name: 'Under £100k' }))
expect(screen.getByRole('button', { name: 'Under £100k' })).toBeInTheDocument()

// ❌ fireEvent.change(getByLabelText('Revenue'), { target: { value: '...' } })
// no native <select> to dispatch change against — silently no-ops
```

If you can't find the option after opening, the listbox is rendered in a portal — make sure the test setup mounts to a real DOM (`happy-dom` is fine), not an isolated shadow root.

### `URadioGroup` / `UCheckbox`

Real `<input type="radio">` / `<input type="checkbox">` underneath. Driveable via the standard role queries:

```ts
await userEvent.click(screen.getByRole('radio', { name: 'Yes' }))
```

### `UInput` / `UTextarea`

Wrap a native `<input>` / `<textarea>`. `getByLabelText` works iff a `<label>` association exists (`for=` or wrapping). If a `<MonoLabel as="label">` is used without `for=`, you'll need `getByPlaceholderText` or a `data-testid`.

### `NuxtTurnstile`

Renders an iframe. Don't try to interact with it; use Cloudflare's test site key (always passes) in `playwright.config.ts` env and verify the form submits successfully. The widget's `v-model` token populates async — see the marketing-forms reference for the token-await pattern in production code.

### `UButton :loading="true"`

When loading, the button has `aria-busy="true"` and is disabled. `getByRole('button', { name: 'Submit' })` still finds it; the click is a no-op. To assert the loading state:

```ts
await expect(screen.getByRole('button', { name: /submit/i })).toBeDisabled()
```

## Time and Fake Clocks

Use `vi.useFakeTimers()` when:

- Code calls `setTimeout` / `setInterval` and you'd otherwise wait real time
- Code depends on `Date.now()` and you need a stable clock (rate limiters, debounce, token expiry)
- Testing `setTimeout`-based timeouts (e.g. the Turnstile 8s token-await)

```ts
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

beforeEach(() => { vi.useFakeTimers() })
afterEach(()  => { vi.useRealTimers() })

it('rejects when the token never arrives', async () => {
  const promise = waitForToken({ timeoutMs: 8000 })
  vi.advanceTimersByTime(8000)
  await expect(promise).rejects.toThrow(/took too long/)
})
```

Rules:

- **Always restore real timers in `afterEach`.** Fake timers leak into the next test and break async assertions in subtle ways.
- **`vi.advanceTimersByTimeAsync(ms)`** (not `advanceTimersByTime`) when the code under test awaits a microtask between timer firings. The async variant flushes the microtask queue between ticks.
- **Don't mix `vi.useFakeTimers()` with Testing Library's `findBy*`/`waitFor`** — those use real `setTimeout` for polling. Either fake everything or fake nothing within a test.

## What NOT to Test at This Tier

- Whole-page rendering with router, layout, meta tags → use Playwright
- Real auth flows → Playwright + provider test keys
- Side effects that cross Nitro (emails, queues, webhooks) → API integration + test seams
- Style/layout — out of scope; use visual regression elsewhere
