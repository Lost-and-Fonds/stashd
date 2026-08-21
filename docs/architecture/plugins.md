# Plugin architecture

## Status

**Validated experimental architecture, not a final Plugin API.**

The [v0.1 plugin architecture spike](PLUGIN-SPIKE.md) established that Stashd
can use a PHP/Tempest application, a small trusted Rust host, and WebAssembly
Components connected by WIT without duplicating the Stashd domain model or
giving plugins direct access to the Vault.

The decision is to proceed with direct Wasmtime and WIT as the experimental
substrate. The stable plugin contracts, permissions, distribution model, and
production Input and Broadcast APIs remain undesigned.

This architecture is separate from the existing in-tree PHP
`BroadcastPlugin` extension point described in the
[broadcast plugin architecture plan](Broadcast-Plugin-Architecture-Plan.md).
That extension point organizes trusted Stashd code. It is not the sandboxed
third-party Component API described here.

## Why Stashd needs plugins

Stashd cannot reasonably ship first-party support for every source, external
service, export target, or homelab integration. Plugins provide a way to add
those capabilities without moving provider-specific or deployment-specific
logic into Stashd core.

The boundary must preserve what makes Stashd dependable:

- the Vault remains canonical;
- preservation decisions remain auditable and controlled by Stashd;
- untrusted code cannot become a second application with database or
  filesystem authority;
- users continue to interact with one coherent Stashd product;
- a plugin failure can be contained without corrupting authoritative state.

Plugins are therefore capability providers, not arbitrary application
extensions.

## Information architecture remains in core

> Plugins extend Stashd's capabilities, not its information architecture.

Plugins may provide facts, operations, and bounded capabilities. Stashd owns:

- navigation;
- UI and presentation;
- domain concepts;
- the preservation model;
- user workflows.

Plugins must not create arbitrary:

- pages;
- routes;
- navigation entries;
- UI components;
- alternate workflows.

This keeps the product understandable and prevents each plugin from inventing
its own interaction model. Stashd interprets plugin facts and capabilities and
decides how, where, and whether to present them.

## Validated boundary

```text
                 STASHD

        PHP / Tempest application
                  |
                  |
          private local IPC
                  |
                  v

        Rust plugin host
        Wasmtime Component Model

                  |
                  |
             WIT interfaces
                  |
                  v

          Plugin components
```

There are three deliberately different boundaries:

1. PHP/Tempest owns the Stashd application and authoritative state.
2. The Rust host owns containment and translates invocation-scoped grants into
   Component resources.
3. WIT defines the prospective language-neutral contract visible to plugin
   Components.

Neither the private PHP-to-Rust protocol nor Wasmtime itself is the plugin
contract.

## PHP owns truth

The PHP/Tempest application is the authoritative Stashd application. It owns:

- Stashes;
- Inputs;
- Items;
- Assets;
- Broadcasts;
- Connections;
- database state;
- plugin installation and enabled state;
- plugin configuration persistence;
- secrets at rest;
- scheduling;
- durable operation records;
- preservation semantics;
- provenance and history;
- integrity and fixity records;
- staging-to-Vault promotion;
- API and UI interpretation.

The Rust host must not become a second Stashd application. It must not acquire
an authoritative database, durable domain state, or its own interpretation of
Stash, Asset, or Broadcast lifecycle rules. Restarting or replacing the host
must not lose authoritative Stashd state.

## Rust owns containment

The Rust plugin host is trusted Stashd infrastructure. Plugin Components are
not trusted.

The host boundary owns:

- the Wasmtime Component Model runtime;
- WIT-generated host bindings;
- component loading and validation;
- sandboxing and filesystem enforcement;
- invocation-scoped resource handles;
- execution limits;
- cancellation and forced termination mechanisms;
- plugin crash and trap isolation;
- progress and log forwarding;
- transient component and runtime caches.

### Normal runtime provisioning

Development and production-like application containers provision one shared
`stashd-plugin-host` process. It listens on the private Unix socket named by
`STASHD_PLUGIN_HOST_SOCKET`; bundled Input and Broadcast Component paths are
supplied by their manifests through environment overrides. PHP lifecycle code
uses the same registry/adapter path as tests and does not launch a host per
operation.

If the host, socket, or Component is unavailable, external plugin execution
returns a generic unavailable failure. It must not silently switch to a
provider implementation in application code once an external implementation
is registered.

Some of these responsibilities, especially production limits, cancellation,
network enforcement, and stronger operating-system isolation, were not fully
solved by the spike. They belong at this boundary when designed; they do not
belong in plugins or in a duplicated Rust domain layer.

## WIT and the Component Model

WIT is the prospective plugin contract definition layer. It describes:

- interfaces;
- typed records, variants, and errors;
- resources;
- imports and exports;
- versioned contracts.

