# Draft Plugin API v1 design — not yet stable

Status: draft design evidence, 2026-08-11. No WIT interface in this document is frozen.

Migration note, 2026-08-21: the experimental substrate is now exercised by a
standalone YouTube Input Component through the normal Stashd lifecycle. The
former in-tree PHP YouTube provider and its ytdlphp wiring have been removed.
The older strategy names and implementation descriptions below are historical
design evidence; current provider behavior belongs to `plugins/youtube` and
generic core selects it by logical provider identity only.

This document designs the semantic Stashd Plugin API from existing behaviour and four capability stress tests. It does not implement a plugin runtime, package format, WIT contract, permissions UI, or plugin conversion.

## 1. Scope and status

The target is a boundary for capabilities that Stashd may eventually host outside PHP. It is not a replacement for Stashd's information architecture.

The repository currently has an in-tree `BroadcastPlugin` abstraction and an experimental Rust/WebAssembly spike. Neither is automatically the public API. The current broadcast interface is evidence about lifecycle needs; the spike is evidence about containment and opaque resources.

The general architecture reference is [plugins.md](plugins.md). The historical spike is [PLUGIN-SPIKE.md](PLUGIN-SPIKE.md), and the in-tree migration record is [Broadcast-Plugin-Architecture-Plan.md](Broadcast-Plugin-Architecture-Plan.md).

## 2. Validated substrate summary

The experimental substrate established this viable execution shape:

```text
PHP / Tempest
    ↓ private local IPC
trusted Rust plugin host
    ↓ WIT / WebAssembly Component Model
untrusted plugin component
```

The spike demonstrated:

- direct Wasmtime Component Model hosting;
- PHP remaining authoritative for Stashes, Inputs, Items, Assets, Broadcasts, Connections, secrets, durable operations, provenance, fixity, and promotion;
- Rust owning loading, containment, limits, cancellation, isolation, and streaming mechanics;
- opaque WIT resources for a read-only Vault Asset and writable staging;
- plugin failure isolation from PHP;
- a narrow private PHP-to-Rust protocol;
- no current need for Extism.

The spike did not define plugin semantics, production streaming, network policy, secret use, Connections, persistent state, or a stable manifest. Its conceptual `vault-asset.read(offset, maximum)` and `staging-output.write/finish` operations remain experiments.

## 3. Design principles

1. **Plugins extend capabilities, not information architecture.** Stashd owns navigation, pages, routes, UX, domain concepts, workflow presentation, and preservation semantics.
2. **PHP owns truth.** Plugin execution may propose facts, plans, outputs, and next opaque state. PHP decides what becomes authoritative.
3. **Rust owns containment.** The host is trusted execution machinery, not a second Stashd application.
4. **Only Stashd core can declare data preserved.** A plugin can discover, read, transform, or produce data; it cannot commit bytes, fixity, provenance, or preservation history.
5. **Capabilities are narrower than paths and identities.** Grant an operation-scoped capability, not a filesystem path, database handle, or broad context object.
6. **Rebuildable output is not canonical media.** Broadcast output and plugin staging remain disposable until core validates and deliberately promotes something.
7. **Real differences remain visible.** A YouTube Input, a filesystem Broadcast, a TTS Broadcast, and a hardware capture Input need not share one artificial workflow interface.
8. **Semantic portability comes before runtime portability.** The API should describe Stashd capabilities, not Wasmtime, PHP, or a particular process model.

The current contract review adds a practical constraint: public Input
contracts describe Stashd semantics, not the mechanisms of the first provider
implemented. Runtime facilities such as HTTP, credential use, helper
execution, and staging remain implementation capabilities, separate from
`resolve`, `discover`, and `acquire`. Before adding a generic Input field, ask
whether it would still make sense for a local-folder Input.

## 4. Existing-system archaeology

### 4.1 YouTube

#### Recognition and resolution

`ProviderRegistry` resolves a provider from a typed `StashdUri`. `YouTubeUriDetector` recognises YouTube hosts, including `youtube.com`, mobile/music hosts, and `youtu.be`. `YouTubeUriResolver` recognises:

- channel handles (`/@name`), channel IDs, custom channel URLs, and legacy user URLs;
- playlists;
- watch URLs and short links;
- Shorts URLs.

The resolver produces a `ResolvedInput` containing provider key, input type, source URI, provider input ID, optional title/avatar, and an estimated count. Channel handles and similar aliases are resolved to a channel ID by `YouTubeChannelIdResolver`; wrong-channel fallback is deliberately avoided.

The current semantic input types are channel, playlist, and video. The database also has `url_list` for the broader input model, but YouTube does not currently implement that type.

#### Stored Input state

`stash_inputs` stores the Stash relationship, provider key, mapped input type, source URI, provider input ID, title, sync mode, per-input options, state, failure counters, and last/next check timestamps. Provider-specific cursor state is not currently stored as a separate cursor field. The present strategies restart discovery and deduplicate against existing `MediaItem`/`MediaItemSource` identity.

`StashInputOptions` stores universal title include/exclude regexes and opaque provider option values. YouTube declares `include_shorts` and `include_live`; filtered candidates are retained as ignored Stash Items with reasons rather than silently deleted.

#### Discovery and metadata

Discovery is strategy-based. `ProviderStrategySelector` chooses an available strategy by purpose, cost, priority, and job intent. Current YouTube strategies are:

| Strategy | Evidence | Behaviour |
|---|---|---|
| RSS | `YouTubeRssDiscoveryStrategy` | Public channel/playlist feeds; cheap; limited recent result set; also resolves individual videos through local strategy code. |
| Data API | `YouTubeDataApiDiscoveryStrategy` | Optional API-key-backed pagination; resolves channel uploads playlists, pages playlist items, batches video classification, and returns title, description, duration, date, thumbnail, and content type. |
| yt-dlp flat listing | `YouTubeYtdlpDiscoveryStrategy` | Fallback full channel/playlist listing when enabled; one flat-playlist extraction; returns id/title/duration/date/thumbnail and raw flat metadata. |
| Data API metadata | `YouTubeDataApiMetadataStrategy` | Optional per-item enrichment from the Data API. |

