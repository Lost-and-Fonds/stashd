# Authoring Stashd plugins

This guide explains how to design a Stashd plugin against the stable boundary
rather than against incidental Core internals. It is intentionally useful both
to plugin authors and to people implementing future SDKs.

For PHP-specific class signatures and a minimal repository skeleton, see
[PHP SDK](php-sdk.md). For every `plugin.json` field, see
[Manifest reference](manifest.md).

## 1. Start with the boundary, not Core

The source-of-truth order is:

1. [`plugin-api` WIT](https://github.com/Lost-and-Fonds/plugin-api/tree/main/wit)
   for language-neutral lifecycle and value semantics.
2. Core's plugin runtime and manifest validation for current execution,
   packaging, sandbox, and application integration.
3. The SDK for the language you are writing in. At present that is the
   [PHP SDK](https://github.com/Lost-and-Fonds/stashd-php-sdk).
4. First-party plugins as worked examples, not as a replacement for the
   contract.

This ordering is deliberate. The WIT survived the retirement of the old
Wasmtime production experiment because it describes *meaning*, not a particular
runtime. Production plugins now communicate with Core as sandboxed processes
using RPC v1, but their Input and Broadcast shapes still follow that contract.

## 2. Choose the contract world

### Input: source → discovery → acquisition

An Input plugin answers three different questions. Keeping them separate makes
refreshes cheap and preservation deterministic.

#### `resolve`

`resolve(source)` receives typed source fields and returns a `resolved-input`.
It should:

- reject sources the plugin does not support;
- canonicalise the provider reference when possible;
- return a stable provider-side ID;
- return useful display metadata such as title and artwork;
- provide size/item estimates only when it can do so honestly.

Resolving is not acquisition. Do not download the media merely to prove a URL
exists if a cheaper provider request can answer the question.

#### `discover`

`discover(input-id, intent, options)` returns `discovered-item` records.

`refresh` is the normal recurring path. Optimise it for inexpensive discovery
of new/current material. `complete` means the caller wants the provider's full
available set and may justify a more expensive API/helper path.

A discovered item needs a stable `id`, a retrievable `reference`, and a title.
Other fields — description, publication time, artwork, duration, provider kind,
size, and opaque `upstream-state` — let Stashd make useful decisions without
learning the provider's schema.

`upstream-state` is deliberately opaque to Core. Use it for provider state that
helps a later comparison, not as a place to smuggle arbitrary Core semantics.

#### `acquire`

`acquire(item, options)` produces one or more staged artifacts. The Input host
recognises generic roles:

- `primary`
- `captions`
- `artwork`
- `metadata`

The plugin/helper writes into its invocation staging area and then asks the host
to stage a relative path with a role and optional media type. The returned
reference is opaque. The plugin does **not** choose a Vault path or promote the
file itself.

This is a central Stashd invariant: acquisition creates evidence in staging;
Core performs authoritative Vault promotion, provenance, and fixity work.

### Broadcast: Vault material → disposable publication

A Broadcast receives a `publish-request` containing settings, sources, and
items/resources chosen by Core.

#### `prepare`

`prepare(request)` is for derived artifacts required before publication, such as
transcoding or format conversion. It returns a `preparation` containing derived
artifacts tied to the source resource through a derivation key.

Keep derivation deterministic where practical. Broadcast outputs are disposable
views of the Vault; Stashd should be able to rebuild them.

#### `publish`

`publish(request)` creates the publication itself. A publication contains:

- an artifact reference;
- zero or more `published-file` mappings;
- optional published metadata/settings.

For a podcast this can be the generated feed artifact plus mappings to episode
files. For a media-server Broadcast it may describe the assembled view that
Core exposes to the server.

#### `finalize`

`finalize(finalization-request)` runs after publication exists. Use it for work
that logically depends on the completed publication, for example notifying an
external service. It returns the resulting publication.

The PHP SDK passes a `PluginContext` to this method as an ergonomic capability
container. That extra PHP parameter is **not part of the language-neutral WIT
signature**; the SDK constructs it from host capability calls.

#### `operation`

`operation(operation-request)` supports explicit auxiliary actions such as
looking up choices or interacting with a configured destination. Treat operation
names as plugin-owned public API: stable names, validated payloads, predictable
results.

Again, PHP currently supplies a `PluginContext` parameter for convenience. A
future SDK may expose those capabilities differently.

## 3. Use capabilities instead of reaching around the sandbox

A plugin is not a small Core module. It runs as an isolated process.

Current production sandboxing uses Bubblewrap with:

- a read-only plugin package mounted at `/plugin`;
- a read-only PHP SDK mount at `/sdk` for the PHP runtime;
- a writable invocation staging directory at `/staging`;
- a private `/tmp`;
- cleared environment;
- isolated user, PID, IPC, and UTS namespaces;
- network namespace isolation unless the invocation explicitly needs network;
- no Vault, database, application data, user home, or runtime sockets exposed.

Therefore the correct question is never “how do I get the Vault path?” It is
“which host capability should provide the resource I need?”

### HTTP

The host HTTP capability is the normal way to call provider APIs. The manifest
declares allowed URL prefixes and, optionally, which operations can use them.
Credentials can be attached by the host as a query parameter or header.

Keep grants narrow. Prefer:

```text
https://api.example.com/v1/
```

over:

```text
https://api.example.com/
```

when only the former is necessary. Never construct a grant broad enough merely
to make development easier.

### Credentials

The plugin declares a logical credential key and the host maps it to an
encrypted Stashd secret. Plugin code requests HTTP using the credential's
logical name; it should not need the raw secret value.

This separation matters because it prevents credentials from becoming ordinary
plugin data. Do not persist or log them.

### Staging

Staging is the only writable publication/acquisition workspace a plugin should
assume. Paths supplied to staging calls are package/invocation-relative and are
validated at the host boundary.

Input staging can run a declared helper and stage an artifact with a generic
role. Broadcast staging can run a helper, write bytes, and stage files.

### Helpers

Use a helper when a mature external tool already solves the difficult part of
the integration. `yt-dlp` is a good example: Stashd should orchestrate it rather
than reimplement it.

Helpers are declared in `plugin.json` and pinned by URL and SHA-256 per platform
in `stashd-plugin/helpers.lock.json`. The builder materialises those exact
artifacts into the OCI package. A helper can be marked `network: true` when it
needs network access.

Do not shell out to an undeclared executable and do not download an executable
at runtime. Build-time pinning makes the package reproducible and auditable.

### Logging and progress

Use host logging and progress capabilities rather than writing a second status
system. Logs are diagnostic; progress is structured job state. Neither may
contain secrets.

## 4. Error semantics

The language-neutral contract carries structured plugin errors with a message
and a `retryable` flag.

Input defines:

- `unsupported`
- `not-found`
- `authentication`
- `rate-limited`
- `unavailable`
- `invalid-data`
- `failed`

Broadcast currently defines:

- `unsupported`
- `not-found`
- `unavailable`
- `invalid-data`
- `failed`

Choose errors by what the caller can do next. “The provider returned 503” is
usually unavailable/retryable; “this URL is not a channel” is unsupported or
invalid-data and is not fixed by retrying.

**Current PHP transport caveat:** error plumbing is not yet as rich as the WIT
model. The Input server currently maps thrown exception messages to wire error
codes heuristically, and the Broadcast server turns uncaught exceptions into a
generic non-retryable plugin failure. Do not build provider semantics around
those implementation quirks. Treat the WIT error model as the direction of the
public contract and keep plugin exceptions/messages precise until the SDK's
typed error transport is completed.

## 5. Manifest and application integration

Every package has a `plugin.json`. Its stable package identity fields are:

- `id`
- `name`
- `version`
- `runtime`
- `api_version`
- `entrypoint`

Current production packages normally place it at
`stashd-plugin/plugin.json`.

The manifest also declares the host-side information Core needs to wire the
plugin without understanding the provider: source prefixes and fields, UI
options, HTTP grants, credentials, helpers, supported file kinds, publication
behaviour, and similar metadata.

See [Manifest reference](manifest.md) before adding a field. The JSON schema is
currently intentionally permissive and some application-integration fields are
parsed by Core classes rather than fully described by the schema. Existing use
is not automatically a frozen public API.

## 6. Package layout and OCI build

A typical current PHP plugin looks like:

```text
plugin-repo/
├── composer.json
├── composer.lock
├── src/
├── stashd-plugin/
│   ├── plugin.json
│   ├── plugin.php
│   └── helpers.lock.json
└── tests/
```

The Core `PluginBuilder` currently:

1. reads `stashd-plugin/plugin.json` and
   `stashd-plugin/helpers.lock.json`;
2. hashes those files, `composer.lock`, and the target platform for its build
   cache;
3. copies the source while removing `.git`, tests, tools, and any existing
   vendor directory;
4. requires `composer.lock` and performs a locked production Composer install
   with scripts/plugins disabled;
5. downloads each helper artifact from the lock file and verifies SHA-256;
6. constructs a Linux OCI layout with `umoci`.

The current builder supports `linux-amd64` and `linux-arm64`.

Even a plugin with no helper binaries should follow the current builder shape
and include an empty helper lock:

```json
{
  "schema": 1,
  "helpers": {}
}
```

The manifest has a `helpers_lock` field, but the current builder specifically
reads `stashd-plugin/helpers.lock.json`. Treat that fixed path as current tooling
behaviour until the builder and schema are made fully declarative.

## 7. Installation, activation, and development links

Core stores installed versions separately from the active version. Packages are
immutable once installed. Activation changes a symlink; rollback activates an
older installed version.

The package manager also supports development links internally. A linked source
still needs a valid manifest and entrypoint; linking is not a bypass around the
contract.

Production installation is by OCI reference:

```bash
docker compose exec stashd php tempest stashd:plugin-install \
  ghcr.io/lost-and-fonds/example:<version>
```

Use the plugin's release workflow to build/publish artifacts rather than
checking `vendor/` or downloaded helper binaries into source control.

## 8. Testing strategy

A plugin should be testable without booting the whole Stashd application.

At minimum test:

- source/setting parsing and validation;
- canonicalisation and provider-ID stability;
- refresh versus complete discovery behaviour;
- provider response mapping to contract DTOs;
- error cases (unsupported, missing, auth, rate limit, malformed data);
- acquisition/publication output shapes;
- helper argument construction without invoking arbitrary host tools;
- capability denial/unavailability paths;
- manifest/package assumptions relevant to the plugin.

For PHP plugins, use the SDK and first-party plugin contract tests as the model
and run the plugin repository's own Composer scripts (`composer test`, static
analysis, lint/format checks).

When changing the contract itself, also run the `plugin-api` contract suite:

```bash
./tests/contract/run.sh
```

When changing Core's runtime/host integration, follow Core's testing rules and
use `./bin/test`; do not invoke Pest/PHPUnit directly on the host.

## 9. First-party examples worth copying

### YouTube Input

Use [`Lost-and-Fonds/youtube`](https://github.com/Lost-and-Fonds/youtube) for:

- an Input factory receiving host capabilities;
- URL prefix/source-field declarations;
- cheap refresh versus more expensive complete discovery;
- optional Data API credentials;
- operation-scoped HTTP grants;
- multiple pinned helper artifacts;
- staging primary media, metadata, artwork, and captions.

Copy the *shape*, not YouTube-specific assumptions.

### Podcast Broadcast

Use [`Lost-and-Fonds/podcast`](https://github.com/Lost-and-Fonds/podcast) for:

- a file-producing Broadcast;
- UI options;
- supported input file kinds;
- a declared `ffmpeg` helper;
- a deterministic output path/media type;
- preparation followed by publication.

Jellyfin and Plex are better references for Broadcasts that need a configured
external Connection and server-side operations.

## 10. Design rules that save review time

- Keep provider semantics out of Core.
- Do not parse provider responses in Core “just for this one field.”
- Do not ask for direct Vault or database access.
- Do not make a plugin a Core Composer dependency.
- Do not assume PHP DTOs are the protocol.
- Do not invent a new lifecycle method without first changing the canonical
  contract.
- Do not add broad network grants when a narrow prefix is sufficient.
- Do not ship mutable/unpinned helper downloads.
- Do not silently downgrade `complete` discovery to refresh semantics.
- Do not invent exact sizes/counts when the provider only offers estimates.
- Do not let Broadcast output become canonical state; the Vault remains the
  archive.
- Prefer a maintained upstream tool/library over reimplementing a mature
  protocol, but keep provider-specific orchestration in the plugin.

## 11. Completion checklist

Before calling a plugin complete, verify all of the following:

- The plugin implements an existing WIT world (Input or Broadcast).
- Its stable IDs/references are documented and tested.
- Manifest identity/version/runtime/API fields are valid.
- All required network destinations are declared narrowly.
- Credentials are host-owned and never logged/persisted by the plugin.
- Helper binaries are declared, pinned by checksum for supported platforms,
  and invoked through host capabilities.
- No code assumes direct DB/Vault/host filesystem access.
- `refresh`/`complete` or `prepare`/`publish` semantics are distinct where they
  need to be.
- Error and retry behaviour is tested.
- The repository has focused contract tests and its normal lint/static checks
  pass.
- The OCI build succeeds for each advertised architecture.
- A produced artifact can be installed and invoked by a compatible Stashd Core.
- Documentation states any provider/API limitations rather than hiding them in
  implementation details.
