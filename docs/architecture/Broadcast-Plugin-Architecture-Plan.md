# Broadcast Plugin Architecture — Implementation Plan

## Status (historical plan; current implementation updated 2026-08-21)

The implementation plan below is retained as historical design context. The
current state has completed the external Podcast migration: Podcast is
registered from `plugins/podcast/plugin.json`, invoked through the generic
Broadcast adapter and shared plugin host, and no longer has a PHP
`PodcastBroadcastPlugin`, Podcast routes, token service, or Podcast-specific
core transcode job. The durable Broadcast key remains `podcast`; the component
package identity is runtime provenance only.

Podcast, Jellyfin, and Plex are now all registered external Components.
Older PHP class names in this historical plan are not current runtime
dependencies.

**Phase 1 is done, in-tree, under `app/Broadcasts/Plugins/`.** `BroadcastPlugin`,
`#[StashdBroadcast]`, `BroadcastPluginDiscoverer`, `BroadcastPluginRegistry`,
`FileKind`, `UiControl`, and `DiscoveredPlugin` all exist and are wired into
`BroadcastController` and `BroadcastLifecycleService`; the old `BroadcastType`
enum, `BroadcastTypeRegistry`, `BroadcastFormat` interface, and `Formats/`
directory are deleted. `GET /api/v1/broadcast-plugins` exposes the registry to
the UI, which drives its create-broadcast dropdown from it.

**Phases 2-3 (separate `stashd/plugin-podcast` / `stashd/plugin-media-server`
Composer packages, `bootstrap.php`) are deferred, not scheduled.** This
contradicts the repo rule "v1 is plugin-ready but does not ship a third-party
plugin runtime" (`.claude/rules/stashd-architecture.md`). Plugins stay in-tree
under `app/Broadcasts/Plugins/`, discovered the same way — the packaging
boundary was never actually needed for a single-binary self-hosted app; revisit
only if a real third-party plugin ecosystem becomes a goal.

**Update (2026-07-02): `FilesystemBroadcastPlugin` removed.** It was a bare
series layout with no NFO sidecars and no media-server targeting — unclear
value next to `JellyfinBroadcastPlugin`/`PlexBroadcastPlugin`, so it's gone.
The plugin registry now has three keys: `podcast`, `jellyfin`, `plex`. Any
mention of a `filesystem` broadcast type below is historical record of what
the original migration produced, not current state.

The sections below are the **original plan as written**, kept for the design
rationale, with corrections inline where the actual implementation ended up
different (marked **✅ implemented differently** or **⏸ deferred**). Do not
copy code samples from here without checking the real source first — several
signatures below don't match what shipped.

## Goal

~~Extract the broadcast format layer from core into separate Composer
packages.~~ **✅ implemented differently**: the format layer was extracted from
the old enum/registry into a plugin interface + attribute-discovery system,
but it stays in-tree (`app/Broadcasts/Plugins/`) rather than moving to separate
Composer packages. Core (`BroadcastController`, `BroadcastLifecycleService`)
now goes through `BroadcastPluginRegistry`/`BroadcastPlugin` instead of
hardcoding format classes, which was the actual goal — package boundaries were
never the point.

## Original "Current State" (pre-migration — all deleted now)

- `BroadcastType` enum: `FilesystemSeries`, `JellyfinSeries`, `PlexSeries`, `Podcast`
- `BroadcastTypeRegistry` — holds instances of format classes, resolves by enum value
- `BroadcastFormat` interface — `key()`, `plan()`, `publish()`, `verify()`, `prune()`
- `AbstractSeriesBroadcastType` — shared base for series formats (NFO, hardlinks, sidecars)
- `PodcastBroadcastFormat` — RSS feed generation, tokenized URLs, asset selection
- `BroadcastController` — hardcodes `BroadcastType` enum, `PodcastTokenService`, `PodcastEpisodeUrlBuilder`
- `BroadcastLifecycleService` — uses `BroadcastTypeRegistry` to resolve handlers

## Target State

