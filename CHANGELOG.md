# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.8] - 2026-11-20

✨ Added
- Guidance for ids that ignore case but are shown as written, such as repo names — use a struct that refuses `==` and compare and key it through methods.
- Guidance on writes that are idempotent in themselves, such as closing a pull request — they keep their deadline and may be retried like a read.
- Guidance that a leased write with an unknown outcome is never retried blind — read what's there, and the write's own value means it landed.
- Guidance on counting a test bubble's own goroutines in leak tests, so parallel tests don't skew the count.
- Guidance on `synctest` limits — channels made outside a bubble, a renderer's ticking, and fixtures dated far from the bubble's clock.
- `testguard.Machine()` gives tests that reach the machine on purpose the pre-isolation environment, and integration suites behind a build tag are built without the guard.
- Guidance for adding a default to a config field where 0 already meant something — give values below zero the old meaning, such as `Timeout: -1`.
- Guidance for changing a type across consumer interfaces, such as a `string` id becoming a typed id, in one change or step by step.
- Guidance that a typed id's zero means unknown, and a malformed id must never silently become one.
- The `flag-parameter` lint rule's blind spots are documented, such as a bool passed on to another function, so review knows to check them.

🔧 Changed
- The table-row source guard now accepts rows run through a package's own `t.Run` runner, and flags rows that run a binary, a CLI entry or a fake inline.
- Table rows may carry function fields only for the act under test, input builders, and builders of fakes, fixtures or wants, and the `thelper` exclusion rules are updated to match.
- Error wrapping now puts what was asked first and a class's sentinel last, such as `push origin: exit status 128: push not run`.
- Sentinel errors are exported only where a caller outside the package branches on them — others stay unexported, with an `export_test.go` alias for external tests.
- Tests may pin the whole message of an error that wraps nothing and has no type, since there is no identity to match.
- Adapter guidance renames its call kinds to bounded and unbounded, with keyed and self-idempotent writes counted as bounded.

## [0.2.7] - 2026-11-17

✨ Added
- go-concurrency guidance for adapters that mix reads and unkeyed writes through one function — each call names its kind, so a write never inherits a read's deadline, with a worked example.
- go-quality script that derives the packages a build-tag test run must cover, as an option instead of running `./...` under the tag.
- go-quality guidance for thelper misfires, with config exclusions for subtest bodies handed to your own runner and for a table row's `setup` taking `t`.
- go-types guidance of one type per vocabulary, with a constant-less type for fields that carry several vocabularies and are read back through their reader's own type.

🔧 Changed
- go-types enum constants now carry their type's full name (`RunStatusQueued`, `EventKindPlaced`), with the stutter accepted, and the examples are renamed to match.
- go-quality now requires build-tag test runs to cover `./...` or a list derived on every run, never a hand-kept one, and adds those runs to the CI script and the done checklist.
- go-quality now excludes findings that only one build-tag set has in the config by file and text instead of using `//nolint`, which is now the one sanctioned exception to "never loosen a gate".
- go-testing helper guidance now says subtest bodies passed to your own runner and a row's `setup` call no `t.Helper()`.
- go-actions guidance now says `kindOf` reads the interrupt before a refusal, matching the exit code.
- go-types now makes a CLI's `--json` output models an exception to unexported wire structs: exported, in the renderer's package.

## [0.2.6] - 2026-11-17

✨ Added
- Guide to `--json` output for command-line tools — one renderer for every error, one document per run, and pinned output models and error text.
- Guide to writes across systems with no shared transaction — plan and check everything first, lease each write, never retry what may have run, and say exactly what landed.
- Guide to tools that drive a terminal — stream detection in one file, a read that can't be cancelled, redraws under one lock, and restoring the terminal on signals.
- Guide to tools that run other binaries — one runner, an adapter per binary that reads its stderr into errors, a fake runner and an opt-in integration suite.
- Guide to error messages shown verbatim in a CLI — prose messages, a separate fix line, warnings in the result, and tests that pin the text.
- Guide to driver contracts for config-chosen drivers, including an outside service whose types are the domain's own words.
- Pattern for a lock that waiters can give up on when their context ends.
- Pattern for enums over a vocabulary an outside system owns — unknown values are not refused, and each method states its fallback.
- Testing guidance for what a `synctest` bubble can't drive (subprocesses, terminals, real I/O) and for programs that need a clock of their own.
- Two new source guards — which packages each build-tag set links, and that starting the binary runs nothing.
- Advice to run `go fix ./...` in its own commit before adopting the lint config on an existing module.

🔧 Changed
- The cobra CLI guide now routes every error through one renderer and turns off cobra's own error and usage printing.
- Exit codes 3 to 125 are open to a tool's own outcomes, each documented in one table.
- The cobra guide is now framed as optional, with the commands rules stated parser-neutrally.
- Words that need reads, such as a name that may be a branch or a build, are passed to the operation as typed instead of being resolved at the command.
- Architecture rules allow domain packages to import `format`.
- Mode-dependent wording, such as time zones, now belongs to the renderer.
- Architecture rules allow a guarded process-wide resource and an adapter that lends its types to the domain.
- Quality gates now run under each build-tag set a module ships.
- The lint config drops `unusedmethod`, adds `exhaustive` ignore-types, skips `forcetypeassert` in tests and widens the stream guidance in the forbidigo message to `*os.File`.
- Table-test rules now let a row carry builder options that shape its input, and allow `TestSubject_Behaviour` names.
- Interface rules allow wide completeness interfaces and exported interfaces that type exported struct fields.
- Deadlines now follow whether a write can be de-duplicated, not whether it leaves the process.

