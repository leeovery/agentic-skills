# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