`DiscoveredItem` produces provider item identity, canonical URI, title, description, duration, publication time, thumbnail URI, content type, and optional raw metadata. Metadata snapshots preserve provider evidence, subject to secret redaction at the existing boundary.

Preflight runs asynchronously, resolves the same strongest available discovery strategy that initial backfill will use, returns all currently discovered candidates, estimates item count and total duration, exposes universal/provider filters, and lets the user review before commit. Commit reruns discovery rather than trusting a stale preview. Sync also reruns discovery, commits through one deduplicating committer, records failures, realigns positions, queues downloads for eligible new items, and queues Broadcast rebuilds when items changed.

#### Acquisition

Discovery does not download bytes. A separate `DownloadStrategyHandler`/`DownloaderInterface` boundary handles acquisition. For YouTube, `YtdlpDownloader` calls only `YtdlpGateway`, which wraps `hazel/ytdlphp` and `yt-dlp`.

The download path is:

```text
item.download
  → temp staging directory
  → yt-dlp/ytdlphp extraction and download
  → normalized original + metadata/source sidecars
  → Stashd validation and Vault ingest
  → Asset rows and MediaItem state transition
```

The plugin-shaped semantic question is therefore not “may a provider write to Vault?” It is “does a provider describe an acquisition that core performs, or does it stream acquisition bytes through a granted staging capability?” The existing design strongly favours the former for ordinary network media: provider code describes source identity and acquisition policy; Stashd owns temp staging, Vault ingest, checksum, provenance, and state transitions.

#### Credentials, errors, and progress

The YouTube Data API key is stored through `SecretsService` with an environment fallback and is never echoed. It is currently used by provider code to construct API requests. It is a candidate for a credential-use capability, not necessarily a first-class Connection.

Current errors include unsupported URL/input type, feed unavailable, RSS fetch/parse failure, Data API unavailable/fetch/parse/not-found failures, yt-dlp unavailable, timeout, invalid URI, bot check, rate limit, transient unavailability, unexpected output, and general download failure. Retryability is classified before messages are redacted.

Download progress forwards yt-dlp's bytes/percent/ETA/speed. `DownloadProgressSmoother` maps stream resets into aggregate stages such as “Downloading media” and “Downloading additional stream”. The job layer throttles progress and heartbeats. Discovery itself has less granular live progress; a future plugin must support long-running paginated discovery without assuming every operation has a known denominator.

Useful preflight facts include optional resolved source identity, generic discovery completeness/estimate-quality facts, item count or range, candidate sample/list, duration total, filter choices, credential/configuration requirements, and a warning when discovery is incomplete. Useful sync facts include new/reused/ignored counts, upstream disappearance or unavailability, cursor/continuation state, and retryability.

### 4.2 Jellyfin

The current `JellyfinBroadcastPlugin` uses the shared `AbstractSeriesBroadcastPlugin` with the key `jellyfin`. It produces a rebuildable series filesystem view from eligible Vault originals. The shared engine:

- selects active Stash Items with ready Vault originals;
- orders seasons and episodes using Stash metadata, optional season mapping, publication time, position, and stable IDs;
- plans media files and NFO/subtitle/poster sidecars;
- uses hardlinks from Vault first and verifies inode identity;
- optionally generates a local remux when configured timeline data requires it;
- records Broadcast Items and generated Asset rows;
- verifies filesystem reality and source relationship;
- prunes only owned stale output;
- never silently copies when hardlinks fail.

The broadcast destination is core-resolved and ownership-marked. A configured `destination_path` is currently a literal host/container path, but that is an implementation detail that a future plugin API should not expose to an untrusted plugin.

Jellyfin's external behaviour is separate from publish validity. `MediaServerConnectionRecord` stores type, display name, base URI, encrypted token reference, selected library settings, state, and health timestamps/errors. A Jellyfin plugin knows the Jellyfin protocol: it tests `/System/Info/Public`, lists `/Library/MediaFolders`, and refreshes with `POST /Library/Refresh`. Stashd owns the Connection record and invocation grant, but core should not grow a generic `refresh-jellyfin-library` operation. A post-rebuild scan failure is recorded as a trigger failure and does not invalidate verified files.

The Jellyfin plugin itself does not need arbitrary network access to publish files. The scan trigger needs a configured media-server Connection and constrained network/credential capabilities for the plugin's Jellyfin protocol calls. Credentials are used without exposing their raw value to plugin code.

### 4.3 Plex

The current `PlexBroadcastPlugin` uses the same shared series engine and the key `plex`. Its current output policy is materially the same as Jellyfin: SxxExxx layout, NFO sidecars, captions, optional poster hardlink, hardlink-first publishing, verification, and pruning.

Plex's external behaviour differs in the Connection client. A Plex plugin knows the Plex protocol: it tests `/identity`, lists `/library/sections`, and triggers `GET /library/sections/{id}/refresh` with the token in the request. The selected library ID is meaningful to Plex; a scan path is not used by the current client in the same way as the endpoint shape suggests. Trigger failures remain separate from broadcast file validity.

#### Comparison

Shared semantic requirements:

- read eligible canonical Assets;
- deterministic, rebuildable layout planning;
- write only Stashd-owned generated output;
- hardlink-first publishing with explicit failure when unavailable;
- return an expected output manifest for core to verify against canonical sources;
- let core reconcile or prune generated output without touching Vault originals;
- optional external refresh after successful verification;
- reusable external service configuration and secret use;
- separate publish validity from refresh-trigger health.

Provider-specific or implementation-specific details:

