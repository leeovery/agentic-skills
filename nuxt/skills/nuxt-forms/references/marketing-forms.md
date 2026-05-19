# Marketing Forms (no heavy library)

Multi-step forms on a public marketing site have a different shape to
admin-app forms:

- Anonymous, public surface → spam defence matters
- No backing repository or model → no `useForm(url, …)`-style hook
- Shared state across multiple route-level pages, not steps within one
  component → state lives in a composable, not the component
- Server-side validation is the source of truth — client validation
  is UX sugar

This file documents that pattern as built for the reach-systems apply
flow: `UForm` + Zod schemas + Turnstile + honeypot + Resend + D1.

---

## Shape: composable + per-step `UForm`

State lives in a `useApplyForm()` composable (see
`nuxt-composables/multi-step-state.md` for the composable itself).
Each step is a route-level page with its own `<UForm>`:

```vue
<!-- pages/apply/partnership.vue -->
<script setup lang="ts">
import type { FormSubmitEvent, Form } from '@nuxt/ui'

definePageMeta({ layout: 'apply' })

const { form, submit, submitState } = useApplyForm()

const formRef = useTemplateRef<InstanceType<typeof Form>>('form')
const turnstileRef = ref<{ reset: () => void } | null>(null)

type Schema = typeof partnershipStepSchema

async function onSubmit(_event: FormSubmitEvent<Schema>) { … }
</script>

<template>
  <UForm ref="form" :schema="partnershipStepSchema" :state="form" @submit="onSubmit">
    <UFormField name="services" required>
      <ApplyChipGroup v-model="form.services" :options="servicesOptions" multiple />
    </UFormField>

    <!-- honeypot, turnstile, submit -->
  </UForm>
</template>
```

The `:state="form"` binds `UForm` to the composable's shared state.
The `:schema="…"` is the **per-step** Zod schema (a subset of the
full payload schema).

---

## Zod schemas: shared client + server, sliced per step

One schema file in `shared/utils/` so client (Nuxt auto-imports from
`shared/utils/`) and server (Nitro auto-imports the same dir) both
see it.

```typescript
// shared/utils/apply-schema.ts
import { z } from 'zod'

export const applyFormSchema = z.object({
  name:    z.string().trim().min(1).max(200),
  email:   z.string().trim().toLowerCase().email().max(200),
  // ...
  services: z.array(z.string().max(100)).min(1).max(10),
  budget:   z.string().trim().min(1).max(100)
})

// Step schemas — subsets of the full schema, for per-step UForm validation
export const businessStepSchema    = applyFormSchema.pick({ name: true, email: true, /* ... */ })
export const growthStepSchema      = applyFormSchema.pick({ acquisition: true, challenge: true, agency: true })
export const partnershipStepSchema = applyFormSchema.pick({ services: true, budget: true, camera: true, other: true })

// Payload schema — what the server actually accepts. Extends the full schema
// with server-required fields (Turnstile token, honeypot, derived counts).
export const applyPayloadSchema = applyFormSchema.extend({
  fitCount:       z.number().int().min(0).max(5),
  turnstileToken: z.string().min(1, 'Human verification required'),
  website:        z.string().max(200).optional().default('')  // honeypot
})

export type ApplyForm = z.infer<typeof applyFormSchema>
export type ApplyPayload = z.infer<typeof applyPayloadSchema>
```

Pattern:

- **`applyFormSchema`** — the canonical form shape (without
  server-only fields)
- **`<step>StepSchema`** — `pick()` subsets for per-step `:schema`
  validation
- **`applyPayloadSchema`** — `.extend()` on top of the form schema
  with server-required fields (Turnstile, honeypot, derived totals).
  This is what the server route validates against.

Why subsets matter: each step's `<UForm :schema="...">` runs only
the validators relevant to that step's fields. The user doesn't get
"phone is required" while filling out the business step if phone
lives on a later step.

### Coercion via `z.preprocess`

URL fields that accept `example.com` and prepend `https://`:

```typescript
function coerceUrl(v: unknown): unknown {
  if (typeof v !== 'string') return v
  const t = v.trim()
  if (!t) return ''
  if (/^https?:\/\//i.test(t)) return t
  return `https://${t}`
}

