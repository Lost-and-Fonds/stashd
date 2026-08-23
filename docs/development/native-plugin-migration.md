# Native plugin migration status

Native PHP plugins are Stashd's production architecture. The native runtime,
SDK, package lifecycle, and Jellyfin/Plex parity gates are complete through M9.

Wasmtime/Wasm implementations are retained only under
`reference/wasmtime/`. They are excluded from production Docker builds,
Composer autoloading, discovery, CI, and routine tests.

## Remaining milestones

- **M10 — Podcast Broadcast:** implement the native provider in
  `Lost-and-Fonds/podcast`; do not add Podcast semantics to core.
- **M11 — YouTube Input:** implement the native provider in
  `Lost-and-Fonds/youtube`; do not activate the historical Wasm provider.

Provider repositories own provider behavior. Core owns persistence, Vault
promotion, fixity, scheduling, filesystem authority, and application lifecycle
integration.
