# Public Forms (no admin form library)

Forms on a public surface have a different shape to admin-app forms:

- Anonymous → spam defence matters
- No backing model/repository → no `useForm(url, …)`-style hook
- Server-side validation is the source of truth; client validation is UX sugar

This file collects the individual patterns. Each is independently useful — adopt the ones that fit.

---

## Share Zod schemas between client and server

Place the schema in `shared/utils/` so the client (Nuxt auto-imports) and the server (Nitro auto-imports the same directory) see one definition.

```typescript
// shared/utils/contact-schema.ts
import { z } from 'zod'

export const contactFormSchema = z.object({
  name:  z.string().trim().min(1).max(200),
  email: z.string().trim().toLowerCase().email().max(200)
})

// The server accepts the form schema + server-required fields
export const contactPayloadSchema = contactFormSchema.extend({
  turnstileToken: z.string().min(1, 'Human verification required'),
  website:        z.string().max(200).optional().default('')   // honeypot
})

export type ContactForm    = z.infer<typeof contactFormSchema>
export type ContactPayload = z.infer<typeof contactPayloadSchema>
```

- **`<form>Schema`** — canonical shape used by UForm's `:schema` and (often) by the composable holding the form state
- **`<form>PayloadSchema`** — what the server actually accepts; extends the form schema with bot-defence and any derived fields

### Slice for multi-step forms with `pick()`

Each step's `<UForm :schema="…">` should run only the validators for its own fields:

```typescript
export const step1Schema = contactFormSchema.pick({ name: true, email: true })
export const step2Schema = contactFormSchema.pick({ /* ... */ })
```

Otherwise the user sees "phone is required" while filling out the name step.

---

## Coerce input with `z.preprocess`

For fields that should accept loose input (URL without `https://`, names with extra whitespace), put the coercion in a `z.preprocess` so it runs before validation:

```typescript
function coerceUrl(v: unknown): unknown {
  if (typeof v !== 'string') return v
  const t = v.trim()
  if (!t) return ''
  return /^https?:\/\//i.test(t) ? t : `https://${t}`
}

const optionalUrl = z
  .preprocess(coerceUrl, z.string().url('Enter a valid URL').or(z.literal('')))
  .optional()
  .default('')
```

Keep the coercion as plain TypeScript (not a Zod transform) — it's testable and reusable across forms.

---

## Cloudflare Turnstile: invisible mode + token-await

Invisible mode means the widget executes async after mount. A fast user can click submit before the token arrives. Wait briefly, fail explicitly otherwise:

```vue
<NuxtTurnstile ref="turnstileRef" v-model="form.turnstileToken" />
```

```typescript
async function onSubmit() {
  if (!form.value.turnstileToken) {
    await new Promise<void>((resolve, reject) => {
      const stop = watch(() => form.value.turnstileToken, (t) => { if (t) { stop(); resolve() } })
      setTimeout(() => { stop(); reject(new Error('Verification took too long.')) }, 8000)
    })
  }
  await submit()
}

// on failure: reset the widget so the next attempt gets a fresh token
catch (err) {
  turnstileRef.value?.reset()
  form.value.turnstileToken = ''
}
```

Tokens are single-use. Always reset on submit error.

**Site key**: hardcode in `nuxt.config.ts > turnstile.siteKey`. It's public and domain-restricted by Cloudflare. **Secret key**: Worker `secret_text` (`NUXT_TURNSTILE_SECRET_KEY`). See `nuxt-config/cloudflare-deployment.md` for why public values can't live in `runtimeConfig.public` on Cloudflare.

---

## Honeypot field

A hidden input bots fill but humans never see. Server silently accepts honeypot submissions (don't signal rejection):

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

Pick a field name bots find tempting (`website`, `url`, `email_alt`). Don't name it `honeypot`. Combine offscreen positioning + `tabindex="-1"` + `autocomplete="off"` + `aria-hidden` so it's invisible to humans, keyboards, and screen readers.

---

## The `<UButton type="submit">` failure mode

A `<UButton type="submit">` inside `<UForm>` can silently no-op — click registers, no event fires, no network. Workaround: call the form's exposed `submit()` via a template ref:

```vue
<script setup lang="ts">
import type { Form } from '@nuxt/ui'
const formRef = useTemplateRef<InstanceType<typeof Form>>('form')

async function onSubmitClick() {
  if (loading.value) return
  loading.value = true
  try { await formRef.value?.submit() } finally { loading.value = false }
}
</script>

<template>
  <UForm ref="form" :schema="schema" :state="form" @submit="onSubmit">
    <UButton :loading :disabled="loading" @click="onSubmitClick">Submit</UButton>
  </UForm>