const optionalUrl = z.preprocess(
  coerceUrl,
  z.string().url('Enter a valid URL').or(z.literal(''))
).optional().default('')
```

`z.preprocess(coerce, schema)` runs the coercion before validation —
keep the coercion plain TS, not a Zod transform, so it's reusable.

---

## Turnstile invisible mode

```vue
<NuxtTurnstile ref="turnstileRef" v-model="form.turnstileToken" />
```

Configuration:

- Widget mode set to "invisible" on the Turnstile dashboard
- Site key hardcoded in `nuxt.config.ts` (`turnstile.siteKey`) — public,
  domain-restricted; see nuxt-config/cloudflare-deployment.md for why
- Secret key set as a Worker secret (`NUXT_TURNSTILE_SECRET_KEY`)

### Token-await pattern

The widget executes async after mount; a fast user can click submit
before the token arrives. Wait briefly, then fail:

```typescript
async function onSubmit() {
  submitState.value.error = ''

  try {
    if (!form.value.turnstileToken) {
      await new Promise<void>((resolve, reject) => {
        const stop = watch(() => form.value.turnstileToken, (token) => {
          if (token) { stop(); resolve() }
        })
        setTimeout(() => {
          stop()
          reject(new Error('Verification took too long. Please refresh the page and try again.'))
        }, 8000)
      })
    }
    await submit()
  } catch (err) {
    submitState.value.error = err instanceof Error ? err.message : 'Something went wrong.'
    turnstileRef.value?.reset()
    form.value.turnstileToken = ''
  }
}
```

On submit failure, reset the widget so the next attempt gets a fresh
token (Turnstile tokens are single-use).

### Server verification

```typescript
// server/api/apply.post.ts
const result = await verifyTurnstileToken(data.turnstileToken, event)
if (!result.success) {
  throw createError({ statusCode: 400, statusMessage: 'Human verification failed. Please try again.' })
}
```

`verifyTurnstileToken` is auto-provided by `@nuxtjs/turnstile`'s Nitro
side. Verifies against Cloudflare's siteverify endpoint using the
secret key.

---

## Honeypot field

A hidden input that humans don't see but bots fill. Server silently
accepts honeypot submissions (returning `{ ok: true, id: 'ignored' }`)
to avoid signalling rejection.

```html
<div aria-hidden="true" class="absolute left-[-9999px] h-0 overflow-hidden">
  <label>
    Website
    <input v-model="form.website" type="text" tabindex="-1" autocomplete="off">
  </label>
</div>
```

```typescript
// server
if (typeof raw?.website === 'string' && raw.website.trim() !== '') {
  return { ok: true, id: 'ignored' }   // silently drop
}
```

Choose a field name bots find tempting (`website`, `url`, `email_alt`).
Don't name it `honeypot` — bots may filter that out.

`tabindex="-1"` + `autocomplete="off"` + offscreen positioning +
`aria-hidden` keeps it invisible to keyboard users and screen readers.

---

## The UForm submit-button gotcha

Reach-systems found that a standard `<UButton type="submit">` inside
`<UForm>` silently no-op'd in their setup (click → no event, no
network). Direct trigger via the form's exposed `submit()` method
works deterministically:

```vue
<script setup lang="ts">
const formRef = useTemplateRef<InstanceType<typeof Form>>('form')

async function handleSubmitClick() {
  if (submitState.value.loading) return
  submitState.value.loading = true
  try {
    await formRef.value?.submit()
  } finally {
    submitState.value.loading = false
  }
}
</script>

<template>
  <UForm ref="form" :schema="..." :state="form" @submit="onSubmit">
    <!-- fields -->
    <UButton :loading="submitState.loading" :disabled="submitState.loading" @click="handleSubmitClick">
      Submit
    </UButton>
  </UForm>
</template>
```

Notes:

- `type="submit"` is omitted from the button — Vue triggers via
  `@click` instead
- Manual loading toggle + `:disabled` prevents re-entry
- Errors caught in `onSubmit` are surfaced via shared state
  (`submitState.error`) rather than a UForm-internal store

If you also try `<button type="submit">` and it works for you, great
— but the manual-trigger path is the one to fall back to if you ever
see a silent submit no-op.

---

## Chip toggle component (single/multi-select)

```vue
<!-- ApplyChipGroup.vue -->
<script setup lang="ts">
const model = defineModel<string | string[]>()

const props = defineProps<{
  options: string[]
  multiple?: boolean
}>()

function isSelected(option: string): boolean {
  if (props.multiple) {
    return Array.isArray(model.value) && model.value.includes(option)
  }
  return model.value === option
}

function toggle(option: string) {
  if (props.multiple) {
    const current = Array.isArray(model.value) ? model.value : []
    model.value = current.includes(option)
      ? current.filter(o => o !== option)
      : [...current, option]
  } else {
    model.value = option
  }
}
</script>

