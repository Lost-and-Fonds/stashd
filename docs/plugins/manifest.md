# Plugin manifest reference

A Stashd plugin package declares itself with `plugin.json`. Current first-party
repositories keep it at `stashd-plugin/plugin.json`.

This page separates three different categories that are easy to blur together:

- **package contract fields** validated by the plugin runtime;
- **application integration fields** consumed by Core to expose/configure a
  plugin;
- **current tooling conventions** used by the PHP/OCI builder.

The canonical lifecycle/value/capability contract remains the WIT in
`plugin-api`; the manifest describes packaging and host integration around that
contract.

## Current compatibility line

New plugins should target:

```text
WIT package       stashd:plugin@0.2.0
manifest          api_version: "0.2"
PHP SDK           stashd/php-sdk:^0.3
production runtime php
```

Core currently accepts both `0.2` and legacy `0.1` manifests as an explicit
migration allowance. Do not use that compatibility path as a reason to begin
new work on 0.1.

## Required package fields

```json
{
  "id": "example",
  "name": "Example",
  "version": "0.1.0",
  "runtime": "php",
  "api_version": "0.2",
  "entrypoint": "stashd-plugin/plugin.php"
}
```

### `id`

Stable machine identifier. Current validation requires 2–64 lowercase
characters matching:

```text
^[a-z][a-z0-9-]{1,63}$
```

Changing an ID creates a different installed plugin. Do not encode the version
in the ID.

### `name`

Human-readable name. Must be non-empty.

### `version`

Package version. Current validation accepts `major.minor.patch` with an optional
SemVer-style suffix.

Installed versions are immutable. Rebuilding different content under the same
version/digest slot is rejected; bump the version.

### `runtime`

The runtime needed to execute the entrypoint.

**Current production value: `php` only.** The contract is language-neutral, but
the current manifest validator and Bubblewrap command support the PHP runtime.
A future SDK/runtime must add host support before a different value can be
installed.

### `api_version`

The plugin API compatibility line. New plugins use `0.2`. Core currently accepts
`0.2` and legacy `0.1` during migration.

Do not confuse API version with plugin package version or SDK version:

```text
plugin version   release of one integration
api_version      contract line understood by Core/plugin
SDK version      release of a language-specific authoring package
```

The first-party YouTube and Podcast plugins now declare `0.2` and require PHP
SDK `^0.3`.

### `entrypoint`

Safe package-relative executable entrypoint. Absolute paths, NULs, empty path
segments, `.` and `..` are rejected.

For current PHP plugins this is normally `stashd-plugin/plugin.php`.

## Common package/tooling fields

### `requires`

Current PHP packages can declare:

```json
{
  "requires": {
    "php": ">=8.5",
    "extensions": ["dom"]
  }
}
```

Core checks the PHP constraint and required extensions before accepting a
package.

### `architectures`

Optional supported host architectures, normally:

```json
["amd64", "arm64"]
```

The current OCI builder itself targets `linux-amd64` and `linux-arm64`.

### `helpers_lock`

Conventionally:

```json
"helpers_lock": "stashd-plugin/helpers.lock.json"
```

This records the intended helper lock location. **Current builder caveat:**
`PluginBuilder` still reads `stashd-plugin/helpers.lock.json` directly, so do not
move the lock merely because this field appears configurable.

## Helpers

Declare helper commands by logical name:

```json
{
  "helpers": {
    "ffmpeg": {
      "executable": "stashd-plugin/helpers/ffmpeg",
      "network": false
    }
  }
}
```

`executable` must be a safe package-relative path. `network` indicates whether
the helper needs network access during invocation.

The corresponding lock file pins materialised helper artifacts:

```json
{
  "schema": 1,
  "helpers": {
    "tool": {
      "version": "1.2.3",
      "license": "MIT",
      "source": "https://example.invalid/tool/1.2.3",
      "platforms": {
        "linux-amd64": {
          "url": "https://example.invalid/tool-amd64",
          "sha256": "<64 hex characters>"
        },
        "linux-arm64": {
          "url": "https://example.invalid/tool-arm64",
          "sha256": "<64 hex characters>"
        }
      }
    }
  }
}
```

