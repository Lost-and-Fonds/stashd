# Integration gaps

This file is a parking lot for backend/API capabilities implied by the UI.

During design phases, **record gaps here instead of solving them**.

| UI surface | Needed data/action | Existing backend support | Later work |
| --- | --- | --- | --- |
| _example_ | Human-readable latest run status | Unknown | Verify during Phase 6 |
| Stash detail · Broadcast | Published vs. total item count per Broadcast, and a build-currentness state (`current`/`rebuilding`/`stale`) distinct from trigger health | Unknown — AGENTS.md documents trigger failures being separate from file validity, but no confirmed field for "how much of the Vault has been published into this Broadcast" | Verify during Phase 6 |
| Stash detail · Broadcast | Output size per Broadcast (separate from Vault/Stash total size) | Unknown | Verify during Phase 6 |
| Stash detail · Broadcast (podcast) | Rotate feed URL/token action | Unknown | Verify during Phase 6 |
| Stash detail · Input | Sync mode (automatic/manual) and a short filter summary | Unknown | Verify during Phase 6 |
| Stash detail · Input/Broadcast | Direct "Sync now" / "Rebuild" trigger per row (not just Stash-level rebuild) | Unknown | Verify during Phase 6 |
| New stash workflow | Real provider/source detection from a pasted URL (type, and a source-derived name suggestion) | Unknown — UI-v2 currently simulates this with regex against the URL string | Verify during Phase 6 |
| New stash workflow | Create-Stash + initial Input as one action/transaction | Unknown | Verify during Phase 6 |
| New broadcast workflow | Authoritative list of available/configurable Broadcast types | Backend exposes the generic plugin registry and declarations; the frontend consumes them without provider-specific component names | Verify during Phase 6 |
| Plugin configuration fields | Normalize manifest field declarations before rendering: YouTube says `bool`; Podcast/Plex say `boolean`; current select choices are string lists without display labels | Declarations currently differ by plugin kind and use `input_options`/`ui_options`/`source_options` | Adapt at the API boundary; do not put provider checks in field components |
| New broadcast workflow | List of configured media-server Connections available to pick from (UI-v2 uses a 2-row placeholder fixture, not the real Connections page, which is still unbuilt) | Backend has `MediaServerConnectionRecord`/`MediaServerConnectionRepository` | Verify during Phase 6 |
| New broadcast workflow (media-server) | Library discovery from a selected Connection (currently a free-text "Library" field, not a real picker) | Backend has `MediaServerLibrarySelection` but discovery/listing endpoint unconfirmed | Verify during Phase 6 |
| Stash detail · Broadcast | A "not yet built" presentation for a freshly created Broadcast — the existing stale-build copy ("Needs rebuild · last built never") reads awkwardly for a Broadcast that has never built at all versus one that's gone stale after building once | Unknown | Consider a distinct wording/state during the later Broadcast state pass |
| Vault overview | Canonical, deduplicated Item list across all Stashes, with per-Item Stash-membership count and Broadcast-usage count | Backend has `MediaItemRecord` as the canonical entity and role-tagged `AssetRecord`s, but no confirmed query/endpoint that returns one row per canonical Item with aggregate membership/usage counts (UI-v2 fixtures hardcode `stashCount`/`broadcastCount`) | Verify during Phase 6 |
| Vault overview | Total preserved Item count/size for the header's orientation line (currently a hardcoded fixture constant, not derived) | Unknown — would need Asset-size aggregation across the whole Vault | Verify during Phase 6 |
| Vault overview → future Item page | Integrity/fixity state per Item/Asset (Verified/Verification due/Verifying/Missing/Storage unavailable/Fixity mismatch) and preservation history — explicitly deferred, not built in this slice | Known future first-class capability per product direction; no backend fields confirmed yet | Design + verify when the integrity system is scoped |
| Status | Storage capacity/usage scoped to what's *available to Stashd* (mount/quota/dataset), not the host's total physical capacity, plus the semantic breakdown (Vault=preserved, Broadcasts=rebuildable, Cache/temp=reclaimable) | Unknown — no confirmed mount/quota-detection or per-category size aggregation | Verify during Phase 6 |
| Status | CPU/memory usage scoped to what's available to Stashd (container/cgroup limits), not raw host metrics | Unknown — no confirmed cgroup-aware measurement | Verify during Phase 6 |
| Status | List of current Needs-attention issues (failed rebuilds, expired connection credentials, storage pressure, etc.) and in-progress operations, each with enough context to route to the owning object | Unknown — no confirmed aggregation endpoint across Stashes/Broadcasts/Connections | Verify during Phase 6 |
| Status | Recent operational activity tail (last few preserve/rebuild/check events across the whole app) | Unknown | Verify during Phase 6 |

Keep entries short. Do not turn this into an API specification during the UI phase.
