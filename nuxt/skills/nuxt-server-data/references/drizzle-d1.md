# Drizzle ORM on D1

Drizzle is a TypeScript-first SQL toolkit. With NuxtHub + D1 you get:

- Schema-in-TypeScript that generates SQL migrations
- Type-safe queries (no string SQL except for the rare `sql\`\``)
- The `db` query handle auto-imported into all server code

This file covers schema definition, query patterns, and Drizzle idioms that matter for D1 specifically.

## Schema definition

Place schemas in `server/db/schema.ts`. NuxtHub picks them up via the `@nuxthub/db` re-export.

```typescript
// server/db/schema.ts
import { sql } from 'drizzle-orm'
import { sqliteTable, text, integer } from 'drizzle-orm/sqlite-core'

export const applications = sqliteTable('applications', {
  id:        text('id').primaryKey(),
  createdAt: text('created_at').notNull().default(sql`(datetime('now'))`),

  // Foreign keys — `int` references the type in TypeScript, the SQL is
  // generated for you (`REFERENCES users(id) ON DELETE CASCADE`)
  userId:    integer('user_id').references(() => users.id, { onDelete: 'cascade' }),

  // JSON columns — D1 / SQLite store as text, Drizzle handles parse/stringify
  socials:   text('socials', { mode: 'json' }).$type<Record<string, string>>(),
  services:  text('services', { mode: 'json' }).$type<string[]>(),

  // Constraints
  status:    text('status').notNull().default('new'),
  email:     text('email').notNull(),

  // Numbers
  fitCount:  integer('fit_count')
})
```

### Conventions

- **Table names**: `snake_case` plural (`applications`, `team_members`)
- **Column names**: `snake_case` in SQL, `camelCase` in TypeScript — Drizzle handles the mapping via the first argument to the column builder
- **`id` always primary key** — choose `text('id').primaryKey()` for UUIDs or `integer('id').primaryKey({ autoIncrement: true })` for sequential
- **Timestamps as text via `datetime('now')`** — SQLite has no native datetime; `text` + `datetime('now')` is the idiomatic SQLite pattern and works with Drizzle's `sql\`(datetime('now'))\`` default

### JSON columns

The killer feature for keeping a schema sane:

```typescript
socials: text('socials', { mode: 'json' }).$type<Record<string, string>>()
services: text('services', { mode: 'json' }).$type<string[]>()
```

`mode: 'json'` makes Drizzle stringify on write and parse on read. `$type<…>()` adds the TypeScript type. On disk it's a `TEXT` column with JSON contents.

Use for: small structured data (≤10 KB), denormalised arrays/maps, anything you'd otherwise model as a join table for the sake of "purity" but never actually query into.

**Don't** use JSON columns for: data you need to filter or sort by (use a normal column), data > 1 MB (D1 has row size limits).

### Defaults

| Pattern | Use |
| --- | --- |
| `.default('new')` | Static scalar default |
| `.default(sql\`(datetime('now'))\`)` | Computed at insert time (timestamps) |
| `.$defaultFn(() => crypto.randomUUID())` | Computed in JavaScript at insert time |

`$defaultFn` runs in Drizzle, not SQL. Use it for IDs/UUIDs where the function isn't representable in SQL.

## Querying

`db` is auto-imported. The named tables are imported from `../db/schema`.

```typescript
import { applications } from '../db/schema'
import { and, eq, gt, sql } from 'drizzle-orm'

// SELECT
const rows = await db.select().from(applications).where(eq(applications.email, 'a@b.com'))

// SELECT specific columns
const rows = await db
  .select({ id: applications.id, name: applications.name })
  .from(applications)

// INSERT
await db.insert(applications).values({
  id: crypto.randomUUID(),
  name: data.name,
  email: data.email,
  services: data.services,    // JSON column — array passes through
  fitCount: data.fitCount
})

// UPDATE
await db.update(applications)
  .set({ status: 'reviewed' })
  .where(eq(applications.id, id))

// DELETE
await db.delete(applications).where(eq(applications.id, id))

// COUNT (helper on the db handle, returns a number)
const count = await db.$count(applications, eq(applications.status, 'new'))
```

### Operators

```typescript
import { eq, ne, gt, gte, lt, lte, and, or, not, inArray, like, ilike, isNull, isNotNull, between, sql } from 'drizzle-orm'

// Compound predicates
where(and(
  eq(applications.status, 'new'),
  gt(applications.createdAt, sql`datetime('now', '-1 hour')`)
))

// IN
where(inArray(applications.status, ['new', 'reviewed']))

// LIKE
where(like(applications.email, '%@example.com'))
```

Always use the operator helpers, not template-string SQL. They're typed against the column types — passing a string to `gt(applications.createdAt, ...)` errors at compile time if the column is an integer.

### Raw SQL — when justified

`sql` template tag is the escape hatch:

```typescript
// Computed expressions that operators don't cover
gt(applications.createdAt, sql`datetime('now', '-1 hour')`)

// Aggregate counts
const [row] = await db.select({ count: sql<number>`count(*)` }).from(applications)
```

Anything more complex than this — joins with conditional logic, recursive CTEs — keep weighing whether a raw `sql\`\`` block is clearer or whether two simpler queries + JavaScript do the job. D1 is fast for simple queries; complex ones are where you get bitten by edge runtime limits.

### Joins

```typescript
const result = await db
  .select({
    application: applications,
    user: users
  })
  .from(applications)
  .innerJoin(users, eq(applications.userId, users.id))
  .where(eq(applications.status, 'new'))
```

Each row of the result has the shape `{ application: <ApplicationRow>, user: <UserRow> }` — Drizzle namespaces by table to avoid column name collisions.

## Inferred row types

Drizzle generates a TypeScript type from the schema. Use it for function signatures so server utilities stay in sync:

```typescript
// server/db/schema.ts
import type { InferSelectModel, InferInsertModel } from 'drizzle-orm'

export type Application       = InferSelectModel<typeof applications>
export type NewApplication    = InferInsertModel<typeof applications>
```

```typescript
// server/utils/email.ts
import type { Application } from '../db/schema'

export async function sendFounderNotification(app: Application): Promise<void> {
  // app.id, app.name, etc. all typed from the schema
}
```

`InferSelectModel` is the row as you'd read it (all columns present, defaults filled). `InferInsertModel` is the row as you'd write it (optional fields are optional, defaults are optional, generated columns are absent).

## D1-specific concerns

- **No transactions across requests.** D1 supports transactions within a single query batch (`db.batch([...])`); don't try to span multiple HTTP requests.
- **Row size**: ~1 MB. Don't store images or large JSON blobs.
- **No `FOR UPDATE` / row locking.** D1 is eventual-consistency under the hood; use IDs and last-write-wins for concurrent updates.
- **No stored procedures.** Logic stays in TypeScript.
- **`now()` doesn't exist in SQLite** — use `datetime('now')` (UTC) or `strftime('%s', 'now')` for unix timestamps.

## Anti-patterns

- ❌ String SQL for normal queries — `db.run("SELECT * FROM applications WHERE id = '" + id + "'")` is both unsafe and untyped
- ❌ Reaching into raw `db.session` / `db.dialect` — Drizzle's surface is the query builder; everything else is internals
- ❌ Defining multiple `sqliteTable` calls for the same table name in different files — must be unique per schema export
- ❌ Storing booleans as `text('active')` with `'true'/'false'` strings — use `integer('active', { mode: 'boolean' })` instead