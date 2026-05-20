# E2E & API Integration

One Playwright config, two tiers. `page` for UI journeys, `request` for server pipelines.

## Setup

```ts
// playwright.config.ts
import { defineConfig, devices } from '@playwright/test'

export default defineConfig({
  testDir: './e2e',
  fullyParallel: false,   // shared in-memory test seams
  workers: 1,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  reporter: process.env.CI ? 'github' : 'list',
  use: {
    baseURL: 'http://localhost:3100',
    trace: 'retain-on-failure'
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
  webServer: {
    command: 'npm run dev -- --port 3100',
    url: 'http://localhost:3100',
    reuseExistingServer: !process.env.CI,
    timeout: 120_000,
    env: {
      NUXT_EMAIL_DRIVER: 'memory',
      NUXT_PUBLIC_TURNSTILE_SITE_KEY: '1x00000000000000000000AA',
      NUXT_TURNSTILE_SECRET_KEY: '1x0000000000000000000000000000000AA',
      NUXT_DEVTOOLS: 'false'
    }
  }
})
```

**Why these choices:**

- **`workers: 1` + `fullyParallel: false`** — required when test seams hold in-memory state (e.g. captured emails buffer). If you have none, parallelise.
- **`reuseExistingServer: !CI`** — locally, attach to an already-running `npm run dev`. In CI, always spawn fresh.
- **Env in `webServer.env`** — driver-swap and provider test keys; see [test-seams.md](./test-seams.md).
- **`trace: 'retain-on-failure'`** — full trace.zip on failure, nothing on green. Cheap when it matters.

```json
// package.json
"scripts": {
  "test:e2e": "playwright test",
  "test:e2e:ui": "playwright test --ui"
}
```

## File Layout

```
e2e/
├── contact.happy-path.spec.ts      ← API-level pipeline test
├── contact.validation.spec.ts      ← UI-level form validation
└── auth.login.spec.ts
```

Name by feature, then by the slice (`happy-path`, `validation`, `errors`). One spec file per slice keeps failure diagnosis localised.

## API Integration — Playwright `request` Fixture

Test the full Nitro request pipeline — body parsing, validation, provider verification (Turnstile), DB writes, side effects — **without rendering a browser**. Faster and more deterministic than the UI equivalent.

```ts
import { test, expect } from '@playwright/test'

const validPayload = {
  name: 'Ada Lovelace',
  email: 'ada@example.com',
  /* … */
  turnstileToken: 'test-token',
  website: '' // honeypot
}

test.beforeEach(async ({ request }) => {
  await request.get('/api/_dev/emails?clear=1')
})

test('POST /api/contact → 200 + both emails captured', async ({ request }) => {
  const res = await request.post('/api/contact', { data: validPayload })
  expect(res.status()).toBe(200)

  const body = await res.json() as { ok: boolean, id: string }
  expect(body.ok).toBe(true)
  expect(body.id).toMatch(/^[0-9a-f-]{36}$/)

  const { count, emails } = await (await request.get('/api/_dev/emails')).json()
  expect(count).toBe(2)

  const confirmation = emails.find(e => String(e.to).includes('ada@example.com'))
  expect(confirmation.subject).toContain('Submission received')
})

test('POST /api/contact with honeypot filled → silent 200, no emails', async ({ request }) => {
  const res = await request.post('/api/contact', {
    data: { ...validPayload, website: 'spammer-bait' }
  })
  expect(res.status()).toBe(200)

  const { count } = await (await request.get('/api/_dev/emails')).json()
  expect(count).toBe(0)
})

test('POST /api/contact with missing required field → 400', async ({ request }) => {
  const res = await request.post('/api/contact', { data: { ...validPayload, name: '' } })
  expect(res.status()).toBe(400)
})
```

**What this tier is for:**

- Server validation (status + error shape)
- Authn / authz boundaries
- Side effects via test seams (emails captured, queue jobs recorded, webhooks fired)
- Bot protection (honeypot, provider verification with test keys)
- DB writes (via response, or a `/api/_dev/<resource>` introspection endpoint)

