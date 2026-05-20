# NuxtHub Integration

NuxtHub (`@nuxthub/core`) wires D1 + Drizzle into the Nuxt build, exposes `db` as an auto-import, and generates the `.output/server/wrangler.json` that Cloudflare Workers needs.

## Configuration

```typescript
// nuxt.config.ts
export default defineNuxtConfig({
  modules: ['@nuxthub/core'],

  hub: {
    db: {
      dialect: 'sqlite',
      connection: {
        databaseId: '<your-d1-database-uuid>'
      }
    }
  }
})
```

The `databaseId` is the UUID Cloudflare assigns to your D1 database. Find it via `wrangler d1 list` or the Cloudflare dashboard.

## Auto-imports

NuxtHub generates `.nuxt/types/nitro-imports.d.ts` with:

```typescript
export { db, schema } from '@nuxthub/db'
```

Inside any `server/` file, both `db` and `schema` are available without import:

```typescript
// server/api/submissions.get.ts
import { submissions } from '../db/schema'
import { eq } from 'drizzle-orm'

export default defineEventHandler(async () => {
  return db.select().from(submissions).where(eq(submissions.status, 'new'))
})
```

Notice the schema *table* (`submissions`) is imported by name; `db` itself is not. That's intentional — the table objects come from your file, the handle is framework-provided.

You can also access via `schema.submissions` if you don't want the named import:

```typescript
return db.select().from(schema.submissions)
```

Both work; named imports are tighter.

## Migration generation

NuxtHub wraps `drizzle-kit generate` to emit migrations based on the diff between your schema and the existing migrations:

```bash
npx nuxt-hub db generate
```

Output:

```
server/db/migrations/sqlite/
├── 0000_freezing_morg.sql
├── 0001_condemned_longshot.sql
└── meta/
    ├── 0000_snapshot.json
    ├── 0001_snapshot.json
    └── _journal.json
```

- **`*.sql` files** — the migration text, applied in numeric order
- **`meta/*_snapshot.json`** — the schema state at each migration; used for diffing
- **`meta/_journal.json`** — the ordered list of migrations applied

**Commit all of `server/db/migrations/sqlite/`** — `_journal.json` especially. Without it the next `db generate` can't compute the diff.

### Naming

Each migration gets an auto-generated name (`freezing_morg`). It's fine to leave as-is. If you want descriptive names, rename the `.sql` and the snapshot stem and update `_journal.json` — but it's almost never worth the churn.

## Applying migrations

This is the part everyone gets wrong. **There is no auto-apply.**

| Path | Applies migrations? |
| --- | --- |
| `nuxt build` | No |
| `wrangler deploy` | No |
| Cloudflare Workers Builds | No |
| `npx nuxt-hub db migrate` | Only against the **local** `.data/db/sqlite.db` |
| `npm run db:migrate:remote` (custom) | Yes, against remote D1 |

### Local

Local migrations run automatically on `nuxt dev` against `.data/db/sqlite.db` (a SQLite file checked into `.gitignore`). To force a re-apply locally:

```bash
rm .data/db/sqlite.db   # nuke local DB
npm run dev             # next dev start replays all migrations
```

`npx nuxt-hub db migrate` is also available but in practice `nuxt dev` does the job.

### Remote (production D1)

Add this script to `package.json`:

```jsonc
"scripts": {
  "db:migrate:remote": "NITRO_PRESET=cloudflare-module nuxt build && npx wrangler d1 migrations apply DB --remote --config .output/server/wrangler.json"
}
```

Run it from a local checkout (it needs `wrangler login` once):

```bash
npm run db:migrate:remote
```

### Why this script is structured this way

- **`NITRO_PRESET=cloudflare-module`** — forces the Cloudflare preset locally. A plain `nuxt build` defaults to `node-server` and won't emit `.output/server/wrangler.json`, so wrangler has nothing to read.
- **`--remote`** — apply against the deployed D1, not the local dev DB.
- **`--config .output/server/wrangler.json`** — the generated wrangler.json declares `migrations_table: _hub_migrations` and `migrations_dir: db/migrations/sqlite/`, so wrangler uses NuxtHub's tracking table and reads from the right path. Without `--config`, wrangler uses its default `d1_migrations` table and looks under `migrations/` — silent failure.

### The `_hub_migrations` tracking table

NuxtHub uses its own table to track which migrations have been applied. It's an implementation detail you shouldn't need to touch, but if a migration apply seems to do nothing, this is where to look:

```bash
npx wrangler d1 execute DB --remote --command "SELECT * FROM _hub_migrations"
```

If a migration is listed there, NuxtHub considers it applied. If you need to force a re-apply, delete the row (carefully) and re-run the migration script.

## Inspecting remote D1

```bash
# Row count
npx wrangler d1 execute DB --remote --command "SELECT COUNT(*) FROM submissions"

# Recent rows
npx wrangler d1 execute DB --remote --command "SELECT id, name, email, created_at FROM submissions ORDER BY created_at DESC LIMIT 10"

# Schema introspection
npx wrangler d1 execute DB --remote --command "SELECT sql FROM sqlite_master WHERE type='table'"
```

Don't manually `INSERT`/`UPDATE` via wrangler unless debugging. Drizzle's schema is the source of truth; ad-hoc SQL writes accumulate state that nobody understands later.

## NuxtHub features that matter

- **`hubDatabase()`** — lower-level handle if you want raw D1 access without Drizzle. Don't bother unless you have a specific reason; `db` is better.
- **`hubKV()` / `hubBlob()`** — KV and R2 bindings, configured under `hub: { kv: true, blob: true }`. Same auto-import pattern.
- **NuxtHub Admin** — `nuxt-hub admin` opens a dashboard for browsing D1 / KV / blobs locally. Useful for debugging.

## Anti-patterns

- ❌ Adding `wrangler deploy && wrangler d1 migrations apply` to your CI workflow — CI doesn't have your wrangler auth, and remote migrations need to be a deliberate manual step
- ❌ Committing `.data/db/sqlite.db` — local-only state
- ❌ Editing a `.sql` migration file after it's been applied anywhere — generate a new migration instead
- ❌ Dropping `meta/_journal.json` — breaks future migration generation
- ❌ Setting `databaseId` to the wrong UUID and pushing — silent prod data divergence

## Related

- **[drizzle-d1.md](./drizzle-d1.md)** — schema definition and query patterns
- **[server-types.md](./server-types.md)** — sharing Drizzle row types with client code
- **[nuxt-config/cloudflare-deployment.md](../../nuxt-config/references/cloudflare-deployment.md)** — full Cloudflare Workers deploy context
- **[nuxt-testing/test-seams.md](../../nuxt-testing/references/test-seams.md)** — DB isolation in tests