- Jellyfin refresh is a broad library refresh endpoint and uses `X-Emby-Token`;
- Plex refresh is section-scoped and passes `X-Plex-Token` in the URL;
- library discovery response shapes differ;
- NFO/layout policies may diverge later even though current profiles are identical;
- neither plugin should own the generic filesystem ownership, Vault selection, fixity, or public UI semantics.

The shared PHP base class is useful evidence of common behaviour, not proof that the future third-party API should expose a “series plugin” superclass. The protocol details remain plugin-owned: Stashd owns Connection records and grants, while the selected plugin owns how it lists or refreshes its service. Core should not standardise provider operations such as `refresh-jellyfin-library`.

## 5. Stress test A — YouTube Input

### Contribution

The plugin contributes candidate discovery, optional metadata enrichment, provider-specific filter declarations, incremental continuation state, and an acquisition description. For URL-like sources such as YouTube it may also contribute source recognition/resolution. That is optional: upload, watched-folder, scanner, and capture-device Inputs may be explicitly selected and configured without recognising a source reference. The plugin should not own Stash creation, MediaItem deduplication, filtering policy, download policy, or Vault promotion.

### Configuration and state

- **Installation-wide:** optional Data API credential and provider defaults/policy.
- **Per Input:** source/configuration identity, optional resolved source identity, input type, sync mode, filters, and opaque provider continuation state if the provider truly needs it.
- **Authoritative PHP state:** Input identity, source URI, provider/item identity, candidate Items, source relationships, filters, sync timestamps, errors, MediaItem state, download policy, Assets, and preservation history.
- **Opaque plugin state:** only the minimum continuation token, remote snapshot marker, or provider cursor needed between invocations. PHP stores it and passes it back; the Rust host owns none of it.

### Invocation grants and external access

Discovery needs a bounded HTTP capability to approved YouTube/API/RSS hosts. RSS versus Data API versus yt-dlp is internal provider strategy, not generic Plugin API semantics. Stashd may consume generic facts such as complete/partial discovery, estimate quality, rate limiting, or a missing capability/credential. Acquisition is preferably a core-owned operation description consumed by Stashd's downloader. If a plugin must implement acquisition, it receives staging output and network access, not Vault access.

The Data API key can be used without exposing its raw value through a host-mediated request or credential-use capability. Raw read is not required by this implementation. Cookies/session material, if a future provider needs them, should be treated as a separate permission and not smuggled through generic configuration.

### Preflight, progress, cancellation, and outputs

Preflight returns optional resolved-input facts, generic capability facts, candidate Items or a bounded sample plus count/range, filter schema, estimated duration/storage facts where available, and warnings about incomplete discovery. It must allow “calculating”, “partial”, and “unable to estimate”; unavailable estimates never alone block creation.

Progress should report stages such as resolving source, fetching page N, classifying items, waiting on rate limit, and complete. It may include completed/known totals, but must not require a denominator for an unbounded collection. Cancellation stops the invocation and prevents an incomplete continuation state from being committed.

The preferred output is a typed discovery/acquisition result: candidate facts, next opaque state, and an acquisition plan. If bytes are produced by the plugin, they go to staging and return a staged output descriptor; PHP validates and ingests them. No output directly changes a canonical Asset.

### Failures, health, and prohibitions

Useful provider errors are unsupported source, authentication/credential unavailable, rate limited, source unavailable/private, malformed response, unsupported content, partial discovery, and transient upstream failure. Health may report capability availability, last successful check, rate-limit/backoff state, and a redacted diagnostic.

The plugin must not see the database, arbitrary filesystem paths, raw Vault paths, unrelated Stashes, other plugin state, raw secrets by default, or the final decision to preserve. It must not delete upstream/local canonical records or turn “discovered” into “preserved”.

Product surfaces consuming these facts are Stash Input creation/review, sync activity, command/job progress, item state, storage/preflight review, and health—not plugin-owned pages.

### Acquisition tradeoff

Passing acquisition bytes through a plugin is more general and can support non-HTTP capture, but it duplicates the hardest parts of Stashd's download boundary: temporary files, probing, checksums, output classification, retries, and provenance. Describing acquisition for core execution is smaller and safer for YouTube. The LaserDisc case prevents making that choice universal: the semantic API must permit a plugin-produced staged stream for sources that core cannot acquire itself.

## 6. Stress test B — Jellyfin and Plex Broadcasts

### Contribution and configuration

Each plugin contributes a reproducible presentation policy: eligibility, ordering, naming/layout, sidecar requirements, optional transformations, and an expected output manifest. It may also return an external refresh intent. Stashd/core owns verification of output ownership and filesystem reality, then safely reconciles or prunes Stashd-owned output. Whether specialised plugin verification callbacks are eventually useful remains open.

- **Installation-wide:** plugin identity/capabilities and perhaps default layout policy.
- **Per Broadcast:** name, item selection through the Stash, layout/presentation settings, captions/timeline choices, destination binding, and optional Connection reference.
- **Per Connection:** server URI, server type, selected library, encrypted token reference, enabled/disabled state, and health state.
- **PHP authority:** Broadcast record, selected Items, generated assets, ownership marker, output paths, fixity/provenance, rebuild state, trigger records, and all secret storage.

### Host grants and filesystem model

The plugin needs read capabilities for explicitly selected Vault Assets and a write capability for generated staging/output. It does not need a raw destination path. A host-side `broadcast-output` or `staging-workspace` capability should enforce the Broadcast-owned root, relative paths, overwrite/prune policy, and output accounting. Core verifies the expected manifest against ownership and filesystem reality, then safely reconciles or prunes only Stashd-owned output. Core can implement a hardlink operation as a trusted media/filesystem operation rather than giving the plugin arbitrary filesystem access.

This matters because the existing PHP implementation currently deals in absolute source and destination paths. That is acceptable inside trusted core, but a raw path would let an untrusted plugin escape the intended Broadcast boundary.

### Connections and external refresh