WIT is not:

- the PHP service API;
- the Stashd database model;
- the frontend API;
- the PHP-to-Rust transport protocol.

The Component Model and WIT were selected as the validated experimental
substrate because they provide:

- typed interfaces across language boundaries;
- language-neutral generated bindings;
- capability-oriented resources;
- structured results and errors;
- a path toward future async and stream types.

Experimental package versions such as `stashd:plugin@0.1.0` are evidence from
the spike, not a compatibility promise. A final Stashd Plugin API does not yet
exist.

## Capability and resource model

The capability model is the central security and architecture decision.
Plugins should not receive internal Stashd implementation details and then be
asked to behave responsibly. They should receive only the capabilities granted
for one invocation.

A plugin should not receive:

- internal filesystem paths;
- raw database IDs;
- direct database access;
- unrestricted secrets;
- arbitrary shell or process execution;
- writable Vault access.

Instead, PHP decides what an invocation may do, and the Rust host materializes
those grants as opaque WIT resources. The Component sees resource handles, not
the paths, records, or credentials behind them. When the invocation ends, its
handles cease to be valid.

### Vault Asset resource

A `vault-asset` represents a preserved Asset through a read-only capability.
An eventual contract may permit a plugin to:

- inspect explicitly exposed metadata;
- obtain the Asset size;
- read bounded chunks of bytes.

It must not permit a plugin to:

- discover the underlying Vault path;
- mutate or replace bytes;
- change Asset state;
- write into the Vault.

The spike validated this shape with opaque `size` and chunked `read`
operations and no mutation operation. The exact methods and streaming model
are still experimental.

### Staging output resource

A `staging-output` represents a temporary writable output granted for an
invocation. It may allow a plugin to:

- create generated output;
- write staged data;
- finish and return an opaque staged-output reference.

It must not allow a plugin to:

- declare the output preserved;
- choose an arbitrary destination path;
- promote the output into the Vault;
- create authoritative Asset or provenance records.

Staging is the only writable data boundary validated by the spike.

## Preservation invariant

> Only Stashd core can declare data preserved.

```text
Plugin
  |
  | capabilities and staged results
  v

Stashd core
  |
  | validation / fixity / provenance / promotion
  v

Vault
```

Plugins may:

- discover data;
- acquire data through granted capabilities;
- transform data;
- generate outputs;
- request operations;
- return facts and typed results.

Only Stashd core performs:

- validation;
- fixity calculation;
- deduplication;
- provenance recording;
- preservation event recording;
- Vault commit.

This is more than a filesystem rule. Preservation is a Stashd domain decision,
so it cannot be delegated to an untrusted Component or inferred merely because
a plugin produced a file.

## Private plugin-host IPC

PHP and the Rust host communicate through private local IPC. Its purpose is to:

- inspect or invoke a Component;
- communicate invocation-scoped grants;
- forward progress and logs;
- return typed results or intelligible execution errors;
- support cancellation when production semantics are defined.

The IPC protocol is an implementation detail that may change in lockstep with
Stashd releases. It is not a public Stashd API and must not mirror domain CRUD
operations such as creating Stashes, querying Assets, or updating Broadcasts.
Domain-oriented PHP services should hide transport and runtime mechanics from
their callers.

The spike proved that a small request/event/result exchange over a local Unix
socket is straightforward. It did not establish a stable wire format, so this
document intentionally does not specify one.

## Prospective plugin contributions

These are future design targets, not implemented or stable APIs.

### Input capability

A plugin may contribute ways to discover or acquire Items. Stashd remains
responsible for turning returned facts and staged data into Inputs, Items,
Assets, operations, and preservation records.

### Broadcast capability

A plugin may contribute ways to generate outputs from granted read-only Assets
and writable staging resources. Stashd remains responsible for Broadcast
lifecycle, publication policy, verification, and user presentation.

The experimental publication capability gives a plugin a bounded way to expose
an existing Asset or a generated file through a core-owned opaque URL. Core
stores the publication, applies its public or credential-protected access
policy, and serves the bytes (including media range handling) through one
generic endpoint. Plugins receive URLs as opaque values; they do not register
HTTP routes or receive filesystem paths. Publication destinations and
credential-use rules are invocation/application data, not provider policy in
the generic host.

### Connection capability

A plugin may contribute a reusable integration with an external service.
Stashd owns Connection records, configuration persistence, secret storage,
permission grants, and presentation.

### Configuration contribution

A plugin may describe installation-level configuration it needs. Stashd owns
validation, persistence, secret handling, and any configuration UI. The schema
and UI-hint format have not been selected.

### Health and status contribution

A plugin may report operational facts, diagnostics, or capability availability.
Stashd decides how those facts affect health state and how they appear in the
API or UI.

Across all contribution types, plugins provide facts and capabilities; Stashd
decides presentation and authoritative state transitions.

