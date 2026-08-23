# Testing and verification

Do not invent a new verification strategy for each task.

Use this document as the default testing procedure. Start with the narrowest
check that exercises the changed behavior, then expand only across boundaries
the change actually touched.

Do not begin by running every test suite.

## Standard order

For PHP/application work:

```text
1. Relevant focused test(s)
2. composer lint
3. composer test:static
4. Broader affected test suite, if the change crosses that boundary
5. git diff --check
```

A passing focused test is not a substitute for lint/static analysis when PHP
production code changed.

A documentation-only change does not require application test suites.

## By change type

### Documentation/configuration only

Run:

```bash
git diff --check
```

Run another check only when the configuration itself has an executable
validator.

### PHP unit/domain change

Start with the directly relevant test:

```bash
composer test:unit -- --filter RelevantTest
```

Then:

```bash
composer lint
composer test:static
git diff --check
```

Do not run feature, Docker, plugin-runtime, or browser suites unless the change
actually crosses those boundaries.

### Application / HTTP / persistence behavior

Start with the relevant feature test:

```bash
composer test:feature -- --filter RelevantTest
```

Then:

```bash
composer lint
composer test:static
```

Run broader PostgreSQL-backed application coverage when the changed behavior
depends on persistence, migrations, jobs, transactions, or real application
lifecycle behavior.

Stashd is PostgreSQL-only. Never create SQLite verification paths.

### Database or migration changes

Run the relevant focused tests first, then PostgreSQL coverage.

Use:

```bash
composer test:postgres
```

where the change requires the PostgreSQL integration environment.

For migration changes, also validate the migration history against an existing
migrated development database when available.

Do not modify historical migrations merely to silence validation without first
understanding the mismatch.

### Plugin SDK / plugin runtime changes

Start with the directly affected package/conformance test.

Then run:

```bash
composer test:plugin-runtime
composer lint
composer test:static
```

If Rust runtime code changed, also run the existing workspace checks:

```bash
cargo fmt --check
cargo clippy --workspace --all-targets --all-features
cargo test --workspace
```

Do not run provider parity suites unless the runtime change can affect provider
behavior.

### Provider port / provider behavior

Run:

1. the provider's focused tests;
2. its parity/conformance test;
3. the application lifecycle tests for that provider;
4. the generic runtime suite only if runtime behavior is involved;
5. lint/static analysis.

Do not substitute fixture-level parity for real application lifecycle coverage
when the milestone specifically requires application integration.

### Docker/runtime changes

Run focused checks first, then:

```bash
composer test:docker-smoke
```

Use a fresh build when validating Docker image contents, runtime registration,
entrypoints, installed binaries, extensions, plugin packaging, or container
behavior.

Do not treat a stale local image as verification.

### UI v2

From `ui-v2/` run:

```bash
npm run typecheck
npm run build
```

Run additional UI/browser checks only when the changed slice requires them.

Do not run PHP/backend suites for fixture-only visual changes.

## Escalation rule

Run broader suites because the **change crosses a boundary**, not because more
tests exist.

Examples:

```text
DTO implementation only
→ focused unit + lint + static

repository/query change
→ focused feature + PostgreSQL coverage + lint + static

plugin RPC framing
→ focused RPC/conformance + plugin runtime + Rust checks

Jellyfin application integration
→ Jellyfin parity + application lifecycle + PostgreSQL + plugin runtime

CSS/layout tweak in ui-v2
→ typecheck + build
```

## Existing commands only

Prefer repository commands from `composer.json`, package scripts, and existing
test scripts.

Do not recreate test environments manually when a repository command already
does it.

Do not invent ad-hoc shell pipelines merely to approximate an existing test
suite.

If an existing command is broken or insufficient, identify that explicitly
rather than silently constructing a parallel testing system.

## Reporting

At completion, report:

- focused checks run;
- broader checks run and why;
- checks deliberately not run and why;
- failures that are genuinely pre-existing/environmental.

Do not narrate the entire process used to discover how testing works unless
testing infrastructure itself was the task.
