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

## What NOT to Test at This Tier

- Whole-page rendering with router, layout, meta tags → use Playwright
- Real auth flows → Playwright + provider test keys
- Side effects that cross Nitro (emails, queues, webhooks) → API integration + test seams
- Style/layout — out of scope; use visual regression elsewhere