For an archive, `archive_binary` identifies the path to extract from the pinned
archive artifact. The builder verifies SHA-256 before copying the executable
into the package.

The current builder expects a helper lock even when it is empty.

## Credentials

Plugins can expose credentials to Stashd without receiving raw secrets as
ordinary configuration:

```json
{
  "credentials": [
    {
      "key": "example-api",
      "label": "Example API key",
      "description": "Used for complete discovery.",
      "secret_key": "example_api_key",
      "secret_type": "api_key",
      "required": false
    }
  ]
}
```

Fields:

- `key` — plugin-facing logical credential name;
- `label` — UI label;
- `description` — optional explanatory text;
- `secret_key` — host secret-store key;
- `secret_type` — `api_key`, `oauth_token`, `password`, or `generic`;
- `required` — whether configuration is required for normal use.

A credential being optional does not mean every operation works without it. An
Input can, for example, use public feeds for `refresh` and require an API key
for `complete`.

The contract 0.2 HTTP capability carries the *logical credential name* in its
request. The host remains responsible for resolving/injecting the actual secret.

## HTTP grants

Declare the network authority a plugin needs:

```json
{
  "http_grants": [
    {
      "allowed_prefixes": ["https://api.example.com/v1/"],
      "operations": ["refresh", "complete"],
      "credential": {
        "name": "example-api",
        "parameter": "Authorization",
        "placement": "header"
      }
    }
  ]
}
```

`allowed_prefixes` is required. `operations` narrows the grant to named host
operations. `credential` asks the host to attach a configured secret to the
request. Placement is `query` or `header`.

Contract 0.2's HTTP capability itself is generic: method, URL, optional logical
credential, request headers and body; responses include status, headers and
body. The manifest controls which network authority that generic capability may
exercise.

Prefer the narrowest prefix and operation set that works. The manifest is the
security declaration, not merely UI metadata.

## Input integration

An Input manifest normally includes:

```json
{
  "kind": "input",
  "provider_key": "example",
  "source_prefixes": ["https://example.com/"],
  "source_fields": [
    {
      "key": "url",
      "label": "URL",
      "type": "text",
      "required": true,
      "description": "Channel or collection URL."
    }
  ],
  "input_options": [
    {
      "key": "include_extras",
      "label": "Include extras",
      "type": "bool",
      "default": false
    }
  ]
}
```

### `kind`

Use `input` for an Input plugin. Core's Input definition discovery requires it.

### `provider_key`

Optional provider key exposed to Core. It defaults to `id`; prefer the default
unless compatibility requires a distinct key.

### `source_prefixes`

URL prefixes used to associate incoming references with the provider. Include
only schemes/hosts the plugin actually understands.

### `source_fields`

Fields the user supplies when creating/resolving a source. Current Core accepts:

- `text`
- `number`
- `bool`
- `enum`

Each field can have `key`, `label`, `required`, `choices`, and `description` as
appropriate.

**Schema status:** `source_fields` is consumed by Core but has historically been
less completely described by the package JSON Schema than the application
parser. Treat this as application-integration surface, not evidence that
arbitrary extra manifest keys are stable.

### `input_options`

Options passed into discovery/acquisition. The shared option schema supports UI
types including `text`, `textarea`, `url`, `select`, `number`, `boolean`, and
`bool`, plus labels, defaults, choices, descriptions, and applicability hints.

Do not confuse permissive manifest/UI parsing with the contract wire values.
PHP SDK 0.3 now decodes contract DTOs strictly: malformed option variants and
wrong scalar types are rejected instead of coerced.

When introducing a new option shape/default, cover both the host integration and
plugin DTO path in tests.

### `operations`

Some first-party Inputs declare per-operation requirements, for example:

```json
{
  "operations": {
    "complete": ["credential:example-api", "helper:provider-tool"],
    "acquire": ["helper:provider-tool"]
  }
}
```

Treat this as current Core integration metadata, not a replacement for the WIT
lifecycle. Operation names must correspond to behaviour Core actually invokes.

