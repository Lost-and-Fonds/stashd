# Plugin migration status

PHP plugins are Stashd's production architecture. The plugin runtime,
SDK, package lifecycle, and provider acceptance gates are complete through M11.5.

Wasmtime/Wasm implementations are retained only under
`reference/wasmtime/`. They are excluded from production Docker builds,
Composer autoloading, discovery, CI, and routine tests.

## Milestone status

- **M10 — Podcast Broadcast:** complete.
- **M11 — YouTube Input:** complete.
- **M11.5 — Final hardening and packaged acceptance:** complete.

The old production Wasm/Wasmtime and dual-runtime parity direction is obsolete.
Production plugins use bubblewrap-isolated processes; WIT remains the
semantic/reference contract, and Wasmtime remains reference-only.

Next: resume Stashd UI/product work.

Provider repositories own provider behavior. Core owns persistence, Vault
promotion, fixity, scheduling, filesystem authority, and application lifecycle
integration.
