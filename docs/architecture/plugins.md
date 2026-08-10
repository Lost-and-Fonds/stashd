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

The following are not designed:

- network permissions and destination restrictions;
- secret usage versus secret access;
- capability declaration and review;
- permission prompts and audit history;
- operating-system-level Vault and staging mounts.

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