Jellyfin and Plex are genuine reusable Connections: they have an endpoint, reusable account/service credentials, discoverable libraries, health, and Broadcast-specific selection. Stashd owns the Connection record and grant; the selected plugin owns the protocol semantics for using it. A Broadcast references a Connection; it does not duplicate its token.

The first API should grant the plugin a configured Connection through constrained network and credential capabilities. The Jellyfin or Plex plugin then performs its own protocol-specific test, library listing, and refresh operations. Selecting a Connection grants only its configured endpoint and declared scope; it does not require unrestricted network access, expose a raw token, or turn provider operations into generic core methods. Core decides whether the requested operation is allowed and records the run.

### Planning, progress, cancellation, outputs

Preflight/plan should report eligible items, reused outputs, new outputs, transformations, sidecars, storage impact, hardlink feasibility, and whether an external refresh will be requested. For current hardlink series output, additional storage can be zero while file count is non-zero. A different filesystem should produce a known hardlink-unavailable condition, not a silent copy.

Progress can use aggregate item counts with current stage/item: planning, reading Asset, linking or transforming item N, writing sidecars, core verification/reconciliation, and refreshing the server. Cancellation must leave no half-claimed canonical state; incomplete generated files remain core-owned staging or are cleaned/reconciled by PHP.

Outputs are rebuildable Broadcast files and sidecars. A hardlink or generated remux may be represented as a derived generated Asset, but it is not a Vault original and does not become preservation truth merely because a plugin produced it. Trigger failure is separate from file validity.

### Failure, health, and hidden information

Useful errors include source Asset missing/unready, output conflict, hardlink unavailable, transformation unavailable/failed, invalid layout, sidecar failure, connection unavailable, library not configured, authentication failed, refresh rejected, and verification drift. Health can distinguish local output/storage readiness from external Connection reachability.

The plugin must not receive unrelated Assets, arbitrary paths, raw tokens, database access, permission to delete Vault files, or permission to mark a Broadcast valid. Stashd surfaces the plan, progress, validity, and trigger failure in its own Broadcast UI/activity.

## 7. Stress test C — hypothetical TTS Podcast Broadcast

### Contribution and configuration

The plugin contributes a reproducible document-to-listening presentation: eligible document selection, text-source preference, text preparation, speech generation, episode metadata, and podcast-compatible disposable output.

- **Installation-wide:** engine defaults, supported voices/models, local model availability, and perhaps an external TTS Connection type.
- **Per Broadcast:** voice/model choice, language, text preparation settings, episode ordering, and feed metadata.
- **Per Connection:** endpoint/account/model access when using an external TTS service.
- **PHP authority:** document Items, existing derived Assets, Broadcast identity/tokens, output lifecycle, preservation decisions, and all durable Asset/provenance records.

### OCR and derived Assets

The case proves that the plugin needs to ask for existing derived Assets by semantic role (for example extracted text or OCR), not by filesystem path. It does not yet prove a complete enrichment API. OCR should remain a deferred reusable-enrichment design unless multiple features need it; the first API can expose “read a granted suitable Asset” and return “missing prerequisite” with a suggested host operation.

TTS audio is normally rebuildable Broadcast output. If Stashd later chooses to preserve it, the plugin returns a staged output plus provenance/derivation facts; PHP validates, checksums, deduplicates, records lineage, and alone declares a new Vault Asset preserved. The plugin cannot promote it itself.

### Local versus external engines

A local engine needs model/runtime availability and possibly a trusted media operation, but no network credential. An external API needs a reusable Connection or credential-use capability, network access to an approved endpoint, response-size/time limits, and redacted errors. Both should implement the same semantic “produce audio into staging” result. They should not be forced into the same Connection type if the local engine has no reusable remote account/resource; local engine selection is plugin configuration unless it has independently managed resources.

### Long operations

Preflight may first count eligible documents, then asynchronously calculate missing OCR/text work and audio estimates. It can return partial facts: known item count, known text bytes, unknown speech duration, a range, or “unable to estimate”. Progress should report current Item and stage (selecting text, OCR prerequisite, preparing text, synthesising, writing output) with optional byte/duration totals. Cancellation must stop generation and discard or quarantine incomplete staging output.

The plugin must not expose a feed route, Vue component, raw HTML, or public token. Stashd owns the podcast surface and token rules.

## 8. Stress test D — hypothetical LaserDisc Capture Input

### Contribution

The plugin contributes physical-source recognition/configuration, capture-session control, device/native-library integration, capture metadata, a long-running acquisition stream, interruption/retry semantics, and provenance facts. This is not an HTTP provider and is intentionally allowed to be awkward.

### Configuration and state

- **Installation-wide:** capture backend availability, device/native library version, station defaults, and hardware capability facts.
- **Per Input:** station/device selection, physical source identifier, capture profile, side/track/session metadata, and retry policy.
- **PHP authority:** Input identity, capture history, staged output, fixity, provenance, Vault Asset state, and preservation declaration.
- **Opaque plugin state:** resumable capture-session token or device-specific continuation data, stored by PHP and returned by the plugin. It must not become a host-owned database.

### Capabilities and runtime implications

The capture plugin requires a large streaming staging output, long-running operation, cancellation, progress, and physical provenance. It may require native libraries or hardware access that embedded Wasm cannot safely or practically provide. A future external/native/container runtime could implement the same semantic contribution if the contract uses Stashd concepts—source, operation, staged output, provenance, health—not Wasmtime handles or PHP classes.

The host must grant a specific capture device/session capability, not arbitrary host device access. Hardware failure, disconnected media, unsupported capture mode, partial stream, interruption, and insufficient staging space need typed operational errors.

Captured bytes are not preserved merely because capture completed. The plugin writes only staging output; PHP validates the stream, calculates fixity, records physical-source provenance, deduplicates, and decides whether to commit a canonical Asset.

### Explicit boundary

