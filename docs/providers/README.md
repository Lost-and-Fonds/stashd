# Providers

Stashd routes inputs through registered external plugins. Plugins own provider
protocols, URL handling, discovery semantics, and acquisition behavior; Core
provides the generic lifecycle, storage, and Vault pipeline.

| Capability | Owner |
|---|---|
| Discovery | External Input plugin |
| Acquisition | External Input plugin through staged artifacts |

Provider-specific strategy selection happens inside the plugin. Core selects
the registered logical provider implementation and commits discovered facts.

## First-party providers

`Lost-and-Fonds/youtube` is the YouTube Input package. Its protocol behavior
lives in the plugin; Core supplies invocation-scoped HTTP, helper, credential,
and staging capabilities.

Jellyfin, Plex, and Podcast are Broadcast packages. Core materializes Vault
assets and invokes their generic Broadcast lifecycle; each package owns its
provider-specific protocol or output format.

## End-to-end flow

```text
POST /api/v1/commands  type=stash.preflight  source_uri=<url>
  → job preflight
  → commands.result

GET /api/v1/stashes/preflight/{commandId}/review

POST /api/v1/commands  type=stash.create_from_preflight
  → stash, stash_input, items, item_sources, stash_items
```

Items deduplicate globally by `(providerKey, providerItemId)`. When acquisition
is enabled, staged artifacts pass through the normal Vault ingest pipeline.

## Typed domain boundaries

| Type | Role |
|---|---|
| `StashdUri` | Wraps `Tempest\Support\Uri\Uri` for URL parsing and path/query helpers |
| `ProviderDates` | Parses and constructs `Tempest\DateTime\DateTime` values |
| `DiscoveredItem` / `ResolvedInput` | Hold typed source identity and serialize at API/storage boundaries |
| `Tempest\Support\str()` | String helpers |

Do not pass raw URL or date strings through provider strategy handlers when a
typed wrapper exists.
