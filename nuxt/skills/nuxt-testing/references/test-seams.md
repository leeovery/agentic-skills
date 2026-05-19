# Test Seams for Nitro Side Effects

The Nitro server runs in its own process; tests can't reach into module-scope state directly. Build a seam: swap the side-effect driver to an in-memory implementation via env, expose its buffer through an env-guarded `/api/_dev/*` endpoint, assert against that.

Same pattern works for emails, queued jobs, flash messages, captured analytics events, webhook outbox, audit log writes.

## Pattern in Three Parts

1. **Driver abstraction** — production driver + memory driver, selected by env
2. **Env-guarded `/api/_dev/*` endpoint** — exposes the buffer to tests only
3. **`beforeEach` clear** — keeps tests independent

## 1. Driver Abstraction

```ts
// server/utils/email.ts
export interface EmailPayload {
  from: string
  to: string | string[]
  subject: string
  html: string
  text: string
  reply_to?: string | string[]
}

const memoryBuffer: EmailPayload[] = []
const MEMORY_BUFFER_MAX = 50

export function getCapturedEmails(): ReadonlyArray<EmailPayload> {
  return memoryBuffer
}

export function clearCapturedEmails(): void {
  memoryBuffer.length = 0
}

export async function sendEmail(payload: EmailPayload): Promise<void> {
  const { resendApiKey, emailDriver } = useRuntimeConfig()

  if (emailDriver === 'memory') {
    memoryBuffer.push(payload)
    if (memoryBuffer.length > MEMORY_BUFFER_MAX) memoryBuffer.shift()
    return
  }

  if (!resendApiKey) {
    console.warn('[email] no resendApiKey + driver is not memory — skipping send')
    return
  }

  await $fetch('https://api.resend.com/emails', {
    method: 'POST',
    headers: { Authorization: `Bearer ${resendApiKey}`, 'Content-Type': 'application/json' },
    body: payload
  })
}
```

**Bounded buffer.** A leaky test seam will eventually OOM the dev server. Cap it.

**Config wiring** — drivers read via `useRuntimeConfig()`, so `NUXT_EMAIL_DRIVER=memory` flips the behaviour:

```ts
// nuxt.config.ts
runtimeConfig: {
  emailDriver: '',     // NUXT_EMAIL_DRIVER
  resendApiKey: '',    // NUXT_RESEND_API_KEY
  /* ... */
}
```

## 2. Env-Guarded Dev Endpoint

```ts
// server/api/_dev/emails.get.ts
/**
 * Returns captured emails for e2e assertion. Only available when the email
 * driver is the memory driver (NUXT_EMAIL_DRIVER=memory). Guarded so it never
 * exposes data in production.
 */
export default defineEventHandler(async (event) => {
  const { emailDriver } = useRuntimeConfig()
  if (emailDriver !== 'memory') {
    throw createError({ statusCode: 404 })
  }

  const url = getRequestURL(event)
  if (url.searchParams.get('clear') === '1') {
    clearCapturedEmails()
    return { ok: true, count: 0, emails: [] }
  }

  const emails = getCapturedEmails()
  return { ok: true, count: emails.length, emails }
})
```

**Guard rules:**

- Return `404` (not `403`) when the seam is off — the endpoint should look non-existent in prod
- Gate by **driver mode** (`emailDriver === 'memory'`), not by `process.env.NODE_ENV` — driver mode is the same flag the seam itself uses, so it's impossible to drift
- Path-prefix `/api/_dev/` so it's grep-able and easy to firewall at the edge if extra paranoia is needed

## 3. `beforeEach` Clear

```ts
test.beforeEach(async ({ request }) => {
  await request.get('/api/_dev/emails?clear=1')
})
```

Module-scope buffers persist across tests within the same dev-server lifetime. Clear at the start, not the end — that way the buffer also clears after a failed test that didn't reach an `afterEach`.

## When To Add a Seam

Add one when behaviour you care about is invisible from the outside:

- Did this code send the right email? → email seam
- Did this code enqueue the right job? → queue seam (`/api/_dev/jobs`)
- Did this code write to the audit log? → audit seam
- Did this code emit the right analytics event? → analytics seam

**Don't add one when** the behaviour is already visible through the normal response or via a DB read — assert directly. Seams are for invisible side effects.

## When NOT to Mock Instead

A common anti-pattern: stub the module export in the test runtime.

```ts
// ❌ Tightly couples test to internal module path; only works for unit tests,
// not Playwright (which runs against a real Nitro process).
vi.mock('~/server/utils/email', () => ({ sendEmail: vi.fn() }))
```

The seam approach:

- **Works at every tier** — unit, component, Playwright API, Playwright UI
- **Tests the real production code path** including config plumbing and serialisation
- **Survives refactors** that move the side effect to a different module — the contract is the env flag, not the import path
- **Catches bugs in the driver swap itself** — that bug is real and shippable; a mock would hide it

Reach for a mock only when the side effect is *external infrastructure* you can't host yourself and the provider has no test mode (rare — most do; see [e2e-and-api.md](./e2e-and-api.md) on provider test keys).

