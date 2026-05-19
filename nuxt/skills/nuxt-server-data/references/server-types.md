# Sharing Types: Client ↔ Server

Nuxt auto-imports from three directories:

- `app/` → client only
- `server/` → server only
- `shared/` → both

Use `shared/` for anything that must be identical on both sides. Used wrong, it's where bugs accumulate; used right, it's how you keep client and server schemas in sync without manual duplication.

## What goes in `shared/`

| Folder | Use for |
| --- | --- |
| `shared/types/` | Pure TypeScript interfaces, request/response shapes |
| `shared/utils/` | Pure functions, Zod schemas, validators, formatters |

What does NOT go in `shared/`:

- Anything that imports from `app/` (e.g. Vue composables — those are client-only)
- Anything that imports from `server/` (DB queries, Nitro utilities)
- Anything with side effects (logging, telemetry)
- Drizzle table definitions — the schema is server-only

## Pattern: Zod schema as the single source of truth

```typescript
// shared/utils/contact-schema.ts
import { z } from 'zod'

export const contactFormSchema = z.object({
  name:        z.string().trim().min(1).max(200),
  email:       z.string().trim().toLowerCase().email(),
  company:     z.string().trim().min(1).max(200),
  preferences: z.array(z.string().max(100)).min(1).max(10)
})

export const contactPayloadSchema = contactFormSchema.extend({
  turnstileToken: z.string().min(1),
  website:        z.string().max(200).optional().default('')   // honeypot
})

export type ContactForm    = z.infer<typeof contactFormSchema>
export type ContactPayload = z.infer<typeof contactPayloadSchema>
```

Used on the **client** (via `app/composables/useContactForm.ts`):

```typescript
import { contactPayloadSchema, type ContactForm } from '~~/shared/utils/contact-schema'

const form = useState<ContactForm>('contact-form', emptyForm)

const parsed = contactPayloadSchema.safeParse({ ...form.value, turnstileToken: token })
if (!parsed.success) { /* surface error */ }
```

Used on the **server** (`server/api/contact.post.ts`):

```typescript
import { contactPayloadSchema } from '~~/shared/utils/contact-schema'

export default defineEventHandler(async (event) => {
  const data = await readValidatedBody(event, contactPayloadSchema.parse)
  // ↑ readValidatedBody is a Nitro helper that throws 400 on invalid input
})
```

One schema, two callers, no drift.

## Pattern: Drizzle row types → client-side type

Drizzle row types live in `server/`. They reference the `sqliteTable` definitions which import from `drizzle-orm/sqlite-core` — bundling those into the client bundle is wasteful and pulls in SQLite-specific code that has no business on the client.

Two options:

### Option A: re-export a plain-TS shape from `shared/types/`

```typescript
// shared/types/submission.ts — hand-written, mirrors the schema
export interface Submission {
  id: string
  createdAt: string
  name: string
  email: string
  company: string
  preferences: string[]
  status: 'new' | 'reviewed' | 'accepted' | 'rejected'
}
```

```typescript
// server/db/schema.ts — Drizzle-inferred type extends/equals the shared one
import type { InferSelectModel } from 'drizzle-orm'
import type { Submission as SharedSubmission } from '~~/shared/types/submission'

export type Submission = InferSelectModel<typeof submissions>

// Type-level check that the two stay in sync; fails compile if they diverge
const _check: SharedSubmission = {} as Submission
```

Pros: client bundle is tiny, types are explicit.
Cons: two declarations to maintain (hopefully a thin one).

### Option B: only share what the client actually needs (recommended)

The client usually doesn't need the full DB row — it needs a response shape that may be smaller (no PII, no internal-only fields, etc.):

```typescript
// shared/types/submission.ts
export interface SubmissionSummary {
  id: string
  status: 'new' | 'reviewed' | 'accepted' | 'rejected'
  submittedAt: string
}
```

```typescript
// server/api/submissions.get.ts
import type { SubmissionSummary } from '~~/shared/types/submission'

export default defineEventHandler(async (): Promise<SubmissionSummary[]> => {
  const rows = await db.select().from(submissions)
  return rows.map(r => ({
    id: r.id,
    status: r.status as SubmissionSummary['status'],
    submittedAt: r.createdAt
  }))
})
```

This is the idiomatic shape for a public API. The client knows about `ApplicationSummary`; the DB row stays server-side. Changes to internal columns don't ripple into the client bundle.

## `~~/` vs `~/` aliases

| Alias | Resolves to |
| --- | --- |
| `~/` or `@/` | `app/` (client root) |
| `~~/` or `@@/` | project root (where `nuxt.config.ts` lives) |

Use `~~/shared/utils/...` to import from shared code, not `~/`. The `~/shared/...` form only works because of fallback resolution and is fragile.

## `nuxt prepare` and import discovery

`shared/utils/` and `shared/types/` are auto-imported into both contexts. After adding a new file there, run `nuxt prepare` (it's also part of `postinstall`) to regenerate `.nuxt/types/` so TypeScript sees the imports without explicit `import` statements.

If you keep seeing "cannot find name `contactPayloadSchema`" in editor squigglies, that's usually the cause. Restart your TS server or re-run `npm run postinstall`.

## When to skip `shared/` and just duplicate

Two small interfaces that *happen* to look similar but represent different concepts shouldn't be unified just because the type signature matches. A `Coordinates` on the client (form input) and a `Coordinates` on the server (DB row) might be the same shape today and diverge tomorrow.

The rule: share when the **concept** is the same, not when the **shape** is the same.

## Anti-patterns

- ❌ Importing from `server/` into `shared/` — pulls server-only code into the client bundle
- ❌ Importing from `app/` into `shared/` — same problem, opposite direction
- ❌ Putting Vue composables in `shared/` because "they're TypeScript too" — they use Vue runtime; client only
- ❌ Sharing the full Drizzle row type to the client — leaks DB structure and bloats the bundle
- ❌ Duplicating a Zod schema in client and server "to be safe" — guaranteed to drift; share it

## Related

- **[drizzle-d1.md](./drizzle-d1.md)** — `InferSelectModel` / `InferInsertModel` for server-side types
- **[nuxt-forms/marketing-forms.md](../../nuxt-forms/references/marketing-forms.md)** — shared Zod schemas across the apply flow
- **[nuxt-architecture/marketing-site-shape.md](../../nuxt-architecture/references/marketing-site-shape.md)** — the `shared/` directory in context
