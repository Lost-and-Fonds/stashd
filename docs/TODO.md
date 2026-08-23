# Stashd implementation roadmap (foundation → v1)

## Code organization (complete)

- [x] Feature-first `app/` layout — see [docs/architecture/code-organization.md](architecture/code-organization.md)
- [x] Removed `app/Domain`, `app/Services`, `app/Infrastructure`, `app/Controllers`, `app/Bootstrap`
- [x] Consolidated broadcast + media-server command handlers; inlined broadcast lifecycle helpers
- [x] Renamed download/vault/stash/provider types (behavior, routes, API JSON unchanged)
- [x] Pest suite green after namespace migration (172 passed)

## Foundation (complete)

- [x] Tempest app boot + configuration
- [x] RoadRunner HTTP worker bridge
- [x] PostgreSQL foundation schema (storage, commands, jobs, settings)
- [x] Storage root checks with hardlink/symlink probing
- [x] Fake provider for tests/dev
- [x] `/health` endpoint
- [x] Docker Compose + smoke test skeleton

## Phase 1 — Core domain persistence

**Status:** complete.

- [x] Add full v1 domain schema migration (`2026_06_17_create_domain_schema`)
  - Tables: `stashes`, `stash_inputs`, `stash_items`, `media_items`, `media_item_sources`, `raw_metadata_snapshots`, `assets`, `broadcasts`, `broadcast_items`, `broadcast_triggers`, `broadcast_trigger_runs`, `provider_accounts`, `media_server_connections`, `users`, `api_tokens`, `secrets`
  - Prefixed ULID primary keys, camelCase PostgreSQL columns, explicit foreign keys, indexes/uniques (incl. `unique(providerKey, providerItemId)` and `unique(stashId, mediaItemId)`)
  - Records/enums for all domain tables; repositories for stash, stash input, stash item, media item, broadcast (+ existing command/job/storage repos)
  - Migration/constraint/repository tests (`tests/Feature/DomainSchemaTest.php`)
- [x] Minimal `stash.preflight` command (fake provider only — no YouTube, no UI)
  - `POST /api/v1/stashes/preflight`, `GET /api/v1/stashes/preflight/{commandId}/review`
  - Creates command + job, stores preflight payload for a later review UI
  - Covered by `tests/Feature/StashPreflightTest.php` (requires auth after owner setup)
- [x] Explicit state transition service for command/job/stash/stash-input/stash-item/media-item entities
  - `StateTransitionService` + enum `allowedTransitions()` / `canTransitionTo()` rules
  - Covered by `tests/Unit/Services/State/StateTransitionRulesTest.php` and `tests/Feature/StateTransitionServiceTest.php`
- [x] Secrets service wrapper around Tempest signing key
  - `SecretsService` encrypt/decrypt/revoke/redact; `tests/Feature/SecretsServiceTest.php`
- [x] Single-owner auth (`users`, session login, API tokens, middleware)
  - `POST /api/v1/auth/setup`, login/logout, token CRUD, `RequireAuthMiddleware`
  - Public: `/health`, setup, login; protected API returns `setup_required` or `authentication_required`
  - Covered by `tests/Feature/AuthTest.php`

## Phase 2 — Command + job execution

**Status:** complete (hardening pass done).

- [x] Generic commands API (`POST /api/v1/commands`, `GET /api/v1/commands/{id}`, `GET /api/v1/jobs`, `GET /api/v1/jobs/{id}`)
  - Auth-protected, snake_case JSON in/out, validation, async dispatch (no sync long-running work)
  - `GET /api/v1/commands/{id}` returns command state, jobs array, and `result` when available
  - `POST /api/v1/stashes/preflight` delegates to the same command dispatch path
- [x] Command dispatcher + handlers (`stash.preflight`, `system.storage_check`)
- [x] Job handler registry + worker (`preflight`, `storage_check`, `boot`)
  - Claim pending jobs, heartbeat, progress, `lastError`, retry-safe stall recovery (120s)
- [x] Async fake-provider preflight (results in `commands.resultJson`)
- [x] Scheduler routine discovery (`stashd:scheduler-tick`, due `stash_inputs` with automatic sync)
- [x] Activity events (command/job lifecycle, preflight/storage completion, failures)
- [x] SSE notification channel (`GET /api/v1/events` polls `event_notifications`; publisher for `job.*` + `activity.created`)
  - Unauthenticated requests rejected; payloads secret-redacted; notifications are ephemeral (not source of truth)
- [x] API token prefix `stashd_pat_…` (distinct from entity IDs)
- [x] RoadRunner-style auth isolation (middleware `finally` clears `AuthContext`; bearer-only when `Authorization` header present; bearer precedence over session; revoked bearer rejected even with session)
- [x] `#[AllowApiClients]` route decorator skips `PreventCrossSiteRequestsMiddleware` for machine/API clients (curl, tokens)
- [x] RoadRunner bridge: no per-request `kernel->shutdown()`; `HttpRequestFailed` returns intended 4xx/5xx bodies
- [x] Caveat: owner `setup` authenticates in-process but session cookie is not reliably reusable on the next RR request — clients should call `login` (or use a bearer token) after setup
- [x] Covered by `tests/Feature/Phase2ExecutionTest.php`, `tests/Feature/Phase2HardeningTest.php`, updated `StashPreflightTest.php`

## Phase 3A — Provider layer (fake provider, no YouTube)

**Status:** complete.

- [x] Provider fixtures for CI (`tests/fixtures/providers/fake/`, `ProviderFixtureTest`)
- [x] Provider strategy selection by job intent + cost (`ProviderStrategySelector`; preflight picks cheapest discovery strategy)
- [x] Full fake-provider preflight → review → commit flow
  - Preflight stores full `discovered_items` in `commands.resultJson`
  - `GET /api/v1/stashes/preflight/{commandId}/review` exposes items for commit UI
- [x] Command type `stash.create_from_preflight`
  - Creates `stash`, `stash_input`, `media_items`, `media_item_sources`, `stash_items` from preflight results
  - Deduplicates `media_items` by `(providerKey, providerItemId)` at commit time
  - No downloads, vault writes, broadcasts, or YouTube
- [x] Covered by `tests/Unit/Services/Provider/ProviderStrategySelectorTest.php`, `tests/Feature/StashCreateFromPreflightTest.php`

## Phase 3B — YouTube provider

**Status:** complete (discovery + metadata boundary only — no downloads).

- [x] YouTube URL resolution (`@handle`, `/channel/UC…`, playlist, watch, youtu.be)
- [x] RSS discovery strategy (`youtube.rss`) — no API key, fixture-driven CI tests
- [x] Optional Data API metadata strategy (`youtube.data_api`) — skipped without `YOUTUBE_DATA_API_KEY`
- [x] ytdlphp download adapter boundary (`YouTubeYtdlpDownloadStrategy` + placeholder; no Vault/temp writes)
- [x] Provider capability split (`DiscoveryStrategyHandler`, `MetadataStrategyHandler`, `DownloadStrategyHandler`)
- [x] `ProviderStrategySelector` filters unavailable/last-resort strategies
- [x] Typed provider domain boundaries (per engineering spec — raw strings at I/O edges only)
  - `StashdUri` wraps `Tempest\Support\Uri\Uri` (`parse()`, `fake()`, path/query helpers)
  - `ProviderDates` parses/constructs `Tempest\DateTime\DateTime` (`tryParse()`, `utc()`)
  - `YouTubeUris` centralizes watch/feed/oembed/Data API URL construction
  - `DiscoveredItem` / `ResolvedInput` hold `StashdUri` + `DateTime`; serializers/repos emit `toString()` / `toRfc3339(useZ: true)` for JSON/DB
  - String manipulation via `Tempest\Support\str()` (trim and path parsing) instead of raw PHP helpers
- [x] Wired into async `stash.preflight` + `stash.create_from_preflight` (media-item dedupe preserved)
- [x] Fixtures under `tests/fixtures/providers/youtube/`; no live YouTube in normal CI
- [x] Covered by `tests/Unit/Domain/Provider/YouTube/*`, `tests/Feature/YouTubeProviderTest.php`
- [x] Provider docs: `docs/providers/README.md` (includes typed-boundary conventions)
- [ ] Live provider tests behind `STASHD_LIVE_PROVIDER_TESTS=1` (optional)

## Phase 4A — Vault + downloads (fake downloader)