## Layout Convention

```
server/
├── api/
│   ├── wizard.post.ts             # real handler
│   └── _dev/                     # seam endpoints — env-guarded
│       ├── emails.get.ts
│       ├── jobs.get.ts
│       ├── audit.get.ts
│       └── db.get.ts             # row-count / table-clear seam
└── utils/
    ├── email.ts                  # driver + memory buffer
    ├── jobs.ts
    └── audit.ts
```

One seam endpoint per side-effect domain. Each endpoint supports `?clear=1` for `beforeEach` reset.

## DB Test Isolation (D1 / Drizzle)

Module-scope buffers are easy. Real-DB writes are harder — D1's local SQLite file persists across test runs and accumulates rows. Two approaches; pick by speed cost.

### Option A: env-guarded table-clear endpoint (recommended)

Cheap, deterministic, mirrors the email-seam pattern.

```ts
// server/api/_dev/db.get.ts
/**
 * Test-mode-only DB seam. Returns row counts or clears tables. Guarded by
 * NUXT_DB_TEST_MODE; never exposed in production. Whitelist tables explicitly
 * so a misconfigured env can't wipe arbitrary tables.
 */
import { submissions } from '../../db/schema'

const CLEARABLE = { submissions } as const
type Clearable = keyof typeof CLEARABLE

export default defineEventHandler(async (event) => {
  const { dbTestMode } = useRuntimeConfig()
  if (dbTestMode !== '1') {
    throw createError({ statusCode: 404 })
  }

  const url = getRequestURL(event)
  const clearParam = url.searchParams.get('clear')

  if (clearParam) {
    if (!(clearParam in CLEARABLE)) {
      throw createError({ statusCode: 400, statusMessage: `Table '${clearParam}' is not clearable` })
    }
    await db.delete(CLEARABLE[clearParam as Clearable])
    return { ok: true, cleared: clearParam }
  }

  const counts = await Promise.all(
    (Object.keys(CLEARABLE) as Clearable[]).map(async (t) => [t, await db.$count(CLEARABLE[t])] as const)
  )
  return { ok: true, counts: Object.fromEntries(counts) }
})
```

```ts
// playwright spec
test.beforeEach(async ({ request }) => {
  await request.get('/api/_dev/emails?clear=1')
  await request.get('/api/_dev/db?clear=submissions')
})

test('POST /api/wizard persists a row', async ({ request }) => {
  await request.post('/api/wizard', { data: validPayload })

  const { counts } = await (await request.get('/api/_dev/db')).json()
  expect(counts.submissions).toBe(1)
})
```

**Guard rules** (same as the email seam, with extras):

- Returns `404` (not `403`) when `dbTestMode !== '1'`
- Whitelist `CLEARABLE` — a typed record of `<name, drizzle table>` so the query param can never resolve to an arbitrary table name (SQL injection / wipe-the-whole-DB risk)
- Use a dedicated env flag (`NUXT_DB_TEST_MODE`) — *not* `NODE_ENV` — so prod can never accidentally enable it via the Worker's default env
- Test against the **local D1 dev binding**, never `--remote`

Wire the env into runtimeConfig:

```ts
// nuxt.config.ts
runtimeConfig: {
  dbTestMode: '',   // NUXT_DB_TEST_MODE — '1' enables /api/_dev/db
  emailDriver: '',
  /* ... */
}
```

```ts
// playwright.config.ts → webServer.env
env: {
  NUXT_EMAIL_DRIVER: 'memory',
  NUXT_DB_TEST_MODE: '1'
}
```

### Option B: transaction rollback (slower, more isolated)

Wrap each test's handler invocation in a transaction that rolls back at the end. SQLite/D1 supports nested savepoints. Avoid unless table-clear isn't enough — transactions add complexity and the Worker request lifecycle doesn't play nicely with long-lived transactions.

### Don't

- ❌ `vi.mock('~/server/db')` — same seam-vs-mock argument as email; mocks lie
- ❌ Use the production D1 (`--remote`) for tests — slow, racy, irreversible
- ❌ Drop and re-create tables between tests — migrations are tested separately; the test path should match production schema exactly
- ❌ Rely on test ordering to leave a DB state for the next test — clear in `beforeEach`

## Multi-Worker Caution

Module-scope buffers live in **one** Nitro process. If you set `workers: > 1` in Playwright (or run multiple specs in parallel against the same dev server), buffers interleave across tests and assertions go non-deterministic.

Rules:

- **One worker, one dev server** → fine, the default for projects using these seams
- **Many workers, many dev servers (per-worker port)** → fine, but each spec hits its own seam buffer
- **Many workers, one dev server** → broken; don't do this

## Production Safety Checklist

Before merging a seam:

- [ ] Endpoint returns `404` when the driver is not the memory driver
- [ ] Driver mode is the *only* gate (no `NODE_ENV` checks that can drift)
- [ ] Buffer is bounded (`MEMORY_BUFFER_MAX`)
- [ ] Endpoint path is under `/api/_dev/`
- [ ] No PII in error logs from the seam path
