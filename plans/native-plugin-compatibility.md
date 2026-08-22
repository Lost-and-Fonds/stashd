# M0 Native Plugin Compatibility Inventory

Status: complete for `b3c393a`, 2026-08-22. This is an inventory, not a
runtime design change. The machine-readable source is
[`native-plugin-compatibility.json`](native-plugin-compatibility.json), checked
by [`verify-native-plugin-compatibility.php`](verify-native-plugin-compatibility.php).

## Active contract

The active WIT contract at the production baseline consists of:

| World | File | Host import | Plugin export | Operations |
|---|---|---|---|---|
| `input-world` | `plugin-api/wit/input.wit` | `input-host` | `input-plugin` | `resolve`, `discover`, `acquire` |
| `broadcast-world` | `plugin-api/wit/broadcast.wit` | `broadcast-host` | `broadcast-plugin` | `prepare`, `publish`, `finalize`, `operation` |

The semantic inventory covers records, lists, options, variants, result/error
values, opaque resource concepts, HTTP, staging, helper execution, progress,
and logging. No WIT file was modified.

## Current implementations

| Package | Kind/world | Logical identity | Contract-facing capabilities |
|---|---|---|---|
| `plugins/youtube` | Input / `input-world` | `provider_key: youtube` | HTTP, credential use, helper, staging, progress, logging |
| `plugins/podcast` | Broadcast / `broadcast-world` | `broadcast_key: podcast` | helper, staging, progress, logging |
| `plugins/jellyfin` | Broadcast / `broadcast-world` | `broadcast_key: jellyfin` | HTTP, credential use, staging, progress, logging, dynamic choices |
| `plugins/plex` | Broadcast / `broadcast-world` | `broadcast_key: plex` | HTTP, credential use, staging, progress, logging, dynamic choices, source settings |

`plugins/example` is deliberately not counted as an active-contract
implementation. It targets `plugin-api/spike-wit`, the quarantined historical
copy-through experiment. M1/M2 must add or designate a current-contract
provider-neutral example before native conformance work begins.

## Existing host adapter surface

`PluginHostClient` currently adapts the active lifecycle through these
application operations:

```text
input-resolve
input-discover
input-acquire
broadcast-prepare
broadcast-publish
broadcast-finalize
broadcast-operation
```

It receives progress/log/result/error events and returns typed application
results. Invocation authority currently includes component path, asset/staging
paths, HTTP grants, and helper grants. These are implementation details to be
translated into the future native runner; they are not a native plugin API.

## Capability inventory

| Capability | Current semantic grant | Native compatibility requirement |
|---|---|---|
| HTTP | Operation-scoped allowed prefixes and optional credential grant | Host enforces destination, credential, limits, and timeout; plugin has no socket |
| Credential use | Named grant used by request | Native plugin receives no raw secret |
| Staging | Invocation output area and staged artifact references | Package cannot write Vault or authoritative paths |
| Helper | Declared helper name and executable grant | Native helper must be package-relative and share the sandbox |
| Progress/logging | Host-forwarded invocation events | Events cannot mutate authoritative state |
| Source settings/choices | Plugin-declared settings and opaque operation choices | Core persists and renders values without interpreting provider semantics |

The authoritative flow remains:

```text
plugin result → staging → core validation/fixity/provenance → promotion
```

The native transport must not copy current PHP-to-host credential values into
the plugin-visible protocol. The plugin should receive an opaque credential
reference while the host performs injection.

## RPC compatibility map

The future transport needs lifecycle request envelopes for all seven current
operations, plus host capability requests for HTTP, staging reads/writes,
helpers, logging, and progress. Current WIT values map directly to structured
RPC records, lists, optionals, variants, results, and opaque resource
references.

The following are compatibility follow-ups, not M0 blockers:

1. Active HTTP/staging bodies use `list<u8>` and need an opaque resource mapping
   before native protocol freeze for large media.
2. Component resource handles need language-neutral SDK lifetime conventions.
3. The existing Wasmtime host adapter has implementation details that must not
   become native plugin authority.
4. The example fixture is still spike-WIT based and is intentionally excluded
   from active-contract conformance.
5. Bubblewrap remains `/proc`-less in the tested rootless Podman environment.

## M0 conclusion

No unavoidable semantic gap was found. All current Input and Broadcast
lifecycle operations, generic settings, dynamic choices, credentials,
staging, helpers, logging, progress, and typed failures have a clear proposed
native representation. M1 is unblocked, subject to M1 using a current-contract
provider-neutral example rather than the quarantined spike fixture.

