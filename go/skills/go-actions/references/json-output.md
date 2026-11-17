# JSON Output Contract

**With `--json`, every run writes one JSON document, its result or its failure, through one renderer; the output models are an API, and a test pins their bytes.**

Contents: one renderer · one document per run · the models are the contract · pinning the text · checklist.

## One renderer

A script reading `shop cancel ord-1 --json` parses stdout. One line printed past the renderer, a usage line or a library's own `Error: …`, and the parse fails, so the script can't even read why.

- **One renderer owns every error, in every mode.** The command returns its error; the renderer writes it, as text to stderr or as the document on stdout. Nothing else prints one: the command parser's own error and usage printing is off (cobra's `SilenceErrors` and `SilenceUsage`).
- **Parse errors included.** An unknown flag fails before the flags are read, so the edge scans the arguments for `--json` first, up to `--`, and renders the parse failure in the mode they ask for.
- **The mode is chosen once,** from the parsed flags, before the renderer is built. Each mode renders the same model: JSON encodes it, text lays it out.

## One document per run

```json
{"order":"ord-1","status":"cancelled","refunded":true,"at":"2026-03-01T09:00:00Z"}
```

```json
{"error":{"kind":"usage","message":"unknown flag --forse: see shop help cancel"}}
```

- **A run shows at most one result and fails at most once.** The renderer writes the document when the run ends: the failure if there was one, else the result, else `{}`.
- **What the run says along the way rides in the same document** as members of its own (`warnings`, `steps`), never as lines before it.
- **The error is an object, not a string:** `kind` (the class a script branches on, the same as the exit code's), `message`, and any `fix` or `link` the error carries.
- **A list is never `null`:** `encoding/json/v2` encodes a nil slice as `[]`; on v1, the renderer makes each empty before encoding ([go-types' boundaries.md](../../go-types/references/boundaries.md)).

## The models are the contract

```go
// CancelResult is what shop cancel shows. Its JSON members are read by scripts:
// renaming one is a breaking change.
type CancelResult struct {
    Order    order.ID     `json:"order"`
    Status   order.Status `json:"status"`
    Refunded bool         `json:"refunded"`
    At       time.Time    `json:"at"`
}
```

- **Exported, in the renderer's package,** one per result, each read by every mode. The domain's own types carry no tags: the edge maps them in.
- **Every field tagged, snake_case.** The tag is the API: renaming a Go field must not rename a member, and renaming a member is a breaking change, said as one in the release notes.
- **Typed string fields are fine here.** `order.Status` encodes to the same bytes as a `string`, and exhaustive checks every switch the text mode makes on it ([go-types](../../go-types/SKILL.md)).
- **A model encodes as an object,** so a member can be added later without breaking a reader.
- **Times in UTC, to a fixed precision.** The text mode words them in the person's zone; the document holds the instant.

## Pinning the text

An error's message shown to a person, or read by an agent from `message`, is the program's output. Its test pins the bytes, as a golden file pins any output you own ([go-errors' errors-as-output.md](../../go-errors/references/errors-as-output.md)):

```go
func TestCancelJSONRefusesAShippedOrder(t *testing.T) {
    code, stdout, stderr := execute(t.Context(), t, "cancel", "ord-2", "--json")

    want := `{"error":{"kind":"failed","message":"order ord-2 is shipped, so it can't be cancelled"}}` + "\n"
    if code != 1 || stdout != want || stderr != "" {
        t.Errorf("shop cancel ord-2 --json = %d, stdout %q, stderr %q; want 1, %q and nothing", code, stdout, stderr, want)
    }
}
```

The logic still matches the error by `errors.Is` or `errors.AsType`; the pinned text is the output's test, not how anything decides.

## Checklist

- [ ] One renderer writes every error in every mode; the parser prints nothing of its own.
- [ ] `--json` read from the arguments before the parse, so a parse failure is a document too.
- [ ] One document per run: the result, or the failure, with warnings as a member.
- [ ] Output models exported and tagged; a renamed member is a breaking change.
- [ ] No list encodes as `null`.
- [ ] Every user-facing message pinned exactly by a test.
