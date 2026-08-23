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

## YouTube Input plugin (deferred)

Key: `youtube`

`Lost-and-Fonds/youtube` is reserved for the M11 native Input plugin. No
production YouTube provider is installed in core yet; the retired Wasm design
is reference material under `reference/wasmtime/`.

When M11 begins, provider-specific discovery and acquisition will remain in the
YouTube package while core supplies only generic capabilities.

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