**Status:** complete + hardening pass (fake downloader only — no real ytdlphp).

- [x] `DownloaderInterface` + `DownloadRequest` / `DownloadResult` / `DownloadProbeResult`
- [x] `FakeDownloader` writes deterministic tiny files to temp (no shell/process calls)
- [x] `item.download` command + async download job (heartbeat, progress, activity, SSE)
- [x] Temp staging under `/media/temp/downloads/{jobId}` before any Vault write
- [x] Vault layout `{providerKey}/items/{providerItemId}/…` via `VaultPathBuilder` + `PathSanitizer`
- [x] `AtomicFileMover` (rename or copy+fsync); refuses to overwrite ready Vault originals
- [x] Asset rows for vault original, metadata JSON, source JSON, optional thumbnail
- [x] Media item state transitions through `StateTransitionService` (incl. `missing`)
- [x] Download policy enforcement (`metadata_only`, `manual_download`, `video`, `audio_only`)
- [x] Drift detection: `system.verify_vault`, `asset.verify` (missing vs checksum mismatch vs root unavailable)
- [x] API: `GET /api/v1/items/{id}`, `GET /api/v1/items/{id}/assets` (snake_case JSON)
- [x] Hardening: idempotency/retry rules, `force` → `download_force_not_supported`, batch ingest rollback, SHA-256 checksums, Vault sidecar JSON (`VaultSidecarBuilder`), path safety guards
- [x] Covered by `tests/Feature/ItemDownloadTest.php`, `tests/Feature/Phase4AHardeningTest.php`, `tests/Unit/Services/Download/*`, `tests/Unit/Services/Storage/PathSanitizerTest.php`, `tests/Unit/Services/Vault/*`
- [x] Docs: `docs/storage/README.md`, provider/downloader notes in `docs/providers/README.md`
- [x] Docker smoke: preflight → create-from-preflight → item.download → Vault file + asset ready

**Phase 4B gate:** do not wire real ytdlphp until 4A hardening is green.

## Phase 4B — Real ytdlphp downloads

**Status:** complete.

