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
- Use code-as-paragraphs vertical spacing: separate logical control-flow and
  terminal steps with blank lines, without padding trivial blocks.

## Verification

Do not rediscover the project's test strategy for every task.

Read [`docs/development/testing.md`](docs/development/testing.md) and use its
verification ladder.

Start with the narrowest relevant test and expand only across boundaries touched
by the change. Prefer existing Composer/package scripts over ad-hoc commands.
Do not begin by running the entire test universe.

The project uses Lerd for local PHP development and Docker/Podman for
deployment. See [local development](docs/development/local-development.md),
[workflow](docs/development/workflow.md), and the relevant architecture docs.

For security-sensitive work, read [security practices](docs/development/security.md).
For plugins, read [the plugin runtime boundary](docs/architecture/plugin-runtime.md)
and [the migration roadmap](docs/development/plugin-migration.md).