## [0.2.5] - 2026-10-15

✨ Added
- New Python plugin (`python/`) with nine skills for Python 3.14 projects: architecture, project, actions, types, protocols, errors, concurrency, testing and quality.
- Python skills cover uv for everything, frozen dataclasses and `NewType` ids, Protocols declared by their consumers, and exceptions handled once.
- A drop-in tool config (`pyproject-tools.toml`) for ruff, pyrefly strict, pytest, coverage, import-linter and mutmut, with a reason beside each rule.
- `ast`-based source guards for the rules no linter can hold, each with the test that proves it fails.
- Install the Python plugin with `npx agntc@latest add leeovery/agentic-skills/python` or `npx skills add leeovery/agentic-skills/python`.

🔧 Changed
- The root README now lists Python alongside Laravel, Nuxt and Go, with a badge, plugin table row and install commands.

## [0.2.4] - 2026-10-13

🔧 Changed
- Go, Laravel and Nuxt skill packs are now declared as `pack` type in their `agntc.json` — agntc installs each one as a skill pack.

## [0.2.3] - 2026-10-13

✨ Added
- Guidance on keeping side effects from repeating on retry or redelivery in the jobs and workflows skills, using leeovery/laravel-idempotency — `Idempotency::once()` for database work, `completed()`/`complete()` for sends.
- Workflows skill gains a "reach for these" section with a reactor example that starts a workflow exactly once.
- Providers skill explains how to configure a scoped manager from `boot()` with `callAfterResolving()`, and how to test it after a worker reset.

🔧 Changed
- Packages and services skills now say that anything registered on a scoped manager at boot is lost when a queue worker resets, and point to a separate singleton registry or `callAfterResolving()`.
- Deferred-provider guidance is more precise — `boot()` runs only once a service is resolved during a request, while artisan processes (queue workers, tests) load every deferred provider, so tests don't catch the problem.

🐛 Fixed
- Anti-patterns now cover sending or calling an external service inside `Idempotency::once()`, and configuring a scoped instance once in `boot()` — both silently fail in practice.

## [0.2.2] - 2026-10-13

✨ Added
- New `laravel-event-sourcing` skill — aggregates as their own state machine, with projectors and reactors, on spatie/laravel-event-sourcing.
- Copy-ready `ProtectsInvariants` trait, `OperationAllowedFrom` attribute and `IllegalTransition` exception for guarding aggregate operations by state.
- New `laravel-workflows` skill — long-running Durable Workflow processes that only sequence, with each step an activity calling an action.

🔧 Changed
- The actions, architecture, enums, jobs and state-machines skills now point to the event-sourcing and workflows skills, and the state-machines skill is scoped to models that aren't event-sourced.
- Laravel plugin README and root README list 22 skills, with a new Event Sourcing and Workflows section.

## [0.2.1] - 2026-10-13

🔧 Changed
- Multi-step processes in the Laravel skills now point to chains and batches of jobs, rather than a separate "workflow" concept, for per-step retries and progress tracking.
- The laravel-jobs skill covers `Bus::chain` and `Bus::batch`, including `Batchable` jobs, the `job_batches` table, and `Bus::findBatch($id)->progress()` — chain jobs for sequential steps, batch them when they can run side by side.
- The laravel-state-machines skill says to transition state when a process ends, such as in a chain's last job or a batch's `then()` callback.
- The laravel-actions and laravel-architecture skills link to laravel-jobs for multi-step processes, and note that projects running a workflow engine can have its steps call actions instead.

## [0.2.0] - 2026-10-13

✨ Added
- New Go skills plugin with eight skills — architecture, actions, types, interfaces, errors, concurrency, testing and quality — installable on its own.
- Go quality references include a drop-in golangci-lint v2 config, source-guard test helpers, and a version-to-idiom table for Go 1.21 through 1.27.
- Laravel multi-tenancy testing harness for stancl/tenancy v4 — one kept testing tenant, with central and tenant databases each wrapped in a transaction.
- Laravel packages skill now covers package-tools providers, a shipped fake for the apps that install the package, and Testbench tests.
- Reference files for Laravel controller responses, DTO transformers, model factories and test setup, Rector and Pint config, a `ConditionalRouteBinder`, money handling, and a Saloon driver.

🔧 Changed
- All 20 Laravel skills rewritten around rules with reasons, a "reach for these when the situation calls for it" section, anti-patterns and related skills.
- Laravel skills now cover current conventions — explicit named routes, route-level `->can()`, public ulid binding, spatie/laravel-data with explicit mapping, Pest `test()` naming, and mocking through the container.
- Laravel architecture skill is now a map of which class does which job and where it lives; flat `app/` is the default, and older `src/` layouts keep theirs.
- Plugin READMEs and the root README have a new header, badges and a Go entry; Nuxt skill count is corrected to 18.
- Releases now run through `mint` instead of the hand-rolled script.

🗑️ Removed
- Redundant Laravel reference files, including the architecture philosophy, patterns, decisions, examples and checklist docs.
- Superseded example PHP files across the DTO, exception, multi-tenancy, packages, validation and value-object skills.
- The GitHub Actions release workflow and the committed local Claude settings file.