**Header comment template** — pin the choice of tier so it survives future readers:

```ts
/**
 * API-level happy-path test. Exercises the full submission pipeline via a
 * direct POST to /api/contact — validates payload, checks Turnstile (CF test
 * keys), inserts into D1, fires both emails through the memory driver, then
 * asserts the captured email content.
 *
 * UI is covered separately in *.validation.spec.ts; this tier gives us the
 * fastest, most deterministic signal that the server pipeline is healthy.
 */
```

## UI E2E — Playwright `page` Fixture

Test client-side behaviour that has no server equivalent: form validation, navigation guards, conditional rendering, multi-step flows.

```ts
import { test, expect } from '@playwright/test'

test.beforeEach(async ({ request }) => {
  await request.get('/api/_dev/emails?clear=1')
})

test('preferences step blocks advance when required fields are empty', async ({ page }) => {
  await page.goto('/contact/preferences')
  await page.getByRole('button', { name: /continue/i }).click()

  await expect(page).toHaveURL(/\/contact\/preferences$/)

  const errors = page.locator('[id$="-error"], p.text-error, [role="alert"]')
  await expect(errors.first()).toBeVisible()
})

test('chip selection without dependent field blocks advance', async ({ page }) => {
  await page.goto('/contact/preferences')
  await page.getByLabel('Your name').fill('Test User')
  await page.getByLabel('Email').fill('test@example.com')
  await page.getByLabel('Company').fill('Test Co')
  await page.getByText('Select a plan').first().click()
  await page.getByRole('option', { name: 'Starter' }).click()
  await page.getByRole('button', { name: 'Premium', exact: true }).click()
  await page.getByRole('button', { name: /continue/i }).click()

  await expect(page).toHaveURL(/\/contact\/preferences$/)
})
```

## Selectors

Same priority as Testing Library:

1. `getByRole` — `page.getByRole('button', { name: /continue/i })`
2. `getByLabel` — `page.getByLabel('Email')`
3. `getByText` — non-interactive
4. `getByTestId` — last resort

Avoid CSS-only locators (`.btn-primary`) — they break when classes change without behaviour changing.

## Auto-Waiting

Playwright assertions auto-retry. Don't `waitForTimeout`; let `expect` poll.

```ts
// ✅
await expect(page).toHaveURL(/\/contact\/preferences$/)
await expect(page.getByRole('alert')).toBeVisible()

// ❌
await page.waitForTimeout(1000)
expect(await page.url()).toMatch(/\/contact\/preferences$/)
```

## Bot Protection / Captcha

Use provider test keys, not mocks:

| Provider   | Site key                              | Secret key                                       |
|------------|---------------------------------------|--------------------------------------------------|
| Turnstile  | `1x00000000000000000000AA` (pass)     | `1x0000000000000000000000000000000AA` (pass)     |
|            | `2x00000000000000000000AB` (fail)     | `2x0000000000000000000000000000000AA` (fail)     |
| reCAPTCHA v3 | `6LeIxAcTAAAAAJcZVRqyHh71UMIEGNQ_MXjiZKhI` | `6LeIxAcTAAAAAGG-vFI1TnRWxMZNFuojJ4WifJWe` |

This hits the real verification code path; mocking the verifier hides bugs.

## Auth

Two options, ordered by preference:

1. **API-level seed + cookie**: hit a `/api/_dev/login?as=admin` seam that issues a real session cookie, then drive the UI with that cookie set on the context.
2. **UI login + `storageState`**: log in once in a setup project, save `storageState`, reuse across specs.

`storageState` is simpler; the API seam is faster and skips UI fragility.

## CI Notes

- `retries: 2` on CI absorbs network flakes; locally `retries: 0` so flakes surface.
- `trace: 'retain-on-failure'` + upload `playwright-report/` artefact on failure.
- `reporter: 'github'` annotates PR diffs at failing lines.

## Testing Prerendered Output