This case rejects an API secretly shaped as “HTTP URL in, list of media URLs out”. It does not require a generic workflow engine. It requires that Input contributions can describe sources that need a host operation and can return large output through a bounded staging capability.

## 9. Cross-case requirement matrix

Legend: **required** = demonstrated core need; **optional** = useful for this case but not universal; **deferred** = not yet enough evidence for a shared API.

| Requirement | YouTube | Jellyfin | Plex | TTS | LaserDisc |
|---|---|---|---|---|---|
| Discovery | required | optional | optional | required | required |
| Incremental state | required | optional rebuild state | optional rebuild state | optional | required |
| Network | required, approved hosts | optional, Connection-mediated | optional, Connection-mediated | optional/external engine | optional |
| Raw secret | not needed in current code | no | no | maybe provider-specific | maybe hardware/backend-specific |
| Credential use | required for Data API | required for refresh | required for refresh | required for external TTS | optional |
| Vault read | no for discovery; core owns acquisition | required | required | required | optional after capture |
| Staging write | optional if core acquires | required for generated output | required for generated output | required | required, very large |
| Large streaming | possible | media-sized | media-sized | audio-sized | required |
| Reusable Connection | not clearly; API key is plugin config/secret | required | required | external TTS only | device/station may be |
| Preflight | required | required | required | required, partial | required, often partial |
| Long-running operation | required for large discovery/acquisition | required for rebuild | required for rebuild | required | required |
| Cancellation | required | required | required | required | required |
| Health | required | required | required | required | required |
| Derived output | metadata/source sidecars, acquired media | sidecars/remux/hardlinks | sidecars/remux/hardlinks | synthesized audio | captured stream |
| Physical provenance | no | no | no | no | required |
| Native/hardware dependency | no | no | no | local engine may | required/likely |

The genuinely common set is smaller than “all plugins are workflows”: typed contribution identity, configuration/schema facts, preflight, invocation-scoped operations, progress/cancellation, typed errors/health, selected host resources, and PHP-owned state transitions.

## 10. Proposed contribution model

### Plugin core

The core contribution should expose identity, API compatibility, declared contributions, and runtime-facing capability facts. It should not expose Stashd CRUD or a generic invocation method table.

Static package metadata should carry identity and compatibility needed before execution. Runtime inspection may confirm the component's actual exports/capabilities and report a mismatch. WIT should carry the typed operational contract, not publisher/catalog prose.

### Input contribution

An Input is a mechanism/source through which material enters a Stash, not a URL. Source recognition/resolution is useful for URL-like Inputs such as YouTube, but is not universal; an upload, watched-folder, scanner, or capture-device Input may be explicitly selected instead. The meaningful operations are:

1. optionally recognise/resolve a source reference;
2. validate configuration and report required capabilities;
3. discover candidates, possibly incrementally;
4. return candidate Item facts and next opaque state;
5. optionally describe or perform acquisition into staging;
6. report preflight, progress, cancellation, health, and typed errors.

These should not necessarily become one giant interface. Recognition and discovery are Input semantics; downloading should be a separate acquisition result/host operation because the YouTube evidence shows the current system already separates them.

### Broadcast contribution

A Broadcast is a reproducible transformation/presentation of preserved Items. The minimum semantic lifecycle is:

```text
validate configuration
→ plan/preflight
→ build/rebuild into host-owned output
→ return expected output manifest
→ core verifies ownership/reality and reconciles or prunes
```

The plugin may declare whether item-scoped rebuild is meaningful. Core owns Broadcast state, destination binding, output ownership, generated Asset records, verification interpretation, and external trigger records. Specialised plugin verification callbacks may still prove useful, but are an open question rather than a required lifecycle method.

### Connection contribution

Connection is first-class only when a reusable configured external service/account/resource has identity, health, and a grantable endpoint/credential scope shared by multiple invocations or Broadcasts. Jellyfin and Plex qualify. An external TTS account qualifies. A YouTube Data API key is currently better represented as plugin configuration plus secret-use permission; it becomes a Connection only if Stashd later needs reusable API-account identity, health, quotas, or multiple account selection. A local TTS engine is plugin configuration unless it exposes separately managed reusable resources. A LaserDisc device/station may become a Connection if multiple Inputs reuse it and it has independent health/configuration. In every case, Stashd owns the record and grant while the contribution owns the external protocol semantics.

### Health/status

Plugins contribute facts, not widgets, and plugin-reported health is only one observer. Stashd must also report host-observed runtime health and Connection health; a broken plugin cannot be relied upon to report its own unavailability. The minimal semantic model is:

```text
observer: host | plugin | connection
health scope: plugin | contribution | connection | operation
state: healthy | degraded | unavailable | disabled | unknown
code: stable machine-readable classification
message: short redacted human explanation
checked_at / retry_after: optional timing facts
```

Stashd owns aggregation, display, notification, and remediation controls. No arbitrary status cards, URLs, HTML, or frontend code are part of the API.

## 11. Host capability/resource model

Only the following capabilities have evidence across the stress tests or the validated substrate.