- `BroadcastPlugin` interface (replaces `BroadcastFormat`) — ✅ done
- `#[StashdBroadcast]` attribute for discovery — ✅ done
- `BroadcastPluginDiscoverer` — scans Tempest discovery at boot — ✅ done
- ~~`stashd/plugin-podcast` — Composer package~~ — ⏸ deferred, stays in-tree as `PodcastBroadcastPlugin`
- ~~`stashd/plugin-media-server` — Composer package~~ — ⏸ deferred, stays in-tree as `JellyfinBroadcastPlugin`/`PlexBroadcastPlugin`/`FilesystemBroadcastPlugin`
- ~~Core has zero knowledge of plugin internals~~ — not fully true even as designed: `BroadcastController` still special-cases `'podcast'` for feed URLs/token rotation, same as before

## Core Interfaces (actual, as implemented — corrects the original draft below)

### `BroadcastPlugin` (interface) — `app/Broadcasts/BroadcastPlugin.php`

```php
interface BroadcastPlugin
{
    public function broadcastKeys(): array;
    public function supportedFileKinds(): array;
    public function uiControls(): array;
    public function plan(BroadcastContext $context): BroadcastPlan;
    public function publish(BroadcastContext $context, BroadcastPlan $plan): BroadcastPublishResult;
    public function verify(BroadcastContext $context): BroadcastVerifyResult;
    public function prune(BroadcastContext $context): BroadcastPruneResult;
}
```

Note the real `plan()` takes **no `$userInput` parameter** — the draft below is
wrong on this point. Per-broadcast settings (e.g. podcast `media_kind`) are read
from `BroadcastContext`/`BroadcastRecord.settings`, not passed into `plan()`.

### `StashdBroadcast` (attribute) — `app/Broadcasts/StashdBroadcast.php`

```php
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class StashdBroadcast
{
    public function __construct(
        public string $name,
        public string $description = '',
    ) {}
}
```

Applied as: `#[StashdBroadcast('Podcast', 'RSS podcast feed with episode media URLs.')]`
— **no `$key` argument**. The broadcast key(s) come from the plugin's own
`broadcastKeys()` method instead, since a single plugin class can register more
than one key.

### `DiscoveredPlugin` (wrapper) — `app/Broadcasts/DiscoveredPlugin.php`

```php
final readonly class DiscoveredPlugin
{
    public function __construct(
        public string $className,
        public string $name,
        public string $description,
        public array $broadcastKeys,
        public BroadcastPlugin $plugin,
    ) {}
}
```

### `BroadcastPluginDiscoverer` (service) — `app/Broadcasts/BroadcastPluginDiscoverer.php`