</template>
```

`type="submit"` omitted from the button. `@submit` on `<UForm>` still fires. Caller owns `loading` — the button's `:disabled` blocks re-entry already.

---

## Single-/multi-select chip toggle

A common public-form input that doesn't fit `<USelectMenu>` or `<UCheckbox>`. Wrap `<UButton>` so it inherits Nuxt UI focus/sizing:

```vue
<!-- ChipGroup.vue -->
<script setup lang="ts">
const model = defineModel<string | string[]>()
const props = defineProps<{ options: string[]; multiple?: boolean }>()

function isSelected(o: string) {
  return props.multiple
    ? Array.isArray(model.value) && model.value.includes(o)
    : model.value === o
}

function toggle(o: string) {
  if (props.multiple) {
    const cur = Array.isArray(model.value) ? model.value : []
    model.value = cur.includes(o) ? cur.filter(x => x !== o) : [...cur, o]
  } else {
    model.value = o
  }
}
</script>

<template>
  <div class="flex flex-wrap gap-2">
    <UButton
      v-for="o in options" :key="o"
      :color="isSelected(o) ? 'primary' : 'neutral'"
      :variant="isSelected(o) ? 'solid' : 'outline'"
      :ui="{ base: 'rounded-full' }"
      @click="toggle(o)">{{ o }}</UButton>
  </div>
</template>
```

`defineModel<string | string[]>()` lets the same component handle single and multi via `multiple`. Type union matches the consumer's bind.

---

## Server route: order rejects from cheapest to most expensive

```typescript
// server/api/contact.post.ts
export default defineEventHandler(async (event) => {
  const raw = await readBody(event)

  // 1. Honeypot (cheapest)
  if (typeof raw?.website === 'string' && raw.website.trim() !== '') {
    return { ok: true, id: 'ignored' }
  }

  // 2. Schema validation
  const parsed = contactPayloadSchema.safeParse(raw)
  if (!parsed.success) {
    throw createError({ statusCode: 400, statusMessage: parsed.error.issues[0]?.message ?? 'Invalid' })
  }
  const data = parsed.data

  // 3. CAPTCHA (network call)
  const result = await verifyTurnstileToken(data.turnstileToken, event)
  if (!result.success) {
    throw createError({ statusCode: 400, statusMessage: 'Human verification failed.' })
  }

  // 4. Rate limit (DB read)
  const ip = getRequestIP(event, { xForwardedFor: true }) ?? ''
  // ... check recent rows per IP, throw 429 if over limit

  // 5. Persist
  const record = { id: crypto.randomUUID(), ip, ...data }
  await db.insert(submissions).values(record)

  // 6. Notify — don't fail the request if email fails
  await Promise.allSettled([sendNotification(record), sendConfirmation(record)])

  return { ok: true, id: record.id }
})
```

Two rules in that order:

- **Cheap rejects first.** Honeypot (no work) → schema (in-process) → CAPTCHA (network) → rate limit (DB read) → persist.
- **Notification failures must not fail the submission.** `Promise.allSettled` so a provider hiccup doesn't 500. The row is the source of truth; email is recoverable.

---

## Surface server errors via `statusMessage`

`$fetch` rejects with a `FetchError` carrying the server's `statusMessage` on `err.data.statusMessage`. Surface it:

```typescript
try {
  await $fetch('/api/contact', { method: 'POST', body: data })
} catch (err) {
  const statusMessage = (err as { data?: { statusMessage?: string } })?.data?.statusMessage
  errorMessage.value = statusMessage || (err instanceof Error ? err.message : 'Something went wrong.')
}
```

The server controls the message (`createError({ statusMessage: 'Too many submissions.' })`), the client just displays it.

---

## Anti-patterns

- ❌ Validating only on the client — server schema is the source of truth
- ❌ Separate `clientSchema.ts` and `serverSchema.ts` files — they'll drift
- ❌ Reusing a Turnstile token after submit failure — single-use, always reset on error
- ❌ Failing the request when a notification email fails — the submission is saved
- ❌ Storing the Turnstile site key in `runtimeConfig.public` on Cloudflare — wiped on deploy

## Related

- **[forms.md](./forms.md)** — admin form patterns (`XForm`, `useFormBuilder`) — different shape
- **[nuxt-composables/multi-step-state.md](../../nuxt-composables/references/multi-step-state.md)** — composable that holds form state across routes
- **[nuxt-config/cloudflare-deployment.md](../../nuxt-config/references/cloudflare-deployment.md)** — Turnstile siteKey + secret placement