<template>
  <div class="flex flex-wrap gap-2">
    <UButton
      v-for="option in options"
      :key="option"
      :color="isSelected(option) ? 'primary' : 'neutral'"
      :variant="isSelected(option) ? 'solid' : 'outline'"
      size="md"
      :ui="{ base: 'rounded-full min-w-20 justify-center' }"
      @click="toggle(option)"
    >
      {{ option }}
    </UButton>
  </div>
</template>
```

Why `defineModel<string | string[]>()`: same component handles
single and multi via the `multiple` prop. Type union matches what
the consumer binds:

```vue
<ApplyChipGroup v-model="form.services" :options="servicesOptions" multiple />
<ApplyChipGroup v-model="form.camera"   :options="cameraOptions" />
```

Built from `UButton`s rather than raw buttons so it inherits the
Nuxt UI focus ring, sizing, and `:ui` overrides.

---

## Server route shape

```typescript
// server/api/apply.post.ts
const MAX_PER_IP_PER_HOUR = 3

export default defineEventHandler(async (event) => {
  const raw = await readBody(event)

  // Honeypot
  if (typeof raw?.website === 'string' && raw.website.trim() !== '') {
    return { ok: true, id: 'ignored' }
  }

  // Schema validation
  const parsed = applyPayloadSchema.safeParse(raw)
  if (!parsed.success) {
    throw createError({
      statusCode: 400,
      statusMessage: parsed.error.issues[0]?.message ?? 'Invalid submission.',
      data: { issues: parsed.error.issues }
    })
  }
  const data = parsed.data

  // Turnstile
  const result = await verifyTurnstileToken(data.turnstileToken, event)
  if (!result.success) {
    throw createError({ statusCode: 400, statusMessage: 'Human verification failed.' })
  }

  // Rate limit per IP
  const ip = getRequestIP(event, { xForwardedFor: true }) ?? ''
  if (ip) {
    const [row] = await db.select({ count: sql<number>`count(*)` })
      .from(applications)
      .where(and(
        eq(applications.ip, ip),
        gt(applications.createdAt, sql`datetime('now', '-1 hour')`)
      ))
    if (row && row.count >= MAX_PER_IP_PER_HOUR) {
      throw createError({ statusCode: 429, statusMessage: 'Too many submissions. Please try again later.' })
    }
  }

  // Persist + notify
  await db.insert(applications).values({ id: crypto.randomUUID(), ip, ...data })

  // Fire emails in parallel; don't fail the request if email fails — the row is saved
  await Promise.allSettled([
    sendFounderNotification(record),
    sendApplicantConfirmation(record)
  ])

  return { ok: true, id: record.id }
})
```

Order matters: honeypot first (cheapest), then schema, then Turnstile
(network call), then rate limit (DB read), then persist. Reject as
early as possible.

Emails via `Promise.allSettled` so a Resend hiccup doesn't fail the
submission — the row is the source of truth.

### Client-side error surfacing

Server `statusMessage` is captured by the composable:

```typescript
try {
  await $fetch('/api/apply', { method: 'POST', body: parsed.data })
} catch (err) {
  const statusMessage = (err as { data?: { statusMessage?: string } })?.data?.statusMessage
  submitState.value.error = statusMessage || (err instanceof Error ? err.message : 'Something went wrong.')
  form.value.turnstileToken = ''  // force fresh token on retry
}
```

`$fetch` rejects with `FetchError` for non-2xx; the original
`statusMessage` lives on `err.data.statusMessage`.

---

## Anti-patterns

- ❌ Validating only on the client — server schema is the source of
  truth, client schema is UX sugar
- ❌ Separate `clientSchema.ts` and `serverSchema.ts` — single file in
  `shared/utils/` ensures they don't drift
- ❌ Storing Turnstile site key in `runtimeConfig.public` — it'll get
  wiped on Cloudflare deploy (see nuxt-config/cloudflare-deployment.md)
- ❌ Reusing a Turnstile token after submission failure — tokens are
  single-use; always reset the widget on error
- ❌ Failing the request when notification email fails — the
  application is saved; email is a separate concern

## Related

- **[nuxt-composables](../../nuxt-composables/references/multi-step-state.md)** —
  `useApplyForm` composable shape
- **[nuxt-config](../../nuxt-config/references/cloudflare-deployment.md)** —
  Turnstile siteKey deploy behaviour
- **[forms.md](./forms.md)** — Nuxt UI form / validation basics
