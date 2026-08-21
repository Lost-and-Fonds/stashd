# Code organization

Stashd uses a **feature-first** layout under `app/`. Each top-level folder owns a slice of product behavior: HTTP controllers, command handlers, jobs, domain records, repositories, and services for that feature live together instead of being split across `Domain/`, `Services/`, and `Infrastructure/`.

## Top-level map

| Folder | Role | PHP files |
|--------|------|-----------|
| `Auth/` | Single-owner auth, users, API tokens | 12 |
| `Broadcasts/` | Broadcast lifecycle, formats, hardlink publishing, triggers | 40 |
| `Commands/` | Command types, dispatch, registry, generic command API | 10 |
| `Console/` | CLI entrypoints (boot, worker tick, scheduler) | 4 |
| `Database/` | Schema migrations | 4 |
| `Downloads/` | Download boundary, external plugin adapter, fake downloader, policies | 18 |
| `Http/` | Shared HTTP middleware, API helpers, routing decorators | 4 |
| `Jobs/` | Job types, worker, handlers, jobs API | 18 |
| `MediaServers/` | Jellyfin/Plex clients, connections, scan triggers | 20 |
| `Providers/` | Provider registry, fake provider, `Core/DiscoveredItem` | 35 |
| `Stashes/` | Stash preflight, create-from-preflight, stash domain | 22 |
| `System/` | Boot, health, storage, scheduler, activity, events, secrets, state, wiring | 42 |
| `Support/` | Cross-cutting helpers (`PrefixedUlid`, `RecordTimestamps`) | 3 |
| `Vault/` | Media items, assets, vault verify, path/staging helpers | 24 |
| `Config/` | Tempest config PHP files (unchanged) | 8 |

**Total:** 264 PHP files under `app/` (excluding tests).

## Namespace rules

- `App\{Feature}\…` — feature code
- `App\Support\…` — shared primitives (formerly `App\Domain\Support` + `RecordTimestamps`)
- `App\Http\…` — cross-cutting HTTP only
- `App\Config\…` — configuration (unchanged)
- `App\Console\…` — console commands (unchanged)
- `App\Database\…` — migrations (unchanged)

Repositories sit next to the records they persist (e.g. `App\Broadcasts\BroadcastRepository`, `App\Vault\AssetRepository`).

## Subfolders worth knowing

| Path | Contents |
|------|----------|
| `Broadcasts/Formats/` | `BroadcastFormat` implementations (`filesystem_series`, `jellyfin_series`, `plex_series`) |
| `Providers/Core/` | `DiscoveredItem` (+ static serialization helpers) |
| `Plugins/` | External Component registry and invocation adapters |
| `MediaServers/Http/` | Fixture/curl HTTP clients for media servers |
| `System/Wiring/` | DI initializers (handler registries, downloader routing, HTTP clients) |
| `Jobs/Handlers/` | Per-intent job handlers |

## Consolidated classes

Several thin wrappers were merged during the refactor:

| Before | After |
|--------|-------|
| `BroadcastPlanCommandHandler`, `BroadcastRebuildCommandHandler`, `BroadcastVerifyCommandHandler`, `BroadcastPruneCommandHandler`, `BroadcastTriggerCommandHandler`, `AbstractBroadcastCommandHandler` | `Broadcasts\BroadcastCommandHandler` (one class, `CommandType` in constructor) |
| `MediaServerTestConnectionCommandHandler`, `MediaServerListLibrariesCommandHandler`, `AbstractMediaServerCommandHandler` | `MediaServers\MediaServerCommandHandler` |
| `BroadcastPlanner`, `BroadcastPublisher`, `BroadcastVerifier`, `BroadcastPruner` | Inlined into `Broadcasts\BroadcastLifecycleService` |
| `InodeHelper` | `HardlinkPublisher::sameFile()` |
| `DiscoveredItemSerializer` | `Providers\Core\DiscoveredItem::toArray()` / `manyToArray()` |

## Renamed types (behavior unchanged)

| Old | New |
|-----|-----|
| `RoutingDownloader` | `DelegatingDownloader` |
| `BroadcastTypeHandler` | `BroadcastFormat` (interface) |
| `SeriesBroadcastProfile` | `SeriesFormatOptions` |
| `MediaServerTokenResolver` | `MediaServerConnectionSecrets` |
| `PreflightExecutor` | `DiscoverStashInput` |
| `StashFromPreflightService` | `CreateStashFromDiscovery` |
| `DownloadExecutor` | `DownloadMediaItem` |
| `TempStagingService` | `StageDownloadFiles` |
| `AtomicFileMover` | `MoveFileIntoVault` |
| `VaultVerifyService` | `VerifyVaultAssets` |

Command type strings (`broadcast.plan`, …), job intent strings, routes, DB schema, and API JSON shapes are unchanged.

## Controllers

Feature controllers moved into their feature folders:

- `Auth\AuthController`
- `Broadcasts\BroadcastController`
- `Commands\CommandController`
- `Jobs\JobController`
- `MediaServers\MediaServerController`
- `Stashes\StashPreflightController`
- `Vault\MediaItemController`
- `System\Health\HealthController`
- `System\Event\EventSubscriptionController`

## Wiring / bootstrap

Former `app/Bootstrap/*` initializers live in `System/Wiring/`:

- `CommandHandlerRegistryInitializer` — registers command handlers (broadcast/media-server handlers instantiated with explicit `CommandType`)
- `JobHandlerRegistryInitializer`
- `DownloaderInitializer`, `YtdlpGatewayInitializer`, `YtdlpDownloadAdapterInitializer`
- `MediaServerHttpClientInitializer`

Fixture paths in testing initializers resolve from project root (`dirname(__DIR__, 3)` from `System/Wiring/`).

## Tests

Tests were **not** deleted or reorganized by folder; only `use` imports and class references were updated to match new namespaces. Test directory layout still mirrors historical areas (`tests/Unit/Domain/…`, `tests/Feature/…`, etc.).

## Removed layout

These top-level folders no longer exist:

- `app/Domain/`
- `app/Services/`
- `app/Infrastructure/`
- `app/Controllers/`
- `app/Bootstrap/`