| Capability | Why it exists / evidence | Authority granted | Explicitly not granted | WIT? |
|---|---|---|---|---|
| Read-only Asset | Broadcasts and TTS need selected preserved/derived material; the spike validated opaque assets. | Read bytes/metadata of explicitly granted Assets. | No path discovery, mutation, deletion, DB access, or preservation declaration. | Yes, as an opaque semantic resource; exact streaming shape remains open. |
| Staging output/workspace | YouTube fallback acquisition, Broadcast transforms, TTS audio, and LaserDisc capture need output before core validation. | Write bounded invocation output and finish with a descriptor. | No Vault promotion, arbitrary host path, unrelated output deletion, or durable truth. | Yes, likely split into stream/output and workspace operations only if evidence requires it. |
| Approved HTTP | YouTube discovery/API and external TTS need fixed targets; Connection-backed integrations need configured endpoints. | Requests only to statically declared/fixed targets or the endpoint derived from a selected Connection, with limits and redaction. | No arbitrary network pivot, listening socket, raw token access, unrestricted URL fetch, or endpoint expansion. | Yes, with policy supplied by manifest/Connection selection and enforced by Rust/core. |
| Connection use | Jellyfin/Plex/TTS reuse configured external resources. | Use a selected Connection through its constrained endpoint/credential grant; provider protocol remains plugin-owned. | No raw token, arbitrary endpoint changes, unrestricted network, or other Connections. | Yes, as an opaque Connection grant plus plugin-defined protocol calls. |
| Credential use | YouTube API key and media-server/TTS credentials need use without disclosure. | Perform a permitted operation using the secret. | No raw secret value, logging, persistence, or cross-Connection access. | Yes, preferably as operation capability rather than `get-secret`. |
| Log emission | Operations need diagnostics. | Redacted structured log messages with level/code. | No secret/raw payload logging or presentation markup. | Yes, small event type. |
| Progress emission | Existing jobs render aggregate/current stage progress. | Send typed progress events. | No UI markup, arbitrary events, or authoritative state transitions. | Yes, shared event type. |
| Cancellation/operation | All four long-running cases need cancellation; the spike validated host enforcement. | Observe cancellation and let host terminate invocation. | No indefinite execution, cancellation veto, or durable operation ownership. | Runtime plus typed operation contract. |
| Host media operation | Current hardlink/remux/probe behaviour should not become arbitrary plugin shell access. | Request an explicitly approved operation such as hardlink/probe/transform, if later validated. | No shell, arbitrary executable, or unbounded codec pipeline. | Defer exact operation set; do not expose speculatively. |
| Persistent opaque state | YouTube and LaserDisc demonstrate continuation state. | Receive and return bounded opaque bytes/structured state; PHP persists it. | No plugin-owned DB, hidden durable filesystem, or authoritative state. | Yes, as invocation input/output data, not host storage. |

Rejected for v1: generic database access, arbitrary filesystem access, arbitrary shell execution, plugin-to-plugin RPC, arbitrary event bus, generic workflow DSL, and a catch-all `plugin-context` resource.

### Vault and staging invariant

```text
Vault Asset
    ↓ read capability
Plugin
    ↓ output capability
Staging
    ↓
Stashd validation / fixity / provenance / deduplication
    ↓
possible Vault commit
```

**Only Stashd core can declare data preserved.** The experimental `vault-asset` resource remains the right semantic abstraction, but production-scale streaming must be validated before freezing `read(offset, maximum)`. A pull stream, bounded async chunks, or host-mediated file descriptor may be more appropriate; none should expose a raw Vault path to the plugin.

## 12. Ownership and persistent-state model

PHP owns authoritative state: plugin installation/enabled/configuration state, contribution configuration, Connections, secrets at rest, Inputs, Items, Assets, Broadcasts, commands/jobs, operation state, cursors/opaque plugin state, output ownership, fixity, provenance, and preservation history.

Rust owns only ephemeral execution state: loaded components, caches, invocation handles, limits, cancellation, temporary files, and crash isolation. Restarting it must not lose a Stashd decision.

The preferred state exchange is:

```text
PHP stores opaque plugin state
    ↓ invocation input
plugin returns next state
    ↓
PHP validates size/version/association and persists it
```

State is associated with a contribution and Stashd record by PHP. Plugins must not choose arbitrary database keys or write migrations. State size limits and schema/version handling are host concerns; the plugin can declare a state format version as data.

## 13. Secrets and Connections

Keep three concepts separate:

1. **Secret storage:** Stashd stores encrypted secret material through `SecretsService`.
2. **Secret use:** a host/core operation uses a selected credential without returning its plaintext.
3. **Secret read:** a plugin receives the raw value. This is exceptional and must be separately declared/granted.

YouTube Data API requests, Jellyfin refresh, Plex refresh, and ordinary external TTS calls should use secret-use capabilities. Raw read is only justified where a plugin's own protocol/library must construct the request and no host-mediated operation can safely represent it. Such a case needs explicit permission, memory/log redaction, no persistence, and a narrow invocation scope.

Permissions implied by a selected Connection should constrain the selected plugin to its configured endpoint and credential use, not silently expand to arbitrary network access. The Plex plugin may use those grants to implement its own section refresh protocol without granting it “send arbitrary HTTP to this server” or turning that protocol into a generic core operation.

## 14. Preflight — “What Stashd will do”

The existing product surface is a plan/review interaction, not plugin UI. The common result should be data-driven and deliberately tolerant of unknown estimates:

```text
plan
  summary: known/estimated/unknown item and byte facts
  operations: ordered descriptions with kind, count, storage impact, and state
  reuse: already available work/assets
  requirements: credentials, Connections, host capabilities
  warnings/errors: typed, actionable, non-secret
```

Operation kinds should remain opaque/data-driven labels with optional stable categories, not hard-coded `download`, `hardlink`, `transcode`, or `OCR` enums. The UI can render a description supplied by the plugin/core while Stashd owns the presentation.

The estimate model needs at least:

- exact values;
- ranges;
- known counts with unknown bytes;
- “calculating” and partial progress;
- “unable to estimate” with reason;
- no additional storage;
- exact or estimated storage;
- reuse versus new work.

Examples:

- **YouTube:** resolve source, discover 15 or 2,000 candidates, apply filters, reuse existing Items, download new media later; Data API pagination may be calculating.
- **Jellyfin/Plex:** publish N eligible Assets, reuse M existing hardlinks, require zero additional bytes if same-filesystem hardlinks are possible, or report hardlink unavailable before build.
- **TTS:** reuse extracted text for some documents, OCR missing documents, synthesise an unknown/ranged amount of audio, write a rebuildable feed/output.
- **LaserDisc:** reserve or estimate capture staging capacity, identify device/session requirements, and report unknown duration until physical capture begins.