## Broadcast integration

A file-producing Broadcast commonly includes:

```json
{
  "broadcast_key": "example",
  "application_runtime": "plugin",
  "supported_file_kinds": ["audio", "video"],
  "output_path": "index.xml",
  "output_media_type": "application/xml",
  "supports_item_rebuild": true,
  "prunes_after_publish": false,
  "ui_options": []
}
```

### `broadcast_key`

Logical key by which Core registers the Broadcast. Current Broadcast discovery
uses this field rather than requiring `kind: "broadcast"`.

### `application_runtime`

Current external Broadcast integration uses `plugin`; it defaults to `plugin`
and rejects other values. This field describes Core application integration and
is distinct from the package `runtime` (`php` today).

### `supported_file_kinds`

Media kinds the Broadcast can consume. If absent, current Core defaults to
`audio` and `video`.

### `output_path` / `output_media_type`

Optional deterministic output location and media type for a publication. Use
these for formats such as a podcast feed where a conventional output file is
part of the Broadcast behaviour.

### `supports_item_rebuild`

Whether Core may rebuild an individual item without rebuilding the entire
Broadcast.

### `prunes_after_publish`

Whether stale Broadcast-side files should be pruned after a successful publish.
Be conservative: pruning is a publication-view concern, never permission to
remove Vault assets.

### `ui_options`

Configuration fields rendered/validated by Stashd for a Broadcast. The shared
option shape supports text/textarea/url/select/number/boolean/bool and optional
defaults, choices, descriptions, etc.

### `source_options`, `actions`, `prepare_helper`

These are current application-integration fields used by some Broadcasts:

- `source_options` — configuration attached to a selected Broadcast source;
- `actions` — declarative UI/operation actions;
- `prepare_helper` — helper used during preparation.

Check the nearest first-party Broadcast and Core's
`ExternalBroadcastPluginDefinition` before introducing a new shape.

### Connection-oriented fields

Jellyfin/Plex-style Broadcasts can use host Connections. Core has integration
fields such as `connection_setting_key`, `library_setting_key`, and Broadcast
credential declarations used to build HTTP grants from the configured
Connection.

These are more application-specific than the base package contract. Prefer
copying the current media-server pattern exactly rather than generalising it in
a provider plugin. If a new integration cannot fit cleanly, that is a signal to
improve the generic host contract rather than add provider-specific Core logic.

## Jobs

Core can parse a manifest `jobs` array containing `type`, `handler`, and optional
`workload`. This is a host-application integration hook and is not part of the
language-neutral Input/Broadcast WIT.

Do **not** use it as the default way for an external plugin to smuggle Core class
names across the isolation boundary. Before adding a plugin-defined job, verify
that the current host actually owns and can resolve the handler and that the job
belongs in Core orchestration. Most provider work should be reached through the
normal Input/Broadcast lifecycle.

## Additional properties and schema drift

The current manifest schema permits additional properties in places. That lets
Core evolve integration metadata without making package installation impossible,
but it has a consequence:

> “The manifest validator accepted my field” does not mean “Core implements my
> field.”

Likewise, a field read by one Core definition class is not automatically a
language-neutral contract feature.

When adding or changing manifest surface:

1. decide whether it is package/runtime metadata, generic application metadata,
   or provider-specific behaviour;
2. keep provider-specific behaviour in the plugin;
3. update the schema when a generic field is meant to become supported public
   surface;
4. update this reference and host tests at the same time.

## Worked Input manifest

The first-party YouTube manifest demonstrates a contract-0.2 Input with source
prefixes, source fields, optional credentials, operation-scoped HTTP grants,
options, and pinned helpers:

<https://github.com/Lost-and-Fonds/youtube/blob/main/stashd-plugin/plugin.json>

## Worked Broadcast manifest

The first-party Podcast manifest demonstrates a contract-0.2 Broadcast with
supported file kinds, publication metadata, UI options, a preparation helper,
PHP requirements, and architecture declarations:

<https://github.com/Lost-and-Fonds/podcast/blob/main/stashd-plugin/plugin.json>
