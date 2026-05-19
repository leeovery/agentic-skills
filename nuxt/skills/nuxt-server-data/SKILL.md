---
name: nuxt-server-data
description: Server-side data layer with Drizzle ORM + Cloudflare D1 + NuxtHub. Use when designing schema, generating and applying migrations, querying from Nitro routes, sharing types between client and server, or testing DB writes.
---

# Nuxt Server Data

The server-side persistence layer for a Nuxt + NuxtHub + Cloudflare app. Drizzle ORM models the schema in TypeScript, NuxtHub auto-exposes a `db` handle, D1 is the runtime database.

**This is the server-side counterpart to `nuxt-repositories`** — repositories are the client-side API access layer; this skill covers what's behind the API.

## When to use

- Designing a Drizzle schema for D1 / SQLite
- Generating and applying migrations (local + remote)
- Querying inside Nitro server routes (`server/api/*.post.ts`)
- Sharing types between client and server via `shared/types/`
- Avoiding the wrangler-deploy migration gotcha (migrations do NOT auto-apply)
- Testing DB writes with isolation seams

## Reference files

- **[drizzle-d1.md](references/drizzle-d1.md)** — schema definition (`sqliteTable`, json columns, `notNull`, defaults), querying patterns (`select`, `insert`, `count`, joins), Drizzle conventions
- **[nuxt-hub.md](references/nuxt-hub.md)** — `hub.db` config, the auto-imported `db` and `schema` exports, migration generation (`nuxt-hub db generate`), the `_hub_migrations` table, local vs remote migration apply, the build-then-wrangler script pattern
- **[server-types.md](references/server-types.md)** — `shared/utils/` and `shared/types/` for cross-side type reuse, Drizzle's inferred row types, request/response shape sharing with Zod

## Stack at a glance

```
Vue component
   │  $fetch('/api/contact', ...)
   ▼
server/api/contact.post.ts        ← Nitro event handler
   │  validates via shared Zod schema
   ▼
db.insert(submissions).values(...)
   │  auto-imported `db` from NuxtHub
   ▼
Cloudflare D1                    ← SQLite at the edge
```

## Quick example

```typescript
// server/db/schema.ts
import { sql } from 'drizzle-orm'
import { sqliteTable, text, integer } from 'drizzle-orm/sqlite-core'

export const submissions = sqliteTable('submissions', {
  id:          text('id').primaryKey(),
  createdAt:   text('created_at').notNull().default(sql`(datetime('now'))`),
  name:        text('name').notNull(),
  email:       text('email').notNull(),
  preferences: text('preferences', { mode: 'json' }).$type<string[]>(),
  status:      text('status').notNull().default('new')
})
```

```typescript
// server/api/contact.post.ts
import { and, eq, gt, sql } from 'drizzle-orm'
import { submissions } from '../db/schema'

export default defineEventHandler(async (event) => {
  const data = await readValidatedBody(event, contactPayloadSchema.parse)

  await db.insert(submissions).values({
    id: crypto.randomUUID(),
    name: data.name,
    email: data.email,
    preferences: data.preferences
  })

  return { ok: true }
})
```

Notice: `db` is **auto-imported** by NuxtHub — no `import { db } from ...` line. Schema is imported by name (`submissions`) from `../db/schema`.

## Critical: migrations don't auto-apply

Neither `nuxt build`, `wrangler deploy`, nor Cloudflare Workers Builds run migrations. You apply them manually from a local checkout:

```jsonc
// package.json
"scripts": {
  "db:migrate:remote": "NITRO_PRESET=cloudflare-module nuxt build && npx wrangler d1 migrations apply DB --remote --config .output/server/wrangler.json"
}
```

Detail in [nuxt-hub.md](references/nuxt-hub.md). This is the single biggest footgun in the stack — if you're seeing "no such table" on prod after a deploy, you forgot this step.

## Anti-patterns

- ❌ Querying from `shared/utils/` — that code runs in the client bundle too. Server queries go in `server/` only.
- ❌ Importing `db` explicitly — it's auto-imported by NuxtHub; an explicit import path is a sign you're working against the framework.
- ❌ Putting raw SQL strings inline for filtering — use Drizzle's `eq`/`and`/`gt` operators; they're typed against your schema.
- ❌ Running migrations against `--remote` from a CI environment — CI doesn't have your wrangler auth and the build script in `db:migrate:remote` does. Run it locally.
- ❌ Reaching for `vi.mock('~/server/db')` in tests — see `nuxt-testing/test-seams.md` for the env-guarded seam pattern.

## Related

- **[nuxt-config](../nuxt-config/references/cloudflare-deployment.md)** — Cloudflare Workers deployment, `wrangler.json` generation, secrets vs vars
- **[nuxt-forms](../nuxt-forms/references/marketing-forms.md)** — the `contact.post.ts` route that consumes the `submissions` table
- **[nuxt-testing](../nuxt-testing/references/test-seams.md)** — DB test isolation seam (`/api/_dev/db`)
- **[nuxt-repositories](../nuxt-repositories/SKILL.md)** — client-side API access (the layer above this one)
