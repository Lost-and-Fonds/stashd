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
   for language-neutral lifecycle, values, errors, and host capabilities.
2. Core's plugin runtime and manifest validation for current execution,
   packaging, sandbox, and application integration.
3. The SDK for the language you are writing in. At present that is the
   [PHP SDK](https://github.com/Lost-and-Fonds/stashd-php-sdk).
4. First-party plugins as worked examples, not as a replacement for the
   contract.

The current language-neutral contract is `stashd:plugin@0.2.0`. The current PHP
binding is the independently versioned `0.3.x` SDK.

This ordering is deliberate. The WIT survived the retirement of the old
Wasmtime production experiment because it describes *meaning*, not a particular
runtime. Production plugins communicate with Core as sandboxed processes using
RPC v1, but their Input/Broadcast semantics and host capabilities are defined by
the contract rather than by PHP or RPC implementation details.

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
recognises generic roles and may also report a requested role as unavailable:

- `primary`
- `captions`
- `artwork`
- `metadata`

The options may include `requested-roles`. A requested role is a capability
request, not a promise that the provider can supply it for every Item. The
result's unavailable entries carry a message and a `permanent` flag. Core can
record a permanent provider limitation and stop retrying that role, while a
temporary limitation remains eligible for a later attempt.

The plugin/helper writes into its invocation staging area and then asks the host
to stage a relative path with an optional media type. The returned reference is
opaque. The plugin does **not** choose a Vault path or promote the file itself.

This is a central Stashd invariant: acquisition creates evidence in staging;
Core performs authoritative Vault promotion, provenance, and fixity work.

Input host capabilities are invocation-scoped WIT imports. The PHP SDK exposes
them through the `PluginContext` passed to the Input factory; another SDK may use
a different language-native shape.

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

#### `operation`

`operation(operation-request)` supports explicit auxiliary actions such as
looking up choices or interacting with a configured destination. Treat operation
names as plugin-owned public API: stable names, validated payloads, predictable
results.

#### Broadcast capabilities

The four WIT lifecycle functions take request values only, but the
`broadcast-world` imports host capabilities for the invocation. In PHP SDK 0.3,
**all four** Broadcast methods receive a `PluginContext` as a language binding
for those imports:

```php
prepare(PublishRequest $request, PluginContext $context)
publish(PublishRequest $request, PluginContext $context)
finalize(FinalizationRequest $request, PluginContext $context)
operation(OperationRequest $request, PluginContext $context)
```

`PluginContext` is therefore a PHP ergonomic binding, not an extra
language-neutral ABI parameter. Do not attach host capabilities to request DTOs
or copy the PHP presentation mechanically into a future SDK.

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

Contract 0.2 defines a generic HTTP request with:

- method: `GET`, `POST`, `PUT`, `PATCH`, or `DELETE`;
- URL;
- optional logical credential name;
- arbitrary request headers;
- request body bytes.

The response includes status, headers, and body bytes. This is intentionally
provider-neutral: do not grow provider-specific HTTP helpers in Core merely to
avoid using the generic request shape.

The manifest declares allowed URL prefixes and, optionally, which operations can
use them. Credentials can be attached by the host as a query parameter or
header.

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

Host HTTP failures include denied access, unavailable credentials,
authentication rejection, rate limiting, upstream unavailability, and generic
failure. Map provider-facing lifecycle failures to the plugin error model when
they cross back through `resolve`, `publish`, etc.

### Credentials

The plugin declares a logical credential key and the host maps it to an
encrypted Stashd secret. Plugin code requests HTTP using the credential's
logical name; it should not need the raw secret value.

This separation matters because it prevents credentials from becoming ordinary
plugin data. Do not persist or log them.

### Staging

Staging is the only writable publication/acquisition workspace a plugin should
assume. Paths supplied to staging calls are invocation-relative and validated at
the host boundary.

Input staging can run a declared helper and stage an artifact. Broadcast
staging can run a helper, write bytes, and stage files.

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

Both Input and Broadcast progress calls support an optional fractional progress
value in addition to the human-readable stage.

## 4. Error semantics

Input and Broadcast now share the same seven language-neutral plugin failure
categories:

- `unsupported`
- `not-found`
- `authentication`
- `rate-limited`
- `unavailable`
- `invalid-data`
- `failed`

Each carries:

```json
{
  "message": "human-readable explanation",
  "retryable": true
}
```

On the wire the result error is a WIT-style variant, for example:

```json
{
  "tag": "rate-limited",
  "value": {
    "message": "remote quota exceeded",
    "retryable": true
  }
}
```

Choose errors by what the caller can do next. “The provider returned 503” is
usually `unavailable` and retryable; “this URL is not a channel” is
`unsupported` or `invalid-data` and is not fixed by retrying.

PHP SDK 0.3 exposes typed plugin failures instead of inferring meaning from
exception strings. Throw a `PluginFailureException` carrying a `PluginFailure`
and `PluginErrorCode` when provider logic intentionally returns a contract
failure. Unclassified exceptions are converted to non-retryable `failed`.
Capability unavailability is mapped to retryable `unavailable`.

Core understands the typed `{tag,value}` form and preserves retryability through
Input, download, Broadcast, and Connection error boundaries. It also accepts the
legacy flat error form while 0.1 plugins are being migrated; new plugins should
not emit that legacy shape.

## 5. DTO and wire strictness

Contract values should be treated as typed values, not vaguely shaped JSON.
PHP SDK 0.3 rejects malformed required fields, list entries, variants, and
integer values instead of silently coercing them.

That means plugin tests should catch malformed data early rather than depend on
behaviour such as:

- missing strings becoming `""`;
- numeric strings becoming integers;
- malformed option values being skipped;
- invalid variants quietly falling back to defaults.

If a field is required by WIT, provide it with the correct type. If it is an
`option<T>`, use `null` only where the contract permits it.

## 6. Manifest and application integration

Every package has a `plugin.json`. Its stable package identity fields are:

- `id`
- `name`
- `version`
- `runtime`
- `api_version`
- `entrypoint`

Current production packages normally place it at
`stashd-plugin/plugin.json`. New plugins should declare `api_version: "0.2"`.
Core currently accepts `0.1` as an explicit migration compatibility line, but
first-party plugins have moved to 0.2.

The manifest also declares the host-side information Core needs to wire the
plugin without understanding the provider: source prefixes and fields, UI
options, HTTP grants, credentials, helpers, supported file kinds, publication
behaviour, and similar metadata.

See [Manifest reference](manifest.md) before adding a field. The JSON schema is
intentionally permissive in places and some application-integration fields are
parsed by Core classes rather than fully described by the schema. Existing use
is not automatically a frozen public API.

## 7. Package layout and OCI build

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

## 8. Installation, activation, and development links

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

## 9. RPC v1 and cross-language compatibility

Ordinary plugin authors should mostly ignore transport details, but SDK authors
and boundary tests need a stable representation.

RPC v1 uses:

```text
4-byte unsigned big-endian JSON byte length
UTF-8 JSON object payload
```

The plugin initiates a hello request advertising its supported protocol range;
Core replies with the selected v1 range before lifecycle dispatch begins.

The JSON mapping follows the contract compatibility rules:

- scalars → JSON scalars;
- records → objects;
- lists → arrays;
- enums → strings;
- variants → `{"tag": "...", "value": ...}` for payload-bearing cases;
- results → exactly one of `{"ok": ...}` or `{"error": ...}`;
- resource references → opaque invocation-scoped handles.

Inline WIT byte values use the host's current JSON string representation in RPC
capability payloads. `resource.read` chunks use base64.

Do not infer these details from one PHP class. The language-neutral conformance
fixtures in `plugin-api/tests/contract/fixtures/` are specifically intended to
be replayed by future SDKs.

## 10. Testing strategy

A plugin should be testable without booting the whole Stashd application.

At minimum test:

- source/setting parsing and validation;
- canonicalisation and provider-ID stability;
- refresh versus complete discovery behaviour;
- provider response mapping to contract DTOs;
- all relevant typed error cases and retryability;
- acquisition/publication output shapes;
- helper argument construction without invoking arbitrary host tools;
- capability denial/unavailability paths;
- strict rejection of malformed contract values;
- manifest/package assumptions relevant to the plugin.

For PHP plugins, use the SDK and first-party plugin contract tests as the model
and run the plugin repository's own Composer scripts (`composer test`, static
analysis, lint/format checks).

