# Stashd project instructions

Stashd is self-hosted media-preservation infrastructure:

```text
Stash → Vault → Broadcasts
```

The Vault is the canonical archive. Broadcasts are disposable, rebuildable
views over Vault assets. Stashd is not a media player, recommendation engine,
or public media catalogue.

## Architecture invariants

- Every preserved media item reaches the Vault before it is broadcast.
- Vault assets are canonical; generated Broadcast files are disposable.
- Core owns authoritative records, provenance, fixity, promotion, filesystem
  authority, jobs, and generic plugin capabilities.
- External plugins own provider protocols, provider semantics, and declared
  helper behavior. Core must not parse provider responses or shell out to
  provider tools.
- Filesystem Broadcasts are hardlink-first. Do not silently copy when
  hardlinking is expected.
- Trigger failures are distinct from invalid Broadcast files.
- Secrets use the encrypted secret service and must never appear in logs,
  public responses, job metadata, or generated URLs.

## Working rules

- Inspect the relevant existing code, tests, and documentation before
  inventing a pattern.
- Keep changes small and feature-first. Preserve unrelated worktree changes.
- Prefer deletion and existing project patterns over speculative abstractions.
- Controllers adapt HTTP; long-running work belongs in commands/jobs.
- Use explicit API resources/arrays at public boundaries; do not serialize
  internal records directly.
- Keep PHP source compatible with PHP 8.5 and the project's PER Coding Style
  3.0 standard. See [PHP standards](docs/foundation/php-standards.md).

## Verification

Run the narrowest relevant checks first, then broader checks when the change
warrants them. Before handoff, report commands actually run and anything not
run. Normal quality gates are `composer lint`, `composer test:static`, the
relevant Pest tests, and `git diff --check`; Docker/runtime changes also need
the appropriate fresh-container smoke test.

The project uses Lerd for local PHP development and Docker/Podman for
deployment. See [local development](docs/development/local-development.md),
[workflow](docs/development/workflow.md), and the relevant architecture docs.

For security-sensitive work, read [security practices](docs/development/security.md).
For native plugins, read [the native runtime boundary](docs/architecture/native-plugin-runtime.md)
and [the migration roadmap](docs/development/native-plugin-migration.md).