## What the spike validated

The v0.1 spike demonstrated that:

- PHP can invoke a Wasm Component through a separate Rust host;
- direct Wasmtime Component Model hosting is practical without Extism;
- WIT can define host and guest interfaces independently of PHP and the
  database schema;
- generated bindings work on both sides of the Component boundary;
- opaque resources can model a read-only Vault Asset and writable staging;
- the plugin does not need the underlying Vault path;
- progress, logs, typed results, and errors can cross the private IPC boundary;
- typed plugin failures, malformed Components, and traps can be isolated
  without crashing PHP or the host's next invocation;
- the Rust host can remain essentially stateless and restartable.

The spike did not prove production performance, a complete sandbox policy, or
the final shape of any real Input, Broadcast, Connection, or configuration
contract.

## Consequences and tradeoffs

The architecture introduces a second trusted implementation language and a
local process boundary. That adds build, packaging, observability, and runtime
complexity. In return, untrusted plugin code is separated from PHP, the
database, and Vault authority, and the prospective plugin contract is not tied
to PHP internals.

WIT resources fit the capability model well, but resource ergonomics and
generated bindings are more involved than ordinary in-process interfaces.
Progress and synchronous request/result plumbing are straightforward; robust
async execution, streaming, and cancellation will require deliberate design.

Wasmtime remains a runtime implementation choice rather than part of the
public contract. The spike found no evidence that adding Extism would
materially simplify the validated boundary, so it is not part of the current
architecture.

## Deferred questions and future work

### Streaming large media

Determine the final WIT stream or resource patterns for:

- very large Vault Assets;
- downloads;
- generated outputs;
- backpressure and bounded memory use.

The spike's chunked reads and in-memory staging are evidence only, not a
production media pipeline.

### Cancellation

Define production semantics for:

- cooperative cancellation;
- forced termination and execution limits;
- cleanup of partially written staging output;
- reporting cancellation versus failure;
- host restart during an invocation.

### Distribution

The following are not designed:

- plugin catalog and discovery;
- OCI distribution;
- signing and verification;
- trust and update policy;
- compatibility negotiation and migrations.

### UI configuration schema

The following are not designed:

- JSON Schema or another configuration schema;
- UI hints;
- dynamic forms;
- validation and configuration migration behavior.

Any eventual schema describes facts for Stashd to render; it does not grant a
plugin arbitrary UI.

### Permissions model

The production permissions model is not designed. The YouTube experiment has
only a narrow invocation-scoped exception: an allowlisted HTTPS HTTP resource
and named credential-use grant, with host-side secret injection and no secret
read capability. It does not establish a general permissions UI, credential
system, or audit model.

### Production Input and Broadcast APIs

The production Input and Broadcast contracts are not designed. Before either
can be considered stable, Stashd must define lifecycle semantics, retries,
idempotency, durable operation records, provenance, staging cleanup, resource
limits, and failure recovery without leaking its database model into WIT.

### Additional open questions

- Which Component languages and toolchains will Stashd support and test?
- How are plugin versions matched to experimental or future stable WIT worlds?
- Which runtime caches are safe and useful without becoming authoritative
  state?
- How should plugin logs be redacted, attributed, retained, and exposed?
- What deployment changes are needed to enforce read-only Vault visibility for
  the host and read/write staging at the operating-system level?

These questions should be resolved through bounded follow-up spikes. They must
not be answered by turning the private IPC protocol into a second domain API or
by granting Components broader access to Stashd internals.

## Experimental YouTube Input Component

### Semantic boundary rule

Public plugin contracts describe Stashd semantics, not the implementation
mechanisms of the first plugin that needed them. Provider-specific behavior
belongs inside the provider plugin; core must not need to understand a
provider's URLs, feeds, APIs, credentials, helper tools, or identifiers.

Runtime capabilities such as HTTP, credential use, helper execution, and
staging are infrastructure available to implementations. They are not Input
semantics or arguments that define what `resolve`, `discover`, and `acquire`
mean. Before adding a field to a generic Input contract, ask whether it would
still make sense for a local-folder Input.

The experimental semantic contract is intentionally small:

- `resolve(source)` returns an opaque resolved input identity and optional
  canonical reference, kind, title, artwork reference, and estimate.
- `discover(input-id, intent)` accepts `refresh` or `complete` intent. The
  plugin chooses its own mechanisms and fallback behavior.
- `acquire(item, options)` returns generic staged artifact facts for core to
  validate and preserve.

The experimental boundary transports declared Input option values as a small
generic boolean/text list. Stashd persists and forwards those values without
interpreting provider-owned keys; the plugin decides what they mean. A
manifest may declare the option metadata used by the API/UI, while the
implementation package identity remains separate from the durable logical
provider identity.