When changing the contract itself, also run the `plugin-api` contract suite:

```bash
./tests/contract/run.sh
```

When changing SDK mapping, exercise the cross-language fixtures as well. When
changing Core's runtime/host integration, follow Core's testing rules and use
`./bin/test`; do not invoke Pest/PHPUnit directly on the host.

## 11. First-party examples worth copying

### YouTube Input

Use [`Lost-and-Fonds/youtube`](https://github.com/Lost-and-Fonds/youtube) for:

- an Input factory receiving `PluginContext`;
- URL prefix/source-field declarations;
- cheap refresh versus more expensive complete discovery;
- optional Data API credentials;
- operation-scoped HTTP grants;
- multiple pinned helper artifacts;
- staging primary media, metadata, artwork, and captions;
- an `api_version: "0.2"` Input package using PHP SDK `^0.3`.

Copy the *shape*, not YouTube-specific assumptions.

### Podcast Broadcast

Use [`Lost-and-Fonds/podcast`](https://github.com/Lost-and-Fonds/podcast) for:

- a file-producing Broadcast;
- `PluginContext` on all Broadcast lifecycle calls;
- UI options;
- supported input file kinds;
- a declared `ffmpeg` helper;
- a deterministic output path/media type;
- preparation followed by publication;
- an `api_version: "0.2"` Broadcast package using PHP SDK `^0.3`.

Jellyfin and Plex are better references for Broadcasts that need a configured
external Connection and server-side operations.

## 12. Design rules that save review time

- Keep provider semantics out of Core.
- Do not parse provider responses in Core “just for this one field.”
- Do not ask for direct Vault or database access.
- Do not make a plugin a Core Composer dependency.
- Do not assume PHP DTOs are the protocol.
- Do not attach capabilities to contract request DTOs because one SDK once did.
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

## 13. Completion checklist

Before calling a plugin complete, verify all of the following:

- The plugin implements an existing WIT world (Input or Broadcast).
- New work targets contract `0.2` and a compatible current SDK.
- Its stable IDs/references are documented and tested.
- Manifest identity/version/runtime/API fields are valid.
- All required network destinations are declared narrowly.
- Credentials are host-owned and never logged/persisted by the plugin.
- Helper binaries are declared, pinned by checksum for supported platforms,
  and invoked through host capabilities.
- No code assumes direct DB/Vault/host filesystem access.
- `refresh`/`complete` or `prepare`/`publish` semantics are distinct where they
  need to be.
- Typed errors and retryability are tested.
- Malformed DTO/wire values fail loudly in tests.
- The repository has focused contract tests and its normal lint/static checks
  pass.
- The OCI build succeeds for each advertised architecture.
- A produced artifact can be installed and invoked by a compatible Stashd Core.
- Documentation states any provider/API limitations rather than hiding them in
  implementation details.
