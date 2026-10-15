# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