The generic WIT world is named `input-world`; provider identity belongs to the
plugin package, not the world name.

The first real plugin implementation lives under `plugins/youtube/`. It is a
standalone Rust WebAssembly Component compiled for `wasm32-wasip2`; it does
not call Stashd's PHP provider. It is now the implementation selected for
logical provider `youtube`; the former built-in implementation has been
removed.

The Component exports `resolve`, `discover`, and `acquire`. It parses YouTube
references and upstream responses inside Wasm, returning only semantic
resolved-input and discovered-item facts. PHP may translate those facts into
authoritative Stashd records, but the plugin never receives database objects or
PHP provider classes.

The experimental Input world accepts a discovered item and semantic media
options, then returns staged artifacts with generic roles: `primary`,
`captions`, `artwork`, and `metadata`. The host
validates each artifact against the invocation's private staging workspace and
returns only a safe relative reference, role, media type, and size. The plugin
cannot return arbitrary filesystem paths or promote anything into the Vault.

The YouTube Component currently fulfills acquisition by requesting the generic
granted helper named `yt-dlp`, constructing and interpreting all yt-dlp
arguments and output itself. The host only runs the granted executable in the
staging workspace, captures its result, and reports newly created files. This
is trusted installed helper software for the development experiment, not a
general hostile-native-code sandbox or a YouTube-specific host bridge.

The host grants invocation-scoped runtime resources internally. In fixture mode
it reads the fixture mapping supplied by the invocation; outside fixture mode
Rust performs the HTTP request. HTTP destinations and credential-use rules are
provided as generic invocation grants containing approved HTTPS URL prefixes
and, where needed, a named credential with its declared placement. The host
does not contain a provider allowlist; the plugin registration supplies the
provider-specific grant data. Redirects are disabled. This is an experimental
capability model, not the final network permission system.

For the bundled YouTube plugin, `refresh` currently uses RSS while `complete` requests
the channel uploads playlist, pages `playlistItems`, then batches `videos`
details. These requests use the same HTTP resource with a named credential-use
intent (`youtube-data-api`). PHP decides whether the invocation receives that
grant; the Rust host validates the invocation-supplied destination and
credential grant, then injects the credential using the grant's declared
query-parameter placement. The raw secret is never returned to Wasm.
Redirects are disabled, so an approved request cannot carry credentials to
another host. Missing grants and provider HTTP statuses are reported as the
compact generic plugin error outcomes. These are YouTube implementation
choices, not part of the generic Input contract or a copy of PHP strategy
classes.

Run the deterministic proof from the Lerd development container with:

```bash
./scripts/youtube-input-spike.sh
```

The script builds the Component and host, uses a temporary private Unix socket,
checks RSS and Data API parity against the PHP provider using the same
fixtures, and exercises unsupported sources, channel-resolution failure,
malformed feeds, unavailable feeds, missing credentials, authentication and
rate-limit failures, and a later invocation after those failures. It also
rejects obvious provider-mechanism terms in the generic WIT as a small guard
against repeating this boundary mistake.

## Declarative plugin UI boundary

The first external Broadcast migration is now wired through the normal runtime:
the bundled Podcast Component is registered from `plugins/podcast/plugin.json`
under the logical key `podcast`, and the generic Broadcast adapter invokes it
through the shared plugin host. The old PHP Podcast implementation, routes,
token service, and Podcast-specific transcode jobs are removed. Published feed
and asset URLs use the generic `PublishedResource` endpoint; the Component
receives those URLs as opaque values and owns the feed document semantics.

The generic Broadcast WIT intentionally contains no feed, episode, enclosure,
or provider-token vocabulary. A plugin supplies its own output meaning while
core owns lifecycle state, asset authority, publication credentials, and byte
serving. The historical `Broadcast-Plugin-Architecture-Plan.md` remains a
design record and may describe the superseded in-tree PHP implementation; use
the current code and this section for the runtime model.

Plugins may describe UI, but they do not ship arbitrary frontend code. The
Stashd frontend remains first-party and renders plugin declarations with its
own components.

The likely extension surfaces are setup/configuration, Input or Broadcast
creation options, detail-page fields/status, actions, and badges/adornments.
The control vocabulary should remain small and evidence-driven; the current
working candidates are `text`, `textarea`, `number`, `boolean`, `select`,
`multi-select`, `secret`, and `url`. This is a direction, not a final schema.

Static UI metadata can come from a manifest or description: labels, help text,
fixed fields, and credential configuration. Dynamic metadata may require a
plugin invocation for remote choices, status, actions, or discovered options.

The first version must not accept arbitrary Vue, plugin JavaScript, plugin CSS,
custom routes, navigation entries, or iframe mini-apps. Podcast is the next
real stress test for a declarative Broadcast UI contract; this Input milestone
does not implement that migration.