- [x] `composer require hazel/ytdlphp` ([hipsterjazzbo/ytdlphp](https://github.com/hipsterjazzbo/ytdlphp))
- [x] `YtdlpConfig` + env: `STASHD_YTDLP_BINARY`, `STASHD_YTDLP_TIMEOUT`, `STASHD_REAL_DOWNLOADS_ENABLED`
- [x] `YtdlpGateway` / `YtdlpGatewayImpl` — only Stashd boundary to ytdlphp (no direct process calls)
- [x] `StubYtdlpGateway` for tests/CI (no network)
- [x] `YtdlpDownloader` + `YtdlpOptionsBuilder` (video 1080p merge, audio mp3 128k)
- [x] `DelegatingDownloader` — fake provider → `FakeDownloader`; others → ytdlphp when enabled
- [x] `YouTubeYtdlpDownloadStrategy` for YouTube provider strategy registry
- [x] Phase 4A pipeline unchanged: temp → batch ingest → checksum → Vault → sidecars
- [x] Tests: `tests/Unit/Services/Download/YtdlpDownloaderTest.php`, `tests/Feature/YtdlpDownloadTest.php`
- [x] Opt-in live probe test: `STASHD_LIVE_DOWNLOAD_TESTS=1` (`tests/Feature/LiveYtdlpDownloadTest.php`)
- [x] Docker: yt-dlp + PHP `uri` extension; the bundled Podcast plugin packages its own FFmpeg helper
- [x] Docker build: PHP 8.5 bundled `uri` extension verified without `docker-php-ext-install uri`
- [x] Docs updated (`docs/providers/README.md`, `docs/storage/README.md`)

## Phase 5 — Broadcasts

### Phase 5A — Broadcast engine + hardlink-first filesystem publishing (complete)

- [x] `BroadcastFormat` interface + `BroadcastTypeRegistry`
- [x] Lifecycle service: `BroadcastLifecycleService` (plan/publish/verify/prune inlined)
- [x] `filesystem_series` broadcast type (hardlinked media under `/media/broadcasts/{broadcastId}/`)
- [x] Hardlink-first publish via `HardlinkPublisher` — no silent copy; `broadcast_hardlink_unavailable` on failure
- [x] Async commands: `broadcast.plan`, `broadcast.rebuild`, `broadcast.verify`, `broadcast.prune`
- [x] API: stash/broadcast list/create/show/items + commands via `POST /api/v1/commands`
- [x] Activity/SSE events for broadcast lifecycle
- [x] State transitions via `StateTransitionService` (broadcast + broadcast item)
- [x] Tests: `tests/Feature/Phase5ABroadcastTest.php`, `tests/Unit/Services/Broadcast/BroadcastEngineTest.php`
- [x] Docker smoke: create broadcast → rebuild → hardlink exists → restart → verify
- [x] Docs: `docs/broadcasts/README.md`, storage hardlink policy notes

### Phase 5B — Jellyfin/Plex filesystem broadcasts + scan triggers (complete)

- [x] `jellyfin_series` and `plex_series` broadcast types (shared series engine, distinct type keys)
- [x] Media-server-friendly layout (`Season 01/`, `S01E001 - Title.ext`) + minimal NFO sidecars
- [x] Hardlink-first publish unchanged; optional poster hardlink when safe
- [x] `media_server_connections` CRUD API + token storage via `SecretsService` (`token_secret_id` → `secrets.id`)
- [x] External Jellyfin Component HTTP integration; Plex remains the transitional built-in client until its migration
- [x] Commands: `broadcast.trigger`, `media_server.test_connection`, `media_server.list_libraries`
- [x] Scan triggers separate from broadcast validity (`broadcast_trigger_runs`; failures do not invalidate files)
- [x] Activity/SSE: `broadcast.trigger_succeeded`, `broadcast.trigger_failed`, `media_server.test_completed`
- [x] Tests: `tests/Feature/Phase5BMediaServerTest.php`, fixture HTTP clients under `tests/fixtures/media_servers/http/`
- [x] Docker smoke: `jellyfin_series` rebuild + NFO sidecar check (fake — no real Jellyfin/Plex containers)
- [x] Docs: `docs/broadcasts/README.md`, `docs/media-servers/README.md`
- [ ] Optional live media-server tests: `STASHD_LIVE_MEDIA_SERVER_TESTS=1`

### Phase 5C — Private podcast broadcasts (historical; replaced by external Component)

- [x] Podcast feed and item token foundation with encrypted storage
- [x] Authenticated podcast feed URL exposure
- [x] `broadcast.rotate_token` for podcast broadcasts
- [x] `audio_podcast` and `video_podcast` broadcast format registration
- [x] Deterministic minimal RSS podcast feed generation to `/media/broadcasts/{broadcastId}/feed.xml`
- [x] Tokenized enclosure URL shape (`/b/{broadcastToken}/items/{itemToken}/episode.{ext}`) in generated feeds
- [x] Audio/video podcast asset selection from ready Vault assets with stable unavailable errors
- [x] Public `GET /b/{broadcastToken}/feed.xml` route
  - Unauthenticated by design (route opts out of `RequireAuthMiddleware`); anyone with the feed URL can read the feed
  - Resolves the raw feed token to its podcast broadcast via `PodcastTokenService::findPodcastBroadcastByFeedToken` (decrypt candidates, `hash_equals`)
  - Serves the generated `feed.xml`; never rebuilds synchronously
  - Non-revealing 404 for unknown/revoked/rotated-old tokens, non-podcast broadcasts, and missing feeds
  - Token rotation (`broadcast.rotate_token`) invalidates old feed URLs
  - Covered by `tests/Feature/Phase5CPodcastFeedRouteTest.php`
- [x] Public tokenized episode media route
  - `GET /b/{broadcastToken}/items/{itemToken}/episode.{ext}` (unauthenticated by design, same convention as the feed route)
  - Resolves the broadcast token via `PodcastTokenService::findPodcastBroadcastByFeedToken`, then the item token via the new `PodcastTokenService::findBroadcastItemByEpisodeToken` (decrypt candidates scoped to that broadcast's items, `hash_equals`)
  - Serves the Vault asset already selected by `PodcastAssetSelector` (audio for `audio_podcast`, video for `video_podcast`) — never an arbitrary filesystem path, never transcoded/remuxed
  - `{ext}` is a presentation hint only; a mismatch against the selected asset's own extension is a non-revealing 404
  - Non-revealing 404 for unknown/cross-broadcast/revoked tokens, non-podcast broadcasts, unavailable/unreadable assets, and extension mismatches
  - Covered by `tests/Feature/Phase5CPodcastEpisodeRouteTest.php`
- [x] Range request support for episode media
  - Single-range `Range: bytes=...` requests only (`N-M`, `N-`, `-N` suffix forms); multi-range and syntactically invalid ranges are treated as absent (full file, 200)
  - Parsed/validated by `PodcastEpisodeByteRange` (pure value object, no I/O); satisfiable ranges are read via chunked `fopen`/`fseek`/`fread`, never a full `file_get_contents()`
  - Satisfiable range → `206 Partial Content` with `Content-Range`/`Content-Length`; unsatisfiable (start beyond EOF) → `416 Range Not Satisfiable` with `Content-Range: bytes */{total}`
  - No-`Range` requests now also advertise `Accept-Ranges: bytes`
  - No resumable/`multipart/byteranges` streaming — out of scope for this slice
  - Covered by `tests/Feature/Phase5CPodcastEpisodeRouteTest.php`
- [x] Conservative description-based podcast funding-link detection
  - `PodcastFundingLinkDetector` scans included episode/media item descriptions for Patreon, Ko-fi, GitHub Sponsors, and Buy Me a Coffee links only
  - Manual `settings['funding_url']` always wins; detection only runs when it is absent/blank
  - Only descriptions of items actually included in the rebuilt feed are scanned (hidden/failed/excluded items are not)
  - Covered by `tests/Unit/Broadcasts/Podcasts/PodcastFundingLinkDetectorTest.php` and `tests/Feature/Phase5CPodcastFeedTest.php`
  - Deferred: channel/about-page scraping, YouTube membership, Nebula, creator-website, merch-store, and Substack/Open Collective detection, and the "first plausible link" fallback from the engineering spec's full priority list
- [x] Unified `audio_podcast`/`video_podcast` into a single `BroadcastType::Podcast` with a per-broadcast `settings.media_kind` (`audio`|`video`, defaults to audio) — `AudioPodcastBroadcast`/`VideoPodcastBroadcast` (two ~25-line subclasses differing only in which selector method/error code they used) deleted; `PodcastBroadcastFormat` is now the sole concrete implementation. Every downstream `in_array($type, [AudioPodcast, VideoPodcast])`/`type = ? OR type = ?` collapsed to one check. No DB migration — no existing users/data.
- [x] Audio derived assets are owned by the external Podcast Component. The Component requests a package-local plugin helper and returns a staged artifact; core validates and promotes the result as a generic derived Asset. The old PHP transcoder/fallback was intentionally removed.
- [ ] Video transcode/remux broadcast policies (still deferred — separate future initiative, a v1 non-goal; requires explicit sign-off per `AGENTS.md`'s "ask before enabling copies/transcoding by default")
  - `stashes.videoQualityProfileId`/`audioQualityProfileId` columns exist but are unused — only meaningful once this work exists
  - `BroadcastPlan::estimatedCopyBytes` stays hardcoded at `0` until generated-media (podcast audio/video) work exists; `0` is already correct for hardlink-only broadcast types in the meantime

### Phase 5D — Broadcast plugin architecture migration (complete, in-tree; Composer packaging deferred)

Full design/status doc: `docs/architecture/Broadcast-Plugin-Architecture-Plan.md`.

- [x] `BroadcastType` enum, `BroadcastTypeRegistry`, `BroadcastFormat` interface, `AbstractSeriesBroadcastType`, `app/Broadcasts/Formats/` deleted; replaced by `BroadcastPlugin` interface + `#[StashdBroadcast]` attribute discovery (`BroadcastPluginDiscoverer` → `BroadcastPluginRegistry`) — broadcast keys are now plain strings (`filesystem`, `jellyfin`, `plex`, `podcast`), not enum cases
  - Plugins stay in-tree under `app/Broadcasts/Plugins/` (`AbstractSeriesBroadcastPlugin` + `Filesystem`/`Jellyfin`/`Plex`/`PodcastBroadcastPlugin`) — the plan's `stashd/plugin-podcast`/`stashd/plugin-media-server` Composer packages are deferred, not scheduled, per `AGENTS.md`'s "v1 ships no plugin runtime"
- [x] `GET /api/v1/broadcast-plugins` — lists discovered plugins (key/label/description/supported_file_kinds/ui_controls); the stash-detail create-broadcast dropdown and the season-mapping series check (`BroadcastController::isSeriesBroadcast()`) both now derive from this instead of a hardcoded type list
- [x] Fixed the migration's incomplete follow-through, caught via full-suite regression (98 → 0 failures across several sessions): stale `filesystem_series`/`jellyfin_series`/`plex_series` keys left in the frontend and three test files after the key rename; `BroadcastLifecycleService`'s six lifecycle methods retyped to `string` without updating their only callers (still `PrefixedUlid`); `BroadcastTriggerService::execute()` similarly mismatched; `AssetRepository`/`BroadcastItemRepository` left strictly `PrefixedUlid`-typed while the new plugin code calls them with plain strings; a missing `PrefixedUlid` import in `BroadcastContextFactory`; and stray `->toString()` calls on already-string values in `AbstractSeriesBroadcastPlugin`
- [x] Deleted the gutted `BroadcastTypeTest.php` stub (`expect(true)->toBeTrue()` placeholders); the policy matrix it once covered is fully exercised by `BroadcastPolicyMismatchTest`

## Phase 6 — API + UI

**Status:** complete. Slices 1-6 shipped (toolchain/shell, auth, Dashboard/Activity,
Stashes/Vault, Create Stash + Settings, and the Slice 6 refinement/multi-input pass below).

### Slice 1 — Front-end toolchain + dashboard shell (complete — `50158bb`)

- [x] Vite + Tailwind v4 + Alpine.js toolchain (`package.json`, `vite.config.ts`, `src/main.entrypoint.{ts,css}`)
- [x] `.rr.yaml` `http.static` serving `public/`; `num_workers` 2 → 4 for SSE headroom (since raised again, see below)
- [x] Dockerfile Node build stage producing `public/build/`
- [x] `app/Http/Ui/Views/x-stashd-layout.view.php` — wordmark, top nav, inline favicon tile

### Slice 2 — Auth: login page + session cookie + logout (complete — `24105cb`)

- [x] `__web_session__` rotating token + `stashd_session` HttpOnly cookie (`app/Auth/AuthService.php`, `AuthController.php`)
- [x] Dropped the fallback to Tempest's native Authenticator/Session — it's a container singleton living for the life of a RoadRunner worker, so one user's session was leaking into every other request that worker served afterward
- [x] `app/Http/Ui/UiController.php` + view shells for every nav destination, all opting out of `RequireAuthMiddleware` via `without:`

### Slice 3 — Dashboard + Activity (read-only, live) (complete — `2dcebbd`)

- [x] Dashboard (`GET /`): status cards from `GET /api/v1/system/health`, jobs, stash/vault counts
- [x] Activity (`GET /activity`): live feed from `GET /api/v1/events` (SSE) via Alpine + native `EventSource`
- [x] Shared `apiFetch()` / status-badge / formatter helpers in `src/main.entrypoint.ts`

### Slice 4 — Stashes + Vault (read + drill-down) (complete — `03b6265`)

- [x] `GET /api/v1/stashes`, `/{id}`, `/{id}/items`, `/{id}/inputs`, `GET /api/v1/items` (closed a documented spec §27 gap — these endpoints didn't exist before this slice)
- [x] Stash detail: inputs/items/broadcasts, rebuild/verify/prune/rotate_token actions, copyable podcast feed URL
- [x] Vault detail: assets list; `Asset.derivedFromAssetId` exposed as an honest partial "Explain Generated Files" (see below — `canRegenerate`/`safeToDelete` still not modelled)

### Slice 5 — Create Stash flow + Settings + broadcast creation (complete)

- [x] Create Stash (`GET /stashes/new`): paste-URL → `stash.preflight` → review (item count, total duration) → configure (name, download policy prominent; sync mode/organization mode/description under a collapsed "advanced options" disclosure) → `stash.create_from_preflight` → redirect to the new stash. No disk-size/impact-warning estimates — backend doesn't compute them yet (spec-only).
- [x] Settings (`GET /settings`): API tokens (create-once display, list, revoke), media-server connections (create/list/test/delete), and read-only system/storage info, all against already-existing endpoints — no backend changes needed.
- [x] Broadcast creation: Stash detail gained a minimal "new broadcast" form (type + name) — there was previously no UI anywhere to create one, only to act on existing ones. Found during this slice's planning, not in the original scope.

### Slice 6 – Refinement, correctness bugs & multi-input reframe (complete)

Full task breakdown (T1-T20) in `docs/plans/phase-6-slice-6/plan.md`; `docs/plans/phase-6-slice-6/tasks/` has one prompt per task.

- [x] Stashes need an edit page — `PATCH`/`DELETE /api/v1/stashes/{id}` + a delete-impact preview (shared vs. orphaned items); Edit/Delete UI on both the stash list and the detail header rather than a dedicated page (T4, `8fbbcba`)
- [x] Detailed item list + live processing status — items endpoint returns each item's media-item summary (thumbnail, title, duration, content type) and on-disk asset size in one call; ignored items shown distinctly with their filter reason; an actively downloading item shows a live progress bar (T11, `67a4aa1`)
- [x] Broadcast/metadata-only mismatch — generalized to `BroadcastType::isSatisfiedByDownloadPolicy()` (`metadata_only` satisfies no media broadcast; `audio_only` doesn't satisfy `video_podcast`); warns and offers an inline policy picker, never blocks (T10, `31c8c92`)
- [x] YouTube 15-item cap — `YouTubeDataApiDiscoveryStrategy` pages the Data API for the full channel once a key is configured, tagging each item's video type (regular/short/live/premiere); RSS behaviour unchanged without a key (T5, `10b06e8`)
  - [x] Settings section for the YouTube API key — `SecretsService`-backed with an env fallback; set/replace without a restart; key never echoed back (T6, `10b06e8`)
- [x] Activity page closing payload disclosures on refresh — `activityComponent` now tracks expanded-event ids explicitly instead of relying on `<details>`'s native open state, which the wholesale SSE re-render was discarding (T15, `747318e`)
- [x] Stash name defaults to channel/playlist name — channel-handle resolution returns the real owner's display name (same fix as the wrong-channel bug below) (T2, `8fbbcba`)
- [x] Slug ordinal-suffix fallback — duplicate slugs now yield `foo`, `foo-1`, `foo-2`, … instead of erroring (T3, `8fbbcba`)
- [x] Animated background-activity indicator — one shared pulse-dot class applied everywhere something is happening now (loading states, in-flight buttons, active job rows, SSE "connecting…") (T14, `e0eaf78`)
- [x] Downloads not firing on stash creation — eligible stash items now auto-enqueue `item.download` at the end of input-commit when the stash's download policy allows it (T1, `8fbbcba`)
- [x] No way to delete a stash — see edit/delete above (T4, `8fbbcba`)
- [x] Channel avatar as stash icon — `icon_uri` is populated from the resolved input's avatar at commit and rendered in the stashes list and detail header (T16, `e0eaf78`)
- [x] Dashboard refinement — Recent Jobs table removed; new `GET /api/v1/activity`-backed media-activity summary; per-location + total disk usage surfaced; real Stashes & Vault counts (T13, `67bf001`)
- [x] Multi-input stash workflow — atomic creation split into `POST /stashes` (empty) + `POST /stashes/{id}/inputs` (add input); `stash.create_from_preflight` retired in favour of a stash-agnostic preflight + per-stash commit; a stash can now hold inputs from multiple sources with dedup preserved (T8/T9, `68024de`/`eae9171`); optional input → broadcast season mapping lives in `BroadcastRecord.settingsJson` (T20, `ca11dd7`)
- [x] Vault/media-item detail page was sparse — added thumbnail, full metadata, "in these stashes"/"in these broadcasts" membership (new `GET /api/v1/items/{id}/stashes` + `/broadcasts`), download/processing status (T12, `613475b`)
- [x] HUGE ISSUE: wrong-channel discovery — `YouTubeChannelIdResolver` now resolves the owner id via canonical link / `og:url` / header `externalId` only, with no first-`channelId` fallback; better to show no identity than a wrong one (T2, `8fbbcba`)
- [x] Create-stash flow missing shorts/live-vod toggle — two filter tiers, both per-input: a universal title-regex include/exclude, plus provider-declared options (YouTube channels: `include_shorts`/`include_live`); excluded items land in `ignored` state with a specific reason, retained rather than deleted (T7, `e0286ab`)
- [x] OpenAPI-documented `/api/v1` resources — `docs/openapi.yaml`, written from actual `Resource::toArray()` output; a Pest test asserts every discovered route has a matching `paths` entry so it can't silently drift (T17, `e60d2ee`)
- [x] True low-latency SSE streaming over RoadRunner — `GeneratorEventStream` adapts the poll-loop generator to a PSR-7 `StreamInterface` (`PSR7Worker::chunkSize`), so events deliver incrementally instead of bursting after `MAX_ITERATIONS` (T18, `18f6daa`)
  - **Worker-pinning itself is not eliminated.** RoadRunner is one-process-one-request, so streaming changes *when* bytes reach the client, not *whether the worker is free* for the duration — that part of the original acceptance bar was never achievable via streaming alone. The open-tab pool-exhaustion problem below is instead now *bounded*: a `sse_connections` table caps concurrent `/api/v1/events` connections at 4 of 8 workers; rejected connections get a `retry-after` SSE message and `EventSource`'s own reconnect handles the rest.
  - **Known sharp edge, hit twice (superseded by the connection cap above):** because the worker is held for the whole loop regardless of client disconnects (confirmed via a logged `broken pipe` at `elapsed: 30148ms`), every page that subscribes to `/api/v1/events` tied up one RoadRunner worker for ~`MAX_ITERATIONS` seconds out of every `MAX_ITERATIONS + ~3`s reconnect cycle, for as long as that page stayed open. `.rr.yaml`'s `pool.num_workers` was bumped once (2 → 4) for this; Slice 4 adding a third SSE-subscribing page (Stash detail) used up that headroom, and with all workers busy, new page navigations (including the auth check) had no worker free to run on — intermittently bouncing users to `/login` in a way that looked like a recurrence of an old database-lock bug (`b841ea7`) but was actually worker-pool exhaustion, a different mechanism with the same symptom. Mitigated 2026-06-20 by dropping `MAX_ITERATIONS` 30 → 10 and raising `num_workers` 4 → 8; the hard cap above is the real fix for the exhaustion scenario.
  - Slice 5's bounded `awaitSseTerminal` consumer (Create Stash wizard) was unaffected either way — a short, explicitly-`.close()`d subscription, not a perpetual one.
- [x] "Explain Generated Files" asset metadata — `AssetRegenerationGuidance` classifies assets as source vs. generated from the existing `broadcastId`/`derivedFromAssetId` columns (no new schema); `canRegenerate`/`safeToDelete`/`generatedBy` are now modelled and shown in the Vault item detail UI (T19, `35d88f4`)
- [x] Stale branding doc pointer — fixed 2026-06-20: `AGENTS.md` and `x-stashd-layout.view.php`'s doc comment both already named the right canonical path (`docs/Stashd-Branding-Plan.md`); the staleness was in that file's *content*, which still described pre-rebrand terminology (Mirror/Collection/Destination — explicitly disavowed by the current direction). Promoted the current content from `docs/stashd-design-assets-phase6/docs/Stashd-Branding-Plan-2026-06-16.md` into the canonical path; the dated copy and its asset bundle are left untouched as the historical design-handoff record (per `ASSET-MANIFEST.md`). Carried over from the Slice 1 plan's sign-off list.

## Phase 7 — Release hardening

- [x] Docker smoke: boot, port 8474, `/health`, storage layout, restart persistence (`tests/docker/smoke.sh`)
- [x] Docker smoke: Phase 2 schema tables (`activity_events`, `event_notifications`) on fresh boot
- [x] Docker smoke: supervisord worker + scheduler + roadrunner process health
- [x] Docker smoke: authenticated `/api/v1/system/health` with `vault_broadcast_hardlink` on bind mounts
- [x] Pest suite green (172 tests: … Phase 5B Jellyfin/Plex broadcasts + scan triggers)
- [x] Docker smoke: fake-provider preflight → create-from-preflight end-to-end
- [x] Docker smoke: fake-provider download → temp → Vault → asset ready (+ restart persistence)
- [x] Docker smoke: filesystem broadcast create → rebuild → verify after restart
- [x] Docker smoke: jellyfin_series broadcast rebuild + tvshow.nfo sidecar (fake provider, no live Jellyfin)
- [x] Docker smoke: audio_podcast rebuild + public feed.xml + public episode route (full fetch, `Range` request → 206, unknown item token → 404)
- [x] Docker smoke docs: first-run and no-build reuse workflow (`docs/runtime/docker-smoke.md`)
- [x] Docker entrypoint: auto-generate and persist `SIGNING_KEY` under `/data/.env` on first boot, symlinked into the app root (`docker/entrypoint.sh` `ensure_signing_key`); operator-supplied `SIGNING_KEY` env var takes precedence and skips generation; verified unchanged across restart, full container recreation, and the override path (`tests/docker/smoke.sh`)
- [x] Multi-arch image build — turned into a bigger fix than the name suggests:
  - **`Dockerfile`**: the `yt-dlp` release asset it downloaded (`.../releases/latest/download/yt-dlp`)
    is an amd64-only PyInstaller build; an arm64 image would have shipped a yt-dlp binary that
    can't execute at all. Now selects `yt-dlp` vs `yt-dlp_linux_aarch64` off the auto-populated
    `TARGETARCH` build arg.
  - **Bigger blocker found while verifying the fix**: `composer.json` pinned `hazel/ytdlphp` as a
    `path` repository at `/home/hazel/Projects/ytdlphp` — a sibling directory that only exists on
    one machine. This meant `docker build` (any platform, not just arm64) had never actually
    succeeded outside that machine, including in the `composer test:docker-smoke` release gate.
    Fixed at the source: pushed `hipsterjazzbo/ytdlphp`'s unpushed commits, tagged `v1.0.2`, and
    switched `composer.json` to a `vcs` repository (`hazel/ytdlphp: ^1.0.2`) resolved over git —
    works from any machine/CI runner now. `composer.lock` regenerated accordingly.
  - **Second latent bug found once the image finally built and booted for real**: `tests/docker/smoke.sh`
    POSTs `{"type":"jellyfin_series",...}` for its NFO/SxxExxx-naming assertions, but the
    broadcast-plugin-architecture migration (`6d5607c`) unified that under the single key
    `"jellyfin"` (now served by the external Broadcast Component) — the smoke test was never updated
    and has been failing at that step ever since, just never noticed because the build never got
    that far before. Fixed the stale type string.
  - **New**: `.github/workflows/docker-image.yml` — first CI in the repo. PRs build both
    `linux/amd64`/`linux/arm64` targets (no push, no registry creds needed) as an ongoing
    regression check against exactly the kind of silent breakage found above; pushes to `main`
    publish an `edge` tag, `v*` tags publish versioned + `latest` tags, all to
    `ghcr.io/stashd/stashd` per `docs/Stashd-Engineering-Specification.md`'s registry/tag scheme.
  - **Verified**: amd64 build + the full `tests/docker/smoke.sh` suite pass end-to-end locally
    (first time, from a truly clean checkout). **Not verified locally**: the arm64 build leg —
    this sandbox's Docker has no QEMU/binfmt emulation registered, and installing it requires a
    privileged container the sandbox correctly declined to run unprompted. Correctness there rests
    on code inspection (the `TARGETARCH` case statement, official multi-arch base images) plus the
    new CI workflow, which runs on a GitHub-hosted runner with proper QEMU setup.
- [x] Static analysis — PHPStan `level: max` against `app/` (`phpstan.neon`, `composer test:static`); 435 pre-existing findings baselined (`phpstan-baseline.neon`) rather than fixed inline — mostly `mixed`-typed returns from the ORM's generic `select()`/`first()`/`all()` (no Tempest-specific PHPStan extension exists to type these properly) plus untyped `array` options params on command/job handlers. New code must pass clean; the baseline is for pre-existing debt, not an escape hatch.
- [x] Filesystem integration tests — audited the four named gaps against real code, not assumption:
  - **Cross-device hardlink-to-copy fallback**: real gap, now covered.
    `MoveFileIntoVaultTest.php` (new — the class had zero dedicated tests before this,
    only indirect exercise via the download→vault Feature flow) adds a happy-path rename,
    refuse-to-overwrite, missing-directory-creation, and a genuine `EXDEV` rename failure
    via `/dev/shm` vs `sys_get_temp_dir()` (two real devices, not mocked) proving
    `MoveFileIntoVault`'s `rename()`→`copy()+fsync()+unlink()` fallback actually fires.
    Self-skips with a clear reason if the environment doesn't offer two devices. Note:
    `HardlinkPublisher` (Vault→Broadcast) has no copy fallback by design — it fails loudly
    with `broadcast_hardlink_unavailable` instead (`docs/storage/README.md`) — so this gap
    only applied to `MoveFileIntoVault` (staging→Vault), not the Broadcast side.
  - **Permission-denied**: real gap, now covered where the environment allows it.
    `FilesystemProbeTest.php` and `BootstrapAndHealthTest.php` each add a `chmod(0o555)`
    unwritable-directory case, asserting `storage_root_unwritable`/`StorageLocationState::Unwritable`
    instead of the happy-path-only coverage that existed before. Both self-skip with a clear
    reason when running as a user that bypasses permission bits (root — true for local/Docker
    dev today), rather than asserting something that isn't actually being tested.
  - **Disk-full**: not covered, deliberately. No portable way to simulate `ENOSPC` without a
    loop-mounted filesystem requiring privileges CI/dev containers don't reliably have; the
    existing `LowSpace` detector (`StorageCapabilityChecker`) reads real `disk_free_space`/
    `disk_total_space` ratios with no injectable seam. Not worth building one for a threshold
    check this simple — revisit only if a real incident makes it worth the infrastructure.
  - **Concurrent access**: not covered, deliberately — not a real scenario. The job worker is
    a single serial process by design (confirmed in the backfill-resilience work); there is no
    code path today where two processes write the same file at once. A synthetic concurrency
    test would test a scenario the app doesn't have, not a gap in the app.
- [x] Tech debt: unused `JobIntent`/`CommandType` enum cases — `RoutineDiscovery`, `MetadataCapture`, `MetadataRefresh`, `Repair` (`JobIntent`) and `StashSync`, `StashBackfill`, `ItemRefreshMetadata`, `SystemPruneTemp` (`CommandType`) had zero references outside their own enum declarations; deleted. `JobIntent::Enrich` looked equally dead but stays deliberately — `Phase2ExecutionTest` uses it as the fixture intent with no registered job handler, to prove the worker fails a job cleanly rather than silently dropping it; now commented in `JobIntent.php` so it doesn't get flagged as dead code again. `JobIntent::InitialBackfill` was already wired up (Phase 6 Slice 6, `eae9171`), not part of this cleanup.
- [x] Tech debt: `RawMetadataSnapshotRecord` table/record exists but is never instantiated — scaffolding for a not-yet-scoped provenance feature. Confirmed zero call sites; its `MetadataSnapshotType` cases (`MetadataCapture`/`MetadataRefresh`) mirror the `JobIntent` cases already deleted as dead code above, so no feature was ever going to write to it. Deleted `RawMetadataSnapshotRecord`; dropped the table via a new forward migration (`DropRawMetadataSnapshots`, mirrors `DropUserUsername`'s pattern) rather than editing the already-shipped `CreateDomainSchema` migration. `MetadataSnapshotType` enum stays — `CreateDomainSchema`'s `snapshotType` column still references it by class, so deleting it would break replaying migration history on a fresh database; commented to explain why it's not dead despite having no readers.
- [ ] Max concurrent downloads is not explicitly enforced in code — naturally satisfied today by the single serial `worker` process; revisit if workers are ever scaled beyond one.
- [x] Tech debt: no full-channel discovery fallback when no YouTube Data API key is configured —
  added `YouTubeYtdlpDiscoveryStrategy` (`app/Providers/YouTube/YouTubeYtdlpDiscoveryStrategy.php`,
  key `youtube.ytdlp_discovery`), registered in `YouTubeProvider::discoveryStrategies()` at
  `StrategyCost::Medium`/`priority: 20` — deliberately tied in cost with Data API (`priority: 10`)
  rather than adding a new `StrategyCost` enum case between `Low` and `Medium`; the selector's existing
  cost-then-priority tie-break already gives the exact wanted ordering (Data API wins ties when both
  are available, RSS still wins every non-`preferHighestCapability` pick since it stays `Low`) without
  touching a cross-provider enum. Availability gates identically to
  `YouTubeYtdlpDownloadStrategy::isAvailable()` (`realDownloadsEnabled() && probe()->available`) — not
  purely on binary presence — so it respects the same real-network kill switch as downloads and stays
  off by default in tests/CI. Uses `YtdlpGateway::extractPlaylist()` (new method, `-J` single-JSON
  playlist dump) with `--flat-playlist` (new `YtdlpOptionsBuilder::playlistOptions()`, also carries the
  cookies/`--sleep-requests` resilience flags from the backfill-hardening pass) rather than
  `extractAll()` — `extractAll()` resolves full metadata per video, which for a large channel would be
  slow and exactly the rate-limit/bot-detection risk the resilience pass was hardening against; flat
  listing is one process call regardless of channel size, at the cost of only id/title/duration/
  thumbnail per entry (full metadata still comes later via the per-video download path). New
  `YouTubeUris::channelVideosPage()`/`playlistPage()` builders for the URLs yt-dlp needs (feed/API
  builders weren't reusable — yt-dlp needs the actual page URL, not a feed URL).

### Worker/runtime reliability follow-ups (out of scope of the worker-lanes slice)

- [ ] ytdlphp timeout semantics — `YtDlp`'s `timeout` is **total runtime** per invocation
  (`vendor/hazel/ytdlphp/src/YtDlp.php`), and `YtdlpGatewayImpl` passes
  `STASHD_YTDLP_TIMEOUT` (default 600s) to every call, including `download()`. Any single
  download taking longer than 10 minutes (big file, slow NAS uplink) is killed mid-transfer
  today even while making healthy progress. Fix in ytdlphp (idle/no-output timeout instead
  of total), or give download calls a separate, much larger timeout than probe/extract calls.
- [ ] `PodcastFeedController` buffers the whole feed XML via `file_get_contents($feedPath)`
  before responding — same unbounded-buffering shape as the fixed episode-serving OOM crash
  (RoadRunner workers die at `max_worker_memory` 128MB). Feed XML is text so it needs a very
  large item count to hurt, but it should stream through `GeneratorFileStream` like episode
  bodies do.
- [ ] Orphaned yt-dlp grandchildren — when stale-job recovery hard-kills a hung worker tick
  (dead-NFS case), a yt-dlp child of that tick is orphaned rather than killed. It exits on
  its own within its ytdlphp timeout (≤600s) and only writes to staging temp, so this is
  accepted for now; revisit with process-group kills if it ever bites.

## Phase 8 — Stronger typing & Tempest-native refactor (ongoing, not gating v1)

Driven by AGENTS.md's "use Tempest-native facilities by default" preference. Full ready-to-run
prompts for each unstarted slice below live in `docs/plans/stronger-record-types.md`; the relations
spike is `docs/plans/tempest-relations-review.md`. Both docs stay as deep reference — this section is
the live status tracker, so "what's left" never again needs a tour of `docs/plans/`.

- [x] Timestamp properties → `Tempest\DateTime\DateTime` — `RecordTimestamps` removed entirely;
  callers (auth expiry, NFO dates, job stale checks, scheduler `nextCheckAt`, podcast feed dates)
  converted off `strtotime()`/`substr()`/`gmdate()` arithmetic (`379f607`)
- [x] Stable JSON properties (first batch) — `ApiTokenRecord::$scopesJson` → `ApiTokenScopes`
  (`a1c16c7`); `MediaServerConnectionRecord::$settingsJson` → `MediaServerLibrarySelection`
  (`1591102`); `BroadcastTriggerRecord::$settingsJson` → `MediaServerScanTriggerSettings`
  (`36980eb`)
  - Intentionally deferred, not outstanding (polymorphic by type, not a stable shape):
    `CommandRecord::$optionsJson`/`$resultJson`, `JobRecord::$payloadJson`,
    `ActivityEventRecord::$metadataJson`, `EventNotificationRecord::$payloadJson`,
    `RawMetadataSnapshotRecord::$rawJson`, `SecretRecord::$metadataJson`
  - Superseded by the Tempest-native records slice (`cb5d4b1`): the deferral of *value objects*
    for the polymorphic ones stands, but they are now typed `array` properties named without the
    `Json` suffix (`options`/`result`/`payload`/`metadata`), persisted via Tempest's built-in
    array↔JSON casting — see the "Tempest-native records" entry below for the details.
- [ ] Entity Identity References — typed ID value objects (`StashId`, `MediaItemId`, `BroadcastId`, …)
  replacing raw `PrefixedUlid`/string at boundaries; pass loaded records instead of IDs where the
  caller already has them, keep raw strings only at HTTP/DB/serialization boundaries. Split by
  domain (auth / stashes-vault / broadcasts / jobs-commands / system-storage-activity) if too large
  for one pass. **Auth, stashes-vault, broadcasts, and jobs-commands domains done; system-storage-activity
  still pending.** Built the shared plumbing all future domains reuse: abstract
  `App\Support\Ids\PrefixedId` (wraps the existing `PrefixedUlid` for prefix/ULID validation instead of
  reimplementing it) plus a single auto-discovered `PrefixedIdCaster`/`PrefixedIdSerializer` pair
  (Tempest's `DynamicCaster`/`ConfigurableCaster`/`DynamicSerializer`, matched by property type, not a
  per-ID-type `#[CastWith]` attribute) — so adding a new ID class for another domain is just a two-line
  subclass, no new caster/serializer file. Converted `ApiTokenRecord::$userId` from `string` to `UserId`
  (a real persisted FK, proving insert → where-lookup → reload, not just boundary parsing) and tightened
  `UserRepository::findById()`/`ApiTokenRepository::create()`/`listForUser()`/`revoke()` from generic
  `PrefixedUlid`/`string` to the specific `UserId`/`ApiTokenId` types, per the spec's "tighten repository
  signatures" rule — this is what lets PHPStan catch misuse instead of a `Stringable`-typed ID silently
  flowing into every `string $id` call site. **Sharp gotcha, worth knowing before doing another domain**:
  a fresh `PrefixedIdCaster` with default priority was silently shadowed by Tempest's built-in
  `ObjectCaster` (`#[Priority(Priority::HIGH)]`, which accepts *any* class-typed property) — every typed
  ID got hydrated through generic array-to-object mapping instead of string parsing, leaving the
  `readonly` `$value` property flat-out uninitialized until first read (surfaced as `Cannot use object of
  type Tempest\View\GenericView as array` on nearly every HTTP test, because token resolution runs on
  every authenticated request). Fixed with `#[Priority(Priority::HIGHEST)]` on `PrefixedIdCaster`. Also
  confirmed (and preserved) that raw `->where('col = ?', $typedId)` bindings still need an explicit
  `->toString()` — the caster only fixes hydration/persistence, not raw bound-param binding, which goes
  through PDO directly and doesn't accept objects. Added `ApiTokenTypedIdTest.php` covering the caster
  priority fix and the where-lookup binding as a standing regression test, not just a one-off debug
  script.
  Stashes-vault domain: added `StashId`, `StashInputId`, `StashItemId`, `MediaItemId`, `MediaItemSourceId`,
  `AssetId` (each a two-line `PrefixedId` subclass, no new caster/serializer needed). Converted
  `StashInputRecord::$stashId`, `StashItemRecord::$stashId`/`$mediaItemId`/`$stashInputId`,
  `MediaItemSourceRecord::$mediaItemId`/`$stashInputId`, `AssetRecord::$mediaItemId`/`$derivedFromAssetId`.
  Deliberately left `AssetRecord::$broadcastId`/`$broadcastItemId` as raw strings (Broadcasts domain,
  future slice) and all provider-identity strings untouched per the plan's own guidance. Added
  `PrefixedId::isValid()` so route/job-payload boundary parsing can return a clean validation error for
  malformed IDs instead of an uncaught exception — several controllers/command-handlers already did
  `PrefixedUlid::parse($rawId)` inline with no guard, which is fine for a generic ID (throws either way)
  but became a real behavior gap once IDs are prefix-specific (a wrong-but-valid-shaped ID needs its own
  rejection path). This slice's actual work was mostly *consumers* of the six converted entities, not the
  six records/repositories themselves — Stashes-internal code was clean, but the Broadcasts, Downloads,
  Transcoding, and Vault-verification code that reads `StashItemRecord`/`AssetRecord`/etc. needed the same
  fix repeated across ~15 files (`BroadcastContextFactory`, `BroadcastController`, both series/podcast
  broadcast plugins, `PodcastAssetSelector`, `PodcastTranscodeFallback`, `DownloadMediaItem`,
  `TranscodePodcastAudioAsset`, `VerifyVaultAssets`, job handlers) — PHPStan (level max, `app/` only)
  caught all of those exhaustively once run project-wide; a single-file or single-domain `phpstan analyse`
  run does not check for baseline entries that went stale from a change elsewhere, so the full
  `composer test:static` was needed to catch already-fixed files whose baseline suppressions no longer
  matched. **Two bug classes PHPStan structurally cannot see, both real, both found only by the full test
  suite (`tests/` isn't scanned)**: (1) API Resource classes (`StashItemResource`, `AssetResource`,
  `StashInputResource`) were emitting the typed ID object directly into JSON output instead of an explicit
  string — `'stashId' => $this->item->stashId` used to work when the property was a plain string, and nothing
  about the type change makes that assignment a static error, it just silently serializes as `{"value":
  "..."}` instead of a plain string, breaking the public API contract. (2) test fixtures comparing a
  now-typed property against a raw string with `===`/`!==` (`if ($stashItem->mediaItemId === $mediaItemId)`)
  silently always evaluate to the "not equal" branch — no error, no warning, just wrong behavior (one fixture
  helper used this to decide which stash items to hide, and got it backwards for every item). Grepped the
  whole `tests/` tree for both patterns after finding the first instance of each, rather than fixing them
  one crash at a time. Added `StashItemTypedIdTest.php` (insert → multi-column where-lookup → reload) and
  value-shape assertions in two existing feature tests (`Phase4AHardeningTest`, `Phase6StashesVaultTest`)
  proving the API output is a plain string, not just present.
  Broadcasts domain: added `BroadcastId`, `BroadcastItemId`, `BroadcastTriggerId` (each a two-line
  `PrefixedId` subclass; skipped `BroadcastTriggerRunId` — nothing looks up a trigger run by its own ID,
  so a class with zero real call sites would just be speculative). Converted `BroadcastRecord::$stashId`
  (reusing `StashId`), `BroadcastItemRecord::$broadcastId`/`$stashItemId`/`$mediaItemId` (reusing
  `StashItemId`/`MediaItemId`), `BroadcastTriggerRecord::$broadcastId`, `BroadcastTriggerRunRecord::$triggerId`,
  and finally closed out `AssetRecord::$broadcastId`/`$broadcastItemId` — the two fields deliberately left
  as raw strings in the stashes-vault slice specifically because this domain hadn't landed yet. Applied
  this session's lesson from the start instead of discovering it again: ran `composer test:static` against
  the *whole project* immediately after the record/repository conversions (not scoped to `app/Broadcasts`),
  which surfaced all ~15 consumer files in one pass — `BroadcastContextFactory`, `BroadcastController`,
  `BroadcastLifecycleService`, `BroadcastTriggerService`, both series/podcast broadcast plugins,
  `PodcastEpisodeController`, `PodcastTokenService`, `MediaItemController`, two job handlers — instead of
  finding them one `composer test:parallel` TypeError at a time. Also proactively tightened 10
  `ActivityEventService::broadcast*()` method parameters from generic `PrefixedUlid` to `BroadcastId`
  (each already broadcast-specific by name and by the entity it's called with, so no scope creep into the
  System/Activity domain itself — `ActivityEventRecord`'s own `$broadcastId`/`$stashId`/`$mediaItemId`
  columns stay untouched, they're generic tag columns shared across many unrelated activity types, not a
  single-entity FK). Proactively grepped for both known bug classes (API Resources leaking the object,
  test fixtures doing `===` against a typed property) *before* running the suite this time, based on
  exactly what broke last slice — found and fixed three more Resource leaks (`BroadcastResource::$stashId`,
  `BroadcastItemResource`'s three ID fields, `AssetResource::$broadcastId`) and zero new `===` bugs.
  Result: only 12 failures on first full-suite run, all clean `TypeError`s from stale `PrefixedUlid`
  parses in test fixtures, no silent-bug hunting needed this time. Added `BroadcastItemTypedIdTest.php`
  (insert → multi-column where-lookup → reload) as the standing regression test for this domain.
  Jobs-commands domain: added `CommandId`, `JobId`. Converted `JobRecord::$commandId` (a genuine
  single-entity FK — always references a `CommandRecord` — unlike `JobRecord::$entityId`/
  `CommandRecord::$targetId`, which stay generic `PrefixedUlid`/string on purpose: they're deliberately
  polymorphic, paired with an `$entityType`/`$targetType` column, so the entity type isn't known at the
  property level the way it is for `$commandId`). Also converted `CommandRecord::$createdByUserId` by
  reusing `App\Auth\UserId` from the very first slice — a nice case of an FK belonging to one domain
  (jobs-commands) pointing at an entity from another (auth), which the shared `PrefixedId` plumbing
  handles without any special-casing. Two command handlers (`StashPreflightCommandHandler`,
  `SystemStorageCheckCommandHandler`, `SystemVerifyVaultCommandHandler`) reuse the same generated
  `CommandId` as both `commandId:` and a job's polymorphic `entityId:` — since `CommandId` and the generic
  `PrefixedUlid` are different classes (by design — `CommandId` is prefix-specific, `PrefixedUlid` isn't),
  passing one where the other is expected is a real type mismatch, not just a style choice; fixed by
  re-wrapping with `PrefixedUlid::parse($commandId->toString())` at the `entityId:` boundary specifically,
  leaving `commandId:` typed. Found two more `#[Hidden]`-slice-style Resource leaks proactively
  (`CommandResource::$createdByUserId`, `JobResource::$commandId`) before running the suite, plus the
  `PrefixedUlid`-vs-`CommandId` mismatch above via `composer test:static` run project-wide immediately
  after the record conversion — only 2 failures on the first full-suite run, both stale test fixtures.
  Added `JobCommandTypedIdTest.php` (insert → where-lookup via `listForCommand` → reload) as the standing
  regression test.
  `docs/plans/stronger-record-types.md`'s Entity Identity References prompt has the domain breakdown and
  the full ID-class inventory for the remaining domain (system-storage-activity: `SecretId`,
  `StorageLocationId`, `StorageCheckId`, `MediaServerConnectionId`, `ActivityEventId`,
  `EventNotificationId` — the last two are worth reconsidering when that slice starts, since
  `ActivityEventRecord`'s own `$stashId`/`$mediaItemId`/`$broadcastId`/`$commandId`/`$jobId` columns are
  deliberately generic tag columns shared across many unrelated activity types, not single-entity FKs,
  which is a different shape than every domain converted so far).
- [ ] URL & Filesystem Path Values — rename `StashdUri` → `StashdUrl`, move `fake://` support out of
  production URL handling into fake-provider-only URL classes, add YouTube-specific URL classes
  (`YouTubeChannelUrl`/`YouTubeVideoUrl`/`YouTubePlaylistUrl`) behind a marshaller, and introduce
  filesystem path value objects kept separate from URLs (vault/broadcast/storage/temp-download paths)
- [x] Sensitive Record Properties — `#[Hidden]` (`Tempest\Mapper\Hidden`) applied to
  `UserRecord::$passwordHash`, `ApiTokenRecord::$tokenHash`, `SecretRecord::$encryptedValue`/`$nonce`/
  `$metadataJson`, `ProviderAccountRecord::$secretId`, `MediaServerConnectionRecord::$tokenSecretId`,
  `BroadcastRecord::$tokenSecretId`, `BroadcastItemRecord::$tokenSecretId`. Confirmed via vendor source
  that `#[Hidden]` excludes a property from *both* the default auto-detected `SELECT` column list
  (`::select()`/`::findById()`/`::find()`/`::all()` with no explicit columns) and generic object→array
  serialization — the first-party way to still fetch a hidden column is `->include('column')` on the
  select builder (`SelectQueryBuilder::include()`, built for exactly this).
  `ApiTokenRecord::$tokenPreview`/`BroadcastRecord::$tokenPreview`/`BroadcastItemRecord::$tokenPreview`
  reviewed and deliberately left visible — they're safe-by-design preview values already exposed
  through explicit Resource DTOs; hiding them would only add friction, not reduce risk.
  Fixed every repository method whose result flowed into a hidden-field read: the "front door" finders
  (`UserRepository::findByEmail`, `SecretRepository::findByKey`, `*Repository::find`) *and*, less
  obviously, **list methods** (`BroadcastRepository::listForStash`/`listPodcastBroadcastsWithFeedToken`,
  `BroadcastItemRepository::listForBroadcast`/`findByBroadcastAndStashItem`) and **each affected
  repository's own internal `create()` re-fetch** (`BroadcastRepository`/`BroadcastItemRepository`
  `create()` insert-then-`findById()` pattern) — both categories were missed by an initial
  read-site-mapping pass that only traced *external* callers of repository methods and excluded each
  record's own repository file from the search, which is exactly backwards: a repository's internal
  `create()`/list methods are just as likely to feed a hidden-field read downstream as any external
  caller. Caught only by tracing every actual read of `->tokenSecretId` etc. back through the full
  call chain and by the full test suite (initially 45 failures: real 500s on podcast broadcast/item
  creation, not just the expected direct-property-access test breakage). Added `ApiResourceSerializationTest.php`
  cases proving the guardrail mechanism itself (not just app-level non-leakage): a hidden property is
  absent from `map($record)->toArray()`, and reading it off a record loaded via the plain default
  select throws `Tempest\Database\Exceptions\ValueWasMissing` rather than silently returning stale data.
- [x] Semantic Scalar Values — `Tempest\DateTime\Duration` applied to `JobRecord::$progressEtaSeconds`,
  `MediaItemRecord::$durationSeconds`, `AssetRecord::$durationSeconds` via new `App\Support\DurationSecondsCaster`/
  `DurationSecondsSerializer` (`#[CastWith]`/`#[SerializeWith]`) — the first use of that Tempest mechanism
  anywhere in this codebase (the prior "Stable JSON Properties" slice used the unrelated `#[SerializeAs]`
  path for `ApiTokenScopes`). Proved the mechanism on one property first (`progressEtaSeconds`, with a
  dedicated insert-then-update-then-reload test) before expanding, since neither Tempest nor this repo had
  an existing scalar-backed-value-object precedent to lean on. Confirmed by reading `InsertQueryBuilder`/
  `UpdateQueryBuilder` that the serializer fires on both insert and `$record->save()` update, and that
  nullable properties short-circuit to `null` before the caster/serializer ever runs (so neither needs
  null-handling itself). Storage stays a plain `INTEGER` column — the caster/serializer only bridges the
  PHP-object boundary, no migration changes. Kept the `*Seconds` property names (no rename to `duration`)
  and kept API JSON output (`duration_seconds`, `progress_eta_seconds`) as plain integers via a new
  `App\Support\DurationSeconds::toSeconds()`/`toDuration()` helper used at every Resource/DTO/event-payload
  boundary — repository `create()` methods still accept plain `?int` and convert internally, so every
  existing caller (providers, discovery, fake fixtures, ffmpeg/ytdlp DTOs) needed zero changes.
  `StorageLocationRecord::$role` retyped from `string` to the existing `StorageLocationKey` enum rather than
  adding a new enum: it was write-only and 100% redundant with `$key` (`StorageCapabilityChecker::checkRoot()`
  always set `role: $key->value`, and nothing in the app ever read `->role`), so reusing `StorageLocationKey`
  closed a real duplication bug and satisfied "enum for a bounded string" with no new type. `ByteSize`
  deliberately **not** built: `sizeBytes`/`freeBytes`/`totalBytes` have no unit ambiguity Duration-style
  seconds do, no normalization/behavior to encode beyond what the property name already documents, and
  formatting is already client-side-only (`dashboard.view.php`'s `formatBytes()`). A `ByteSize` wrapping an
  int and serializing straight back to an int would mostly buy blast radius: `StorageCapabilityChecker::checkRoot()`
  does live `$freeBytes / $totalBytes` ratio arithmetic that a wrapper would force unwrapping at, for no
  corresponding safety gain. `JobRecord::$progressPercent`, `AssetRecord::$mimeType`/`$language`, and
  `MediaItemRecord::$contentType` were named as candidates in `docs/plans/stronger-record-types.md` but not
  in this checklist line itself — left as plain scalars, revisit as a separate slice if desired.
- [x] Tempest-native records (`cb5d4b1`, PR #3) — three slices making the persistence layer lean on
  the framework instead of hand-rolled marshalling, driven by a 93-method audit across all 21
  repositories (~57% was framework-replaceable boilerplate; the ~40 real-invariant methods stay).
  **JSON columns**: every `*Json` column/property renamed to its bare name (`options`/`result`/
  `payload`/`settings`/`metadata`/`details`/`scopes`; 12 columns, 10 tables, migration
  `2026_07_03_rename_json_columns`) and the raw-string ones retyped as `array<string, mixed>` —
  Tempest's `JsonToArrayCaster`/`ArrayToJsonSerializer` own the JSON boundary, ~41 manual
  `json_encode`/`json_decode` sites deleted, `ApiJson::encode` opaque-key protection preserved in
  resources; PHPStan baseline shrank by 32 entries, none added. **PrimaryKey ceremony**:
  `PrefixedId::toPrimaryKey()`/`fromPrimaryKey()` + `PrefixedUlid::toPrimaryKey()` replaced ~36
  `new PrimaryKey($id->toString())`/`XId::parse((string) $record->id)` sites; every repository
  `create()` dropped its insert-then-`findById()` reload (Tempest persists client-set PKs as-is);
  dead `BroadcastTriggerRunRepository::save()` deleted, single-caller `UserRepository::count()`
  inlined. **Relations**: `#[HasMany] StashRecord::$items` / `#[BelongsTo] StashItemRecord::$stash`
  declared and proven on PostgreSQL (`tests/Feature/TempestRelationsTest.php`: `with()`, `load()`,
  `whereHas()`, BelongsTo hydration) — the `BelongsToStatement` stripping in
  `MigrationSchemaHelpers` affects only FK *constraints*, not relation joins. Gotchas: the FK
  column on the child table is the `ownerJoin` arg for **both** `#[BelongsTo]` and `#[HasMany]`
  (needed since our camelCase columns don't match Tempest's `snake_id` default), and a HasMany
  `@var` docblock needs an FQCN (`\App\Stashes\StashItemRecord[]`) — bare same-namespace names fail
  reflection at query time. Custom-typed *primary keys* are unsupported (PK stays `PrimaryKey`,
  holding client-set string ULIDs), and Tempest generates only UUIDv7, so `PrefixedUlidGenerator`
  stays.
- [ ] Later Encryption Annotation Review — investigate `#[Encrypted]` as a future `SecretsService`
  simplification; deliberately not combined with the `#[Hidden]` slice above
- [ ] Later Naming Review — audit whether `*Record` should remain the persistence-marker suffix
  everywhere, after the stronger-typing slices land
- [ ] Later Discovery Review — investigate Tempest custom discovery for provider / command-handler /
  job-handler / broadcast-format / media-server-client registries, replacing today's manually-curated
  initializer registries
- [x] Tempest relations audit (`docs/plans/tempest-relations-review.md`) — resolved by the
  Tempest-native records slice (`cb5d4b1`): relations proven working on PostgreSQL and declared on
  stash/stash-item, but the wholesale replacement of repository FK-list methods (`listForStash` &
  co., 18 methods) was **deliberately rejected** — every caller holds a typed ID from a command/job
  payload or route param, so the repo one-liner is already the minimal interface; relation access
  would add a parent-record fetch at 17 call sites. Adopt `with()`/`load()`/`whereHas()`
  opportunistically where future code starts from a loaded record, not by refactoring ID-driven
  callers.

## Future companion apps (not started, not part of this v1 roadmap)

- [ ] Browser extension — revised v1 spec at `docs/Stashd-Browser-Extension-Spec.md`: explicit
  browser-to-web-app handoff into the existing create-stash flow; no extension code yet
  roadmap phase assigned. First add the small `/stashes/new` fragment handoff described in the spec,
  then package the Chromium popup.
