# Providers

Stashd's bundled providers resolve inputs and discover media items. External Input plugins own provider-specific mechanisms and return facts or staged artifacts to the normal Stashd pipeline.

| Capability | Interface / adapter | Phase |
|---|---|---|
| Discovery | External Input Component or test provider | current |
| Acquisition | `DownloaderInterface` and plugin staging adapter | current |

Provider-specific mechanism selection happens inside the external plugin. Core only selects the registered logical provider implementation.

## Fake provider

Key: `fake`

URIs: `fake://channel/{name}`, `fake://playlist/{name}`, `fake://item/{id}`

| Strategy | Key | Cost |
|---|---|---|
| Discovery | `fake.feed` | Low |

Used for tests, local development, and Docker smoke. Downloads use `FakeDownloader`.

Fixtures: `tests/fixtures/providers/fake/`

## YouTube Input plugin

Key: `youtube`

The bundled Component under `plugins/youtube` owns YouTube source parsing, RSS/Data API discovery, and yt-dlp acquisition. Its manifest contributes logical provider key `youtube`; the implementation package identity is runtime provenance only.

The Component chooses RSS versus Data API based on semantic discovery intent and granted capabilities. The host supplies bounded HTTP, credential use, staging, and the trusted `yt-dlp` helper without exposing those mechanisms as core provider strategies.

## Download service

All stash downloads go through `App\Domain\Download\DownloaderInterface`:

| Implementation | When |
|---|---|
| `FakeDownloader` | `providerKey=fake` (tests, dev, Docker smoke) |
| External plugin adapter | Registered non-fake provider implementation |
| `DelegatingDownloader` | Default binding; selects fake or external implementation |

- Command: `item.download` → temp staging → Vault ingest → asset rows
- Vault originals are not overwritten by default; `force=true` returns `download_force_not_supported`
- Opt-in live tests: `STASHD_LIVE_DOWNLOAD_TESTS=1`

See `docs/storage/README.md` for idempotency, drift detection, and sidecar JSON rules.

## Fixtures

HTTP fixtures for CI live under:

```text
tests/fixtures/providers/youtube/http/
tests/fixtures/providers/fake/
```

Map URLs to fixture bodies in `map.json`. The plugin host consumes these
fixture mappings during deterministic Component tests.

Optional live provider tests:

```env
STASHD_LIVE_PROVIDER_TESTS=1
STASHD_LIVE_DOWNLOAD_TESTS=1
```

## End-to-end flow (preflight → stash)

```text
POST /api/v1/commands  type=stash.preflight  source_uri=<url>
  → job preflight
  → commands.result

GET /api/v1/stashes/preflight/{commandId}/review

POST /api/v1/commands  type=stash.create_from_preflight
  → stash, stash_input, media_items, media_item_sources, stash_items
```

Media items deduplicate globally by `(providerKey, providerItemId)`.

Downloads (when enabled):

```text
POST /api/v1/commands  type=item.download
  → temp staging → Vault → assets ready
```

## Typed domain boundaries

Per the engineering spec, provider domain types are typed internally; raw strings appear only at HTTP/DB/JSON edges.

| Type | Role |
|---|---|
| `StashdUri` | Wraps `Tempest\Support\Uri\Uri` — parse, fake URIs, path/query helpers |
| `ProviderDates` | Parses/constructs `Tempest\DateTime\DateTime` (`tryParse()`, `utc()`) |
| `DiscoveredItem` / `ResolvedInput` | Hold `StashdUri` + `DateTime`; serializers emit `toString()` / RFC3339 `Z` |
| `Tempest\Support\str()` | String helpers (not raw PHP string functions) |

Do not pass raw URL or date strings through provider strategy handlers when a typed wrapper exists.
