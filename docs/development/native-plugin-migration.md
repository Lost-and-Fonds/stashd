# Native plugin migration roadmap

The native runtime is additive while the existing Wasmtime Component runtime
remains production code. The stable boundary and security policy are recorded
in [native plugin runtime](../architecture/native-plugin-runtime.md).

## Completed foundation

- Native runner, bubblewrap policy, RPC v1, WIT/schema bridge, PHP SDK,
  brokered capabilities, package lifecycle, and provider-neutral operational
  conformance were proven in bounded milestones.
- The productionized runner, SDK, package lifecycle, and capability policy no
  longer depend on executable spike code.
- The first native provider port established parity without adding provider
  semantics to core.

## Remaining migration sequence

### M8 — Jellyfin

- **Status:** Complete.
- **Purpose:** Complete the Jellyfin native provider migration.
- **Completion criterion:** Native provider implementation, application-level
  native/Wasmtime selection, and PostgreSQL lifecycle/rollback coverage are
  complete, with equivalent provider behavior through both runtimes.
- **Hard stop:** Do not begin M9 or add another provider until this gate is
  intentionally reopened.

### M9 — Plex

- **Status:** Complete.
- **Purpose:** Port Plex to native runtime parity.
- **Completion criterion:** Plex has native parity through the real
  PostgreSQL-backed application lifecycle, including runtime selection,
  rebuild/materialization behavior, credential-backed HTTP, XML discovery,
  publication, captions, refresh, and rollback, with Wasmtime retained.
- **Hard stop:** No new provider semantics in core; do not remove Wasmtime
  rollback or begin M10 in this milestone.

### M10 — Podcast Broadcast

- **Purpose:** Port the Podcast Broadcast plugin to native parity.
- **Completion criterion:** The generic Broadcast contract is proven against
  the more complex podcast lifecycle.
- **Hard stop:** Do not add podcast-specific semantics to core or treat a
  simpler provider lifecycle as sufficient proof.

### M11 — YouTube Input

- **Purpose:** Port YouTube Input to native parity.
- **Completion criterion:** Input acquisition, helper, network, and resource
  behavior are proven through the production native runtime.
- **Hard stop:** Do not bypass the production runtime or reimplement provider
  behavior in core.

### M12 — Parity/deprecation decision

- **Purpose:** Decide the long-term role of Wasmtime after native migration.
- **Completion criterion:** All active providers have native parity; behavior,
  deployment, and failure modes have been compared; the Wasmtime decision is
  deliberate and recorded.
- **Hard stop:** Do not remove Wasmtime before all active providers have native
  parity and rollback/compatibility coverage is closed deliberately.

The separately extracted `plugin-contract` package is explicitly deferred.
Revisit it only if a real provider port exposes contract drift or awkward
shared DTO ownership; do not extract it speculatively.

RSS/Input work resumes only after the native runtime's next provider parity
gate is complete and does not require a new generic capability.
