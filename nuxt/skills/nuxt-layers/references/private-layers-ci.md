# Private Layers in CI

Consuming a Nuxt layer hosted in a private GitHub repo, with build-time
resolution that differs from local development.

---

## The shape

```typescript
// nuxt.config.ts
const layers = {
  nuxtUi: process?.env?.LAYER_NUXT_UI || '../../nuxt-layers/nuxt-ui'
}

export default defineNuxtConfig({
  extends: [
    [layers.nuxtUi, { install: true }]
  ]
})
```

- Local dev: `LAYER_NUXT_UI` is unset → falls back to the sibling path
  (`../../nuxt-layers/nuxt-ui`). Fast iteration, no auth needed.
- CI / Cloudflare Builds: `LAYER_NUXT_UI` is set to a `github:` URL →
  `giget` fetches the tarball at `nuxt prepare` time.

Use one env var per layer when you extend more than one. Don't try to
encode multiple layers in a single var.

---

## `{ install: true }` — required for remote layers

The tuple form is mandatory when extending from a remote URL. Without
`{ install: true }`, the build host fetches the layer source but never
installs the layer's own `package.json` deps — leading to opaque "module
not found" failures during the build.

```typescript
extends: [
  [layers.nuxtUi, { install: true }]   // ✔ remote-safe
]

extends: [
  layers.nuxtUi                         // ✘ breaks when resolved from GitHub
]
```

For purely local paths the bare-string form works too, but the tuple form is
harmless — always use it if the same config might run in CI.

---

## `GIGET_AUTH` — scope, placement, failure modes

`giget` reads `GIGET_AUTH` as a bearer token when fetching private GitHub
tarballs. The token must be a **fine-grained PAT** with:

- **Repository access**: only the layer repo (e.g. `org/nuxt-layers`)
- **Permissions**: `Contents: Read` (nothing else)
- **No** workflow, packages, or admin scopes

### Build-time vs runtime placement

On Cloudflare Workers Builds (and most CI providers), `GIGET_AUTH` MUST be
set as a **build-time** variable. `nuxt prepare` runs during the build, not
in the deployed Worker. If you place it at runtime scope:

```
ERROR  Failed to download `github:org/nuxt-layers/nuxt-ui#main`: 404
```

The 404 is misleading — the URL is fine, the token is missing. Move it to
the build scope and re-run.

### Token rotation

Fine-grained PATs expire (default 90 days). When a previously-green build
suddenly 404s for the layer, check token expiry before chasing anything else.

---

## When to use which layer source

| Source | Use case | Example |
| --- | --- | --- |
| Relative path | Active layer development, both repos checked out side-by-side | `../../nuxt-layers/base` |
| `github:org/repo/path#ref` | Production CI, layer published as a private repo | `github:org/nuxt-layers/nuxt-ui#main` |
| `npm:@org/layer-name` | Layer published as a private npm package | requires `.npmrc` auth in CI |

Choose by who maintains the layer:

- **You + active edits** → relative path (with env-var fallback for CI)
- **Stable + read-only consumer** → pinned `github:` ref or npm version

Don't mix: pick one resolution strategy per layer per environment.

---

## Per-layer dependency installation

When `{ install: true }` is on, Nuxt runs the layer's own install during
`nuxt prepare`. The layer's `package.json` `dependencies` (not
`devDependencies`) are installed into the build host's `node_modules`.

Implications:

- The layer's runtime deps must be listed under `dependencies`. Anything in
  `devDependencies` is skipped at the consumer site.
- Conflicting versions between layer and consumer will surface as resolution
  warnings — keep the layer's dep ranges loose (`^x.y.z`) so the consumer's
  pinned versions win.
- Don't list the layer itself as a `dependency` in the consumer's
  `package.json` — extends handles it.

---

## Local-first development loop

For layer work:

1. Clone the layer repo as a sibling: `/Users/you/Code/nuxt-layers/`
2. Leave `LAYER_NUXT_UI` unset locally so the relative path resolves
3. Edit layer + consumer in parallel; the Nuxt dev server picks up layer
   changes via the relative path
4. Push the layer; CI uses the `github:` URL via the build-time env var

If you want to test the CI resolution path locally:

```bash
LAYER_NUXT_UI='github:org/nuxt-layers/nuxt-ui#main' \
  GIGET_AUTH=<your-pat> \
  nuxt build
```

---

## Importing from layers via `#layers/`

The `#layers/<name>/` alias resolves regardless of source. Use it inside
the consumer app:

```typescript
import Model from '#layers/base/app/models/Model'
import type { Castable } from '#layers/base/app/types'
```

The `<name>` is the layer directory name (or the package name's last
segment for npm-published layers). Auto-imports (composables, utils,
components) work without the alias.

---

## Failure modes cheatsheet

| Symptom | Cause |
| --- | --- |
| `Failed to download tarball: 404` | `GIGET_AUTH` missing or wrong scope |
| `Cannot find module '...'` in build | Forgot `{ install: true }` on remote extend |
| Build green locally, fails in CI with mysterious resolution | `LAYER_NUXT_UI` not set in CI env |
| Layer dep not found at runtime | Layer's dep is in `devDependencies` instead of `dependencies` |
| Local edits to layer don't reflect | `LAYER_NUXT_UI` set locally, overriding the relative path |

---

## Related

- **[nuxt-config](../../nuxt-config/references/cloudflare-deployment.md)** —
  full Cloudflare Workers Builds deployment context
- **[layers.md](./layers.md)** — layer architecture overview