Scans Tempest discovery registrations at boot, finds all `#[StashdBroadcast]` attributes, instantiates plugins, validates no duplicate keys, populates the static `BroadcastPluginRegistry` (not returned as a value — the draft below implies a return value, but discovery is a boot-time side effect via Tempest's `Discovery` interface).

### `FileKind` (enum)

```php
enum FileKind: string { case Video = 'video'; case Audio = 'audio'; }
```

### `UiControl` (value object) — `app/Broadcasts/UiControl.php`

```php
final readonly class UiControl
{
    public function __construct(
        public string $name,
        public string $label,
        public string $type = 'text',   // e.g. 'text', 'select'
        public mixed $default = null,
        public array $options = [],      // for select: flat list of option values
    ) {}
}
```

No `$category` or `$required` — the draft below over-specified this. No plugin
currently declares any `uiControls()` beyond the podcast plugin's five text/select
fields (title, description, author, funding_url, media_kind); see
`GET /api/v1/broadcast-plugins` for the live shape.

## File Layout

### Core (`app/Broadcasts/`) — ✅ implemented, in-tree

The plugin implementations live under `app/Broadcasts/Plugins/`
(`JellyfinBroadcastPlugin`, `PlexBroadcastPlugin`,
`AbstractSeriesBroadcastPlugin` as their shared base, `SeriesFormatOptions`) and
`app/Broadcasts/Podcasts/` (podcast support classes — `PodcastAssetSelector`,
`PodcastTokenService`, `PodcastEpisodeUrlBuilder`, etc. — plus
`PodcastBroadcastPlugin` under `Plugins/`). `Formats/` is deleted, as planned;
`Podcasts/` was **not** deleted — it never moved to a separate package, so it
stays. Don't trust a hand-copied file tree here to stay accurate — run
`ls app/Broadcasts/ app/Broadcasts/Plugins/` for the current, authoritative
layout.

### `stashd/plugin-podcast`, `stashd/plugin-media-server` — ⏸ deferred, never created

These packages don't exist and aren't planned. Everything they would have
contained (`PodcastAssetSelector`, `PodcastTokenService`,
`JellyfinBroadcastPlugin`, `PlexBroadcastPlugin`,
`AbstractSeriesBroadcastPlugin`, `SeriesFormatOptions`, etc.) lives in-tree
under `app/Broadcasts/Podcasts/` and `app/Broadcasts/Plugins/` instead, per the
in-tree-only decision at the top of this doc.

## Implementation Tasks

### Phase 1: Core interfaces and types — ✅ done

1. Create `BroadcastPlugin` interface
2. Create `StashdBroadcast` attribute
3. Create `DiscoveredPlugin` wrapper
4. Create `FileKind` enum
5. Create `UiControl` value object
6. Create `BroadcastPluginDiscoverer` service
7. Wire discoverer into Tempest discovery at boot

### Phase 2: Extract podcast plugin — ✅ implemented differently, in-tree (⏸ Composer package deferred)

The podcast format was extracted from `PodcastBroadcastFormat` into
`PodcastBroadcastPlugin` implementing `BroadcastPlugin`, `BroadcastType`/
`BroadcastTypeRegistry`/`BroadcastFormat`/`app/Broadcasts/Formats/` are deleted,
and `BroadcastController`/`BroadcastLifecycleService` resolve it through
`BroadcastPluginRegistry`. It was **not** moved to a `stashd/plugin-podcast`
Composer package — steps 1, 5, and 6 below (package skeleton, `bootstrap.php`,
Composer dependency) didn't happen and aren't planned.

1. ~~Create `stashd/plugin-podcast` package skeleton~~ — ⏸ deferred
2. Move `PodcastBroadcastFormat` → plugin (renamed to `PodcastBroadcastPlugin`, implements `BroadcastPlugin`) — ✅ done, in-tree
3. Move all podcast support classes → plugin — ✅ done, but to `app/Broadcasts/Podcasts/` in-tree, not a package
4. Add `#[StashdBroadcast]` to plugin class — ✅ done
5. ~~Create plugin `bootstrap.php` that registers Tempest discovery~~ — ⏸ deferred (discovery is Tempest's normal in-tree class scanning)
6. ~~Update core to depend on plugin via Composer~~ — ⏸ deferred
7. Update `BroadcastController` and `BroadcastLifecycleService` to use discovered plugins — ✅ done
8. Update `BroadcastType` enum — remove podcast, jellyfin, plex, filesystem values — ✅ done, but the whole enum was deleted rather than narrowed (broadcast type is a plain string now)
9. Delete `app/Broadcasts/Formats/` directory — ✅ done
10. ~~Delete `app/Broadcasts/Podcasts/` directory (moved to plugin)~~ — did not happen; this directory still holds the in-tree podcast support classes
11. Delete `BroadcastTypeRegistry` — ✅ done
12. Delete `AbstractSeriesBroadcastType` (plugin-internal) — ✅ done (replaced by `AbstractSeriesBroadcastPlugin`)
13. Delete `BroadcastFormat` interface (replaced by `BroadcastPlugin`) — ✅ done

### Phase 3: Extract media-server plugin — ✅ implemented differently, in-tree (⏸ Composer package deferred)

Same story as Phase 2: `JellyfinBroadcastPlugin`, `PlexBroadcastPlugin`, and
`FilesystemBroadcastPlugin` (all extending `AbstractSeriesBroadcastPlugin`)
exist under `app/Broadcasts/Plugins/`, each implementing `BroadcastPlugin`
directly — no separate `stashd/plugin-media-server` package, no
`bootstrap.php`, no Composer dependency.

### Phase 4: Wire and clean up — ✅ done (aside from the Composer-package items, which don't apply)

1. Update `BroadcastController` to use `BroadcastPluginDiscoverer` — ✅ done (via `BroadcastPluginRegistry`, which the discoverer populates)
2. Update `BroadcastLifecycleService` to call plugin methods directly — ✅ done
3. Update `BroadcastContextFactory` if needed — ✅ done
4. Update `BroadcastState` enum if any type-dependent states need removal — not needed; unchanged
5. Update tests — ✅ done for the in-tree scope; no separate per-package test suites since there are no packages
6. ~~Update `composer.json` to add plugin dependencies~~ — ⏸ deferred, not applicable
7. Update `docs/TODO.md` to reflect completion — see note below
8. Delete any remaining dead code — ✅ done (`BroadcastTypeTest.php` stubs, stray backup files, unused `app/Id/EntityId.php`)

### Phase 5: Documentation — partially done

1. Update `docs/TODO.md` with plugin architecture docs — check `docs/TODO.md` directly; not exhaustively re-verified here
2. Write plugin development guide (how to create a new plugin) — not done; would be worth writing before any real third-party plugin push, not needed for the current four in-tree plugins
3. Update `AGENTS.md` or architecture docs with new structure — this doc is that update

## Key Migration Details

### `BroadcastRecord.type` — enum to string — ✅ done, simpler than drafted

No SQL migration ever ran or was needed: `broadcasts.type` was already a plain
`string` SQLite column in `CreateDomainSchema` (this project is SQLite-only —
the drafted `ALTER TABLE ... DROP CONSTRAINT` is Postgres syntax and never
applied here). Only `BroadcastRecord::$type`'s PHP type changed, from
`BroadcastType $type` to plain `string $type` — an application-layer change
with zero migration surface.

### UI flow — ✅ implemented as drafted, minus `$userInput`

1. User creates broadcast → core queries the plugin registry (`GET /api/v1/broadcast-plugins`) → renders the type picker from it → user selects a type
2. Core passes the selected key to `BroadcastRepository::create()`; the plugin's `plan()` reads settings from `BroadcastContext`, not from a `plan()` argument (see the `$userInput` correction above)
3. Core calls `publish()` → plugin creates its output (RSS feed, Jellyfin layout, etc.)
4. Plugin returns `BroadcastPublishResult` with publication details

`uiControls()` is exposed over the API and the create form now renders
per-plugin controls from it (the podcast plugin's `title`/`description`/
`author`/`funding_url`; `media_kind` keeps its own dedicated select, excluded
from the generic render to avoid a duplicate). There is still no general
`PATCH` for broadcast settings after creation — `settings` can only be set at
creation time (`POST /api/v1/stashes/{id}/broadcasts`) or, for the one
settings key that has its own endpoint, via `PATCH /api/v1/broadcasts/{id}/
season-mapping`. Series plugins declare no `uiControls()`, so nothing renders
for them.

### Plugin bootstrap — ⏸ deferred, doesn't apply

No `bootstrap.php`, no Composer package dependency. Tempest's normal
`Discovery` scanning finds `#[StashdBroadcast]`-attributed classes anywhere
under `app/`, in-tree, the same way it discovers command handlers, job
handlers, and everything else in this codebase.

## Risks

- ~~Existing broadcasts with `BroadcastType::JellyfinSeries` values need migration to string~~ — moot; the column was always a string (see above), and no broadcasts existed yet when the enum was removed
- Tests that hardcode `BroadcastType` values need updating — done, but this exact category of drift (stale `filesystem_series`/`jellyfin_series`/`plex_series` keys) recurred three separate times after the initial migration (frontend, then two test files, then this doc's own `BroadcastType` schema in `docs/openapi.yaml`) before all instances were caught — a good argument for the `GET /api/v1/broadcast-plugins` endpoint and `docs/openapi.yaml`'s `BroadcastType` enum as the two remaining sources of truth to keep in sync if a plugin's key ever changes again
- Plugin discovery must handle missing plugins gracefully (log warning, skip) — done: `BroadcastPluginDiscoverer::apply()` logs and skips on resolution failure, missing `BroadcastPlugin` implementation, or duplicate key
- ~~Docker build needs plugin Composer packages available~~ — not applicable, no packages
- `BroadcastController` hardcodes `PodcastTokenService` and `PodcastEpisodeUrlBuilder` — still true today, unchanged from the original migration; core is not fully plugin-agnostic and isn't expected to be while packaging stays deferred