Preflight must never block creation solely because an estimate is unavailable. It may block because a required capability, credential, Connection, device, or source is unavailable.

## 15. Progress and cancellation

Plugins emit typed facts, not presentation markup:

```text
operation id
aggregate completed / total or indeterminate
stage code and short label
current item reference / label, optional
bytes or units completed / total, optional
ETA/rate, optional
```

Stashd maps these to its existing aggregate operation progress and current stage/current Item UI. Unknown totals are first-class. A plugin may emit stage changes without percentages; the host may throttle events and heartbeat the durable job.

Cancellation is requested by PHP and enforced by Rust. Plugins should observe it at host calls and safe internal checkpoints. Rust may terminate a non-cooperative invocation. PHP decides whether returned opaque state is safe to persist; cancelled or partial state must not silently become the next successful cursor. Staging cleanup/quarantine is core-owned.

## 16. Error model

Use a small layered error shape rather than a universal enum:

```text
layer: plugin | host | stashd
code: stable machine-readable local code
retry: never | later | after-user-action | unknown
message: short redacted explanation
details: typed, bounded, non-secret fields
```

Plugin/provider errors cover authentication failed, rate limited, source unavailable, unsupported content, malformed upstream data, missing prerequisite, output invalid, and provider-specific codes. Host errors cover invalid component, denied capability, trap, timeout, memory/resource limit, cancellation, and runtime failure. Stashd errors cover invalid configuration, missing authoritative record, storage unavailable, fixity/validation failure, output ownership conflict, and rejected promotion.

The layers must remain distinguishable. “Plex refresh rejected” is not the same as “plugin trapped”; PHP interprets both in operation context. Raw upstream URLs, tokens, command payloads, and filesystem paths must be redacted or omitted from public/activity output.

## 17. Permissions requirements

Static declarations should distinguish fixed network targets (for example YouTube/API hosts) from Connection-derived targets. They should state the maximum kinds of capability a plugin may request: fixed network host patterns, Connection kinds/operations, credential-use or exceptional raw-secret read, Asset read, staging write, host media operations, and future native/hardware access. Selecting a Connection supplies only its configured endpoint and scope; it is not a request for unrestricted network access.

Dynamic invocation grants should state the actual selected resources: these Assets, this Connection, this staging quota, this approved operation, this Input/Broadcast scope. A manifest declaration is not permission by itself; PHP/core approval and Rust enforcement are required.

The permission UX is out of scope, but the semantic model must support:

- “declared but not configured”;
- “configured but disabled”;
- “implied by selected Connection”;
- “granted for this invocation only”;
- “raw secret access requested” as a visibly exceptional case;
- denial with a typed capability error.

## 18. WIT, manifest, schemas, and catalog boundaries

| Concern | Owner |
|---|---|
| Typed runtime functions, resources, values, errors, host calls, progress, cancellation | WIT/component contract |
| Plugin ID, version, publisher, compatibility, declared contributions, permissions, artifact/runtime facts | Static package manifest |
| Configuration shape, types, required fields, ranges, enums | JSON Schema or equivalent schema data |
| Small labels, grouping, select choices, help text, Stashd-owned control hints | Stashd UI schema/hints |
| Discovery, distribution, compatibility search, trust/publisher metadata | Catalog/package system |

Do not put full catalog data or a frontend framework in WIT. Do not force all configuration rendering into a generic plugin-owned UI. WIT optional exports may have tooling limitations; use both static manifest declarations and runtime exports where useful rather than inventing a generic `invoke(method-name, json)` escape hatch.

## 19. Draft interface/package decomposition

Names are provisional. The decomposition is semantic, not a frozen WIT package layout.

### `stashd:plugin-core` (provisional)

Identity/compatibility facts, contribution inspection, typed common values, configuration validation result, health facts, operation errors, progress, and cancellation relationship. It should not expose Stashd domain CRUD.

### `stashd:input` (provisional)

Optional source recognition/resolution, Input configuration validation, discovery/preflight, candidate Item facts, opaque continuation state, and acquisition description. Acquisition bytes should be an optional separate result rather than mandatory Input behaviour.

### `stashd:broadcast` (provisional)

Broadcast configuration validation, eligibility/plan, rebuild, expected output manifest, and optional item rebuild declaration. Core owns verification/reconciliation/pruning of Stashd-owned output. It consumes host Asset/output capabilities but does not own destination paths.

### `stashd:connection` (provisional)

Contribution-defined Connection kinds, configuration validation, health, and narrow operations. A generic opaque Connection identity is acceptable; arbitrary HTTP is not.

### `stashd:host-assets` (provisional)

Opaque read-only Asset metadata and streaming access. The final stream shape is open.

### `stashd:host-staging` (provisional)

Bounded output/workspace creation, writes, finish, and typed staged-output descriptors. Promotion remains outside the plugin contract and belongs to PHP/core.

### `stashd:host-http` (provisional)

Approved request/response streaming with host policy, size/time limits, redaction, and no arbitrary proxy behaviour.

### `stashd:host-connections` (provisional)

Invocation-scoped access to selected Connection operations and credential use. Keep this separate from raw secret read.

### `stashd:host-events` (provisional)

Small log/progress emission and cancellation observation. This may be folded into `plugin-core` if WIT package dependency overhead is not justified; it is separated here because host event plumbing is cross-cutting and not a domain contribution.

Do not add a generic database, shell, filesystem, plugin-to-plugin, event-bus, workflow, or frontend package.

## 20. Lifecycle and contribution discovery

The semantic lifecycle that affects the API is:

```text
inspect → validate/configure → invoke → update health/state → disable
```

Install, verify package integrity, update, uninstall, catalog, and distribution are package-management concerns. Enable/disable affects whether PHP may grant capabilities. Configuration changes may require schema/version validation and a health recheck.

Contribution discovery should use both:

- **static manifest declarations** for package inspection, permissions, compatibility, and early UI/configuration discovery;
- **WIT exports/runtime inspection** for the executable contract and capability confirmation.

The manifest is not trusted merely because it claims a contribution. Runtime inspection must reject a missing required export or incompatibility. Conversely, optional WIT exports should not be the only way to discover that a package needs a Connection or permission, particularly if tooling cannot model optional exports cleanly.

## 21. External runtime compatibility

The semantic API can be implemented by embedded Wasm, a native subprocess, an external service, or an OCI/container runtime if it keeps these concepts stable:

- contribution identity and compatibility;
- operation-scoped grants;
- typed plans, progress, cancellation, errors, and health;
- read-only selected Assets;
- bounded staged outputs;
- host-mediated network/Connection/credential use;
- PHP-owned state and promotion.

The API must not require a Wasm `resource` handle to appear in the semantic model, expose Wasmtime instance IDs, or assume that a plugin can call back synchronously forever. WIT is an appropriate current transport contract because the validated substrate uses it; a future adapter can map the same semantic capabilities to another runtime.

## 22. Decisions supported by evidence

1. Keep PHP authoritative; the spike and all current lifecycle code depend on it.
2. Keep Vault Assets read-only and opaque; both the spike and Broadcast security boundary support this.
3. Keep staging separate from preservation; YouTube temp download, current Vault ingest, TTS, and LaserDisc all need this.
4. Separate discovery from acquisition; the current YouTube strategy split is direct evidence.
5. Treat Jellyfin and Plex as separate Broadcast contributions over a possible shared host output model; their current file policies are shared, their Connection protocols are not.
6. Make Connections first-class for reusable remote services, not for every secret.
7. Persist plugin state in PHP as bounded opaque data; YouTube and LaserDisc need continuation, while the spike forbids host-owned authoritative state.
8. Make unknown/partial preflight estimates valid results rather than blockers.
9. Use typed progress, cancellation, and layered errors; do not pass UI markup or flattened strings across the boundary.
10. Use manifest plus runtime contribution discovery; do not force optional capability discovery through a generic method dispatcher.
11. Keep the semantic model runtime-neutral even while WIT is the first implementation contract.

## 23. Open questions

- What production streaming shape replaces or refines `read(offset, maximum)` for very large Assets?
- Should host staging expose one sequential output, a workspace with named outputs, or both?
- Which media operations are sufficiently bounded and reusable to become host capabilities rather than plugin-local code?
- How should core represent an acquisition plan that a plugin describes but Stashd executes?
- What is the minimum stable candidate Item schema for non-video and physical sources?
- When does a local device/model/backend deserve a first-class Connection?
- How are opaque state size, encryption, version migration, and retention handled?
- How should partial discovery commit safely without losing or falsely advancing continuation state?
- What network policy language is expressive enough for YouTube/API/TTS without becoming a general firewall DSL?
- Which output/provenance facts are required before a staged plugin result can be considered for Vault promotion?
- How should future native/container runtimes prove equivalent containment and permission semantics?
- Should Broadcast external refresh be a contribution-defined operation or a core media-server service invoked from a plugin plan?

## 24. Explicit non-goals

This document does not implement or freeze:

- WIT interfaces or signatures;
- Rust, PHP, Vue, database schema, or runtime changes;
- plugin discovery implementation, catalog, packaging, OCI, or signing;
- plugin installation/configuration UI or JSON-Schema forms;
- arbitrary frontend, routes, navigation, HTML, or alternate UI frameworks;
- YouTube, Jellyfin, Plex, TTS, or LaserDisc conversion;
- enrichment/OCR API beyond the minimum Asset-read observation;
- generic workflow DSL, event bus, database/shell/filesystem access, or plugin-to-plugin RPC;
- multiple runtime implementations;
- a universal error enum or universal plugin context object.

## 25. Recommended next validation experiment

Implement one real vertical slice using the draft semantics, with no attempt to convert the existing provider wholesale: a **YouTube Input plugin adapter** is the highest-value first experiment.

It exercises optional source recognition, channel/playlist/video resolution, plugin-internal strategy choice, network policy, credential-use without raw secret exposure, paginated discovery, filters, candidate Item facts, opaque incremental state, preflight with partial estimates, progress/cancellation, provider errors, and the boundary between acquisition description and core-owned download/Vault ingest. It also has existing fixtures and fake seams, keeping validation cheap.

The experiment should stop at a narrow vertical path:

```text
manifest/runtime inspection
  → YouTube source resolution
  → fixture-backed discovery with opaque state
  → typed preflight/progress/errors
  → PHP-owned candidate commit
```

Do not begin with Jellyfin/Plex because their current shared series engine could hide the more important Asset/staging boundary. Do not begin with LaserDisc because native/hardware containment would add runtime questions before the semantic Input contract is tested. After YouTube, a tiny staged-output Broadcast or TTS-like fixture transformation should validate Asset reads, staging writes, and promotion boundaries.

## Verification record

Only this Markdown document was added. The archaeology inspected:

- `AGENTS.md` and every Markdown rule under `.claude/rules/`;
- [PLUGIN-SPIKE.md](PLUGIN-SPIKE.md);
- [Broadcast-Plugin-Architecture-Plan.md](Broadcast-Plugin-Architecture-Plan.md);
- provider, download, Stash Input, Vault, Broadcast, and media-server documentation;
- the YouTube Provider, discovery/metadata/download seams, Input/preflight/sync/commit flow, and ytdlphp download boundary;
- `JellyfinBroadcastPlugin`, `PlexBroadcastPlugin`, the shared series engine, Broadcast lifecycle/plan/output ownership, media-server Connections/clients, and trigger handling.

No PHP, Rust, WIT, Vue, database, schema, or runtime file was modified. No implementation test suite was run because the requested change is documentation-only; the final check is Markdown/diff inspection and terminology review.