`routeRules: { '/': { prerender: true } }` pages are baked to static HTML at build time. The dev server's runtime-rendered output is **not** identical to the prerendered HTML — meta tags injected late, modules that swap behaviour at build, and any `<ClientOnly>` content all differ.

For true SEO / OG-tag / structured-data assertions, test against the production preview build:

```ts
// playwright.preview.config.ts — separate config, separate npm script
import { defineConfig } from '@playwright/test'

export default defineConfig({
  testDir: './e2e-preview',
  use: { baseURL: 'http://localhost:3200' },
  webServer: {
    command: 'npm run build && npm run preview -- --port 3200',
    url: 'http://localhost:3200',
    timeout: 240_000,                 // build is slow
    reuseExistingServer: !process.env.CI
  }
})
```

```ts
// e2e-preview/seo.spec.ts
import { test, expect } from '@playwright/test'

test('home page has correct OG tags in prerendered HTML', async ({ request }) => {
  // Raw HTML, no JS evaluation — what crawlers see
  const html = await (await request.get('/')).text()

  expect(html).toContain('<meta property="og:title" content="Example"')
  expect(html).toContain('<meta property="og:description"')
  expect(html).toContain('<link rel="canonical" href="https://example.com/"')
})

test('sitemap.xml lists all prerendered routes', async ({ request }) => {
  const xml = await (await request.get('/sitemap.xml')).text()
  expect(xml).toContain('<loc>https://example.com/</loc>')
  expect(xml).toContain('<loc>https://example.com/about</loc>')
  expect(xml).toContain('<loc>https://example.com/pricing</loc>')
})
```

Why not just use `page.goto()` + `page.locator('meta[property="og:title"]')`? Because Playwright's page navigation runs JS, so a `useSeoMeta` call that *only fires on the client* would still pass — masking the bug where the prerendered HTML has wrong tags. Asserting on the raw HTML response forces the test to see exactly what a crawler sees.

```json
// package.json
"scripts": {
  "test:e2e": "playwright test",
  "test:e2e:preview": "playwright test --config=playwright.preview.config.ts"
}
```

Run `test:e2e:preview` in CI on a separate job — the build step makes it slow (~3 min cold), so don't bundle it with the fast dev-server e2e run.

## Test Fixtures and Builders

`validPayload` inline at the top of a spec file works for one spec. For three+ specs sharing the same domain object, lift it into a builder. Keeps tests readable and avoids "what changed?" diffs when the schema grows.

```ts
// e2e/fixtures/contact.ts
import type { ContactPayload } from '~~/shared/utils/contact-schema'

export const validContactPayload = (overrides: Partial<ContactPayload> = {}): ContactPayload => ({
  name: 'Ada Lovelace',
  email: 'ada@example.com',
  company: 'Lovelace & Babbage',
  /* ...all required fields with sensible defaults... */
  turnstileToken: 'test-token',
  website: '',
  ...overrides
})
```

```ts
// e2e/contact.validation.spec.ts
import { validContactPayload } from './fixtures/contact'

test('rejects empty name', async ({ request }) => {
  const res = await request.post('/api/contact', { data: validContactPayload({ name: '' }) })
  expect(res.status()).toBe(400)
})

test('rejects malformed email', async ({ request }) => {
  const res = await request.post('/api/contact', { data: validContactPayload({ email: 'not-an-email' }) })
  expect(res.status()).toBe(400)
})
```

Rules:

- **Builder, not constant.** A function that takes overrides composes; a constant doesn't.
- **Type the overrides against the same schema type the production code uses.** Pulls in via `~~/shared/utils/...` keeps fixtures and production schema in sync.
- **Don't add scenario-specific helpers to the base builder.** A `validContactPayloadForRateLimit()` belongs in the spec that uses it.

## What NOT to Test at This Tier

- Pure logic (model hydration, enum behaviour, error transformers) — vitest
- Component contracts (props/emits/v-model) — Testing Library
- Visual regression — out of scope
