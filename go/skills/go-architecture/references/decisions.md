# Decisions

**Reach for the least structure that holds the rule: a function, then a struct, then a package.**

## Need to reach for

| Need | Reach for | Not when | More |
|---|---|---|---|
| A computation with no collaborators | a function in the package that owns the data | — | [go-actions](../../go-actions/SKILL.md) |
| An operation that needs a store, a clock or a provider | a struct whose fields are those collaborators, with one exported verb method taking `ctx` first | it needs none: a function | [go-actions](../../go-actions/SKILL.md) |
| A value with a meaning: an id, money, a date | a named type (`order.ID`, `type Cents int64`) | — | [go-types](../../go-types/SKILL.md) |
| A value with rules: a positive amount, a valid email | a struct with unexported fields, built by `NewX` or `ParseX` | — | [go-types](../../go-types/SKILL.md) |
| A fixed set of states | a defined type with constants, `String` and `ParseX` | — | [go-types](../../go-types/SKILL.md) |
| Swapping an implementation, or faking one in a test | an interface its consumer declares, of one to three methods | one implementation and no fake | [go-interfaces](../../go-interfaces/SKILL.md) |
| A meaning two callers need | one function in its owner, called by both | — | [ownership.md](ownership.md) |
| How a value reads to people | a function in `format` | it's stored or sent: its own function | [ownership.md](ownership.md) |
| A job several packages need | a package named for that job | one package needs it: an unexported function there | [layout.md](layout.md) |
| Work that runs until shutdown | a type with `Run(ctx)` | the caller can loop: stay synchronous | [go-concurrency](../../go-concurrency/SKILL.md) |
| A command, handler or worker | a thin edge: parse, call one operation, write the result or map the error | — | [go-actions](../../go-actions/SKILL.md) |
| Config, flags, the environment | read in `run`, passed down as a struct | — | [layout.md](layout.md) |
| A rule that must always hold | a gate: a linter setting or a source guard | — | [go-quality](../../go-quality/SKILL.md) |

## Signs of the wrong choice

- **A struct with methods and no fields** → functions.
- **An interface with one implementation and no fake** → the concrete type.
- **A package doc that needs "and"** → two packages.
- **Two packages every caller imports together** → one package.
- **A `bool` parameter that switches what a function does** → two functions, or a named mode.
- **A constructor taking six arguments** → a `Config` struct whose zero fields mean the defaults.
- **A function named for its mechanics** (`processData`, `handleOrder`) → named for what it gives back or does: `order.Cancel`, `format.Percent`.
- **The same three lines in two packages** → one owner, before a third copy appears.
