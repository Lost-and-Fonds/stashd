# Stashd Plugin Architecture — v0.1 Spike

Status: EXPERIMENTAL

This is an architectural spike, not the final Stashd Plugin API.

The original Broadcast-shaped spike contract is quarantined under
`plugin-api/spike-wit/`. It remains only for the trap/failure containment
regression script and must not be extended or treated as the active semantic
contract. Active experimental Input WIT lives under `plugin-api/wit/`.

HTTP destinations and credential-use rules are supplied as invocation-scoped
generic grants. They are not hard-coded YouTube or Google policy in the Rust
host; provider registrations supply the destination prefixes and credential
placement needed by their implementation.

The purpose is to prove or disprove the proposed PHP / Rust / WebAssembly Component Model architecture before designing the stable Plugin API.

Do not prematurely generalize the spike into a complete plugin framework.

---

# Core principles

## PHP owns truth

The PHP/Tempest application remains the authoritative Stashd application.

PHP owns:

- Stashes
- Inputs
- Items
- Assets
- Broadcasts
- Connections
- PostgreSQL
- plugin installation/enabled/configured state
- plugin configuration persistence
- secrets at rest
- scheduling
- durable operation state
- preservation semantics
- provenance/history
- fixity/integrity records
- staging → Vault promotion
- Status/API/UI interpretation

The Rust plugin host must not acquire an authoritative application database.

It should be possible to kill and restart the Rust plugin host without losing authoritative Stashd data.

---

## Rust owns containment

A small trusted Rust process, tentatively:

`stashd-plugin-host`

owns:

- Wasmtime Component Model runtime
- WIT bindings
- component loading
- component validation
- plugin sandboxing
- invocation-scoped resource handles
- resource limits
- cancellation
- plugin crash containment
- plugin log/progress forwarding
- filesystem enforcement
- eventual network enforcement
- transient component/runtime caches

Rust is trusted Stashd code.

Third-party Wasm plugins are not trusted.

---

# Plugin boundary

The prospective public plugin API should be defined using WIT / WebAssembly Component Model interfaces.

This API is independent of:

- PHP
- Tempest
- the private PHP↔Rust IPC protocol
- the internal Stashd database schema

Do not treat Wasmtime itself as the Stashd Plugin API.

Use experimental WIT package versions such as:

`stashd:plugin@0.1.0`

Do NOT publish or describe this spike as Plugin API v1.

---

# Private PHP ↔ Rust boundary

PHP and `stashd-plugin-host` communicate through a small private local IPC protocol.

For this spike:

- use a Unix-domain socket on Linux
- use a deliberately boring framed/NDJSON-style JSON protocol unless another simple choice is clearly easier
- support request IDs
- support request → progress/log events → result/error

This protocol is private implementation detail.

It may change lockstep with Stashd releases.

Do NOT turn it into a second public Stashd API.

Expected conceptual operations should remain close to:

- inspect component
- invoke component
- cancel invocation

Expected returned events should remain close to:

- progress
- log
- result
- execution error

Do NOT add domain CRUD operations such as:

- createStash
- saveItem
- queryAssets
- updateBroadcast
- queryDatabase

If the IPC begins mirroring Stashd's domain model, stop and reconsider the boundary.

---

# Security invariant

Only Stashd core may commit data into the Vault.

A plugin must never receive:

- an arbitrary writable Vault path
- arbitrary write access to the Vault filesystem
- direct database access
- arbitrary shell/process execution

Plugins operate through explicitly granted capabilities.

---

# Resource model

The most important concept to test is WIT resources.

For the spike, demonstrate at least:

## Vault Asset

A plugin receives an opaque read-only resource representing an Asset.

Conceptually:

```wit
resource vault-asset {
    size: func() -> u64;
    read: func(offset: u64, maximum: u32) -> result<list<u8>, asset-error>;
}
```

This exact interface is NOT final.

Using chunked byte reads is acceptable for the spike.

Do not spend significant time solving production-scale streaming yet.

The critical property is:

**there is no mutation operation on `vault-asset`.**

The plugin must not receive the underlying Vault path.

---

## Staging output

A plugin may receive an opaque writable staging capability.

Conceptually:

```wit
resource staging-output {
    write: func(bytes: list<u8>) -> result<_, staging-error>;
    finish: func() -> result<staged-output, staging-error>;
}
```

Again, exact signatures are experimental.

The plugin may create output in staging.

The plugin may NOT promote it to Vault.

After the plugin finishes, control returns to PHP.

Future Stashd core would perform:

1. validation
2. fixity calculation
3. deduplication
4. metadata/provenance recording
5. preservation event recording
6. Vault commit

Do NOT implement the full preservation pipeline in this spike.

## Known proof limitation: whole-file buffering

This spike intentionally buffers the fixture-sized proof data in memory. The
Rust host reads the granted asset with `fs::read` in `plugin-host/src/main.rs`
and stores it in `VaultAssetResource::bytes`. It also accumulates every staged
write in `StagingOutputResource::bytes` before `finish` writes the complete
buffer to the staging path.

That is not suitable for multi-gigabyte media. The smallest likely follow-up
is to replace those two invocation-local buffers with host-owned seekable input
and append-only staging handles, preserving the same opaque WIT resources and
chunked calls. The follow-up should measure the Wasm boundary's copy costs
before introducing a larger streaming abstraction.

---

# Invocation-scoped grants

Resources must be scoped to a single invocation.

Conceptually PHP decides:

Invocation 847 may:

- read Vault Asset A
- write one staging output

Rust materializes those grants as WIT resources.

The plugin sees only opaque resource handles.

When the invocation ends, those capabilities cease to exist.

Do not design permanent plugin access to internal Asset IDs or filesystem paths.

---

# Spike plugin

Build one deliberately tiny example Component plugin.

Use the guest language that gives the fastest reliable Component Model development path; Rust is preferred for the first spike.

Do not optimize for demonstrating every guest language yet.

The example plugin should behave roughly like a trivial Broadcast/export operation:

1. receive a granted read-only `vault-asset`
2. inspect/read its contents
3. write corresponding output to granted staging
4. report progress
5. return a typed result

The transformation itself should be deliberately trivial.

For example:

- copy a small fixture Asset
- or generate a simple textual report from the Asset

The purpose is proving the architecture, not building useful media behaviour.

Use only small fixture files.

---

# Progress

The plugin should be capable of producing progress/stage information.

For the spike, something equivalent to:

- 0.0 — Starting
- 0.5 — Reading Asset
- 0.8 — Writing output
- 1.0 — Complete

is sufficient.

Rust forwards those events to PHP through the private IPC connection.

Do not integrate the existing Vue UI or SSE system yet.

Prove the plumbing only.

---

# Failure behaviour

Exercise at least:

- successful invocation
- plugin-provided typed failure
- malformed/invalid component
- plugin trap/crash OR forced execution failure

If practical without substantial scope growth, also prove one timeout/cancellation path.

Rust runtime failure must not crash PHP.

PHP must receive an intelligible execution error.

---

# Filesystem boundary

Where practical, strengthen the resource abstraction with filesystem permissions.

Ideal eventual architecture:

- Vault visible to plugin host as READ ONLY
- staging visible READ/WRITE
- plugin itself only receives resource handles

For this spike, prove as much of this as is practical in the local development environment.

Do NOT spend the day redesigning Docker deployment to accomplish it.

At minimum:

- plugin receives no Vault path
- plugin API exposes no Vault write operation
- plugin output goes to a separate staging location

Document anything that still relies on host trust/OS configuration.

---

# Minimal PHP integration

Add a small PHP client/service for communicating with `stashd-plugin-host`.

Keep the API domain-oriented from the PHP caller's perspective.

Prefer something conceptually close to:

```php
$result = $pluginHost->invoke($invocation);
```

over exposing Wasmtime/WIT/runtime mechanics throughout the application.

The PHP side should not need to understand component instantiation internals.

Do not integrate plugin execution into real Input/Broadcast workflows yet.

A CLI/test harness is enough for the spike.

---

# Rust host persistence

`stashd-plugin-host` must not own authoritative persistent Stashd state.

Allowed:

- compiled component cache
- ephemeral invocation state
- temporary runtime files

Not allowed:

- authoritative plugin configuration database
- authoritative Input state
- authoritative Broadcast state
- authoritative Asset metadata

Persistent plugin-specific state will ultimately belong to PHP/Stashd.

Do not design that subsystem in this spike.

---

# Plugin manifest

If needed for loading the example plugin, introduce only a minimal experimental manifest.

It may include:

- plugin ID
- display name
- plugin version
- component path
- required experimental Plugin API version

Do not design the final catalog/distribution manifest yet.

Discovery, OCI distribution and signing are out of scope for this spike.

---

# Explicit non-goals

Do NOT implement:

- production Plugin API v1
- plugin discovery/catalog
- OCI distribution
- signing/Sigstore
- plugin installation UI
- Configure UI
- arbitrary plugin UI
- arbitrary routes/navigation
- JSON Schema forms
- final Connection API
- final Input API
- final Broadcast API
- enrichment API
- media transcoding
- FFmpeg integration
- network permissions
- production secret handling
- plugin updates
- configuration migrations
- full Vault promotion
- multiple plugin runtimes
- Extism integration
- TypeScript guest support unless everything else is complete and it is trivial to demonstrate

These are later tasks.

---

# Suggested repository shape

Adapt to existing repository conventions rather than forcing this exact layout.

Conceptually:

```text
docs/
  architecture/
    PLUGIN-SPIKE.md

plugin-api/
  wit/
    ...

plugin-host/
  Cargo.toml
  src/
    ...

plugins/
  example/
    ...

app/
  Plugins/
    ...
```

Inspect existing Stashd structure and `AGENTS.md` plus the relevant canonical
architecture documents before choosing exact paths.

---

# Build sequence

Work in this order.

Do not start later phases until the earlier one works.

## Phase 1 — WIT + standalone component

- create minimal experimental WIT package
- build example Component
- verify Wasmtime can instantiate it from Rust
- invoke one typed function successfully

Checkpoint.

## Phase 2 — Rust plugin host

- create `stashd-plugin-host`
- load the example Component
- map WIT interfaces
- produce result/error
- expose progress/log events internally

Checkpoint.

## Phase 3 — private IPC

- Unix-domain socket
- PHP client connects
- PHP invokes example operation
- Rust returns events/result

Checkpoint.

## Phase 4 — resource boundary

- PHP grants a fixture Vault Asset
- Rust materializes `vault-asset`
- plugin reads it without receiving its path
- plugin cannot mutate it through the WIT interface

Checkpoint.

## Phase 5 — staging output

- Rust grants staging resource
- plugin writes output
- PHP receives an opaque/result reference to staged output
- source fixture remains unchanged

Checkpoint.

## Phase 6 — failure tests

- typed plugin failure
- invalid/trapping component
- host remains operational
- PHP receives meaningful error

Checkpoint.

Stop here.

Do not continue into the full plugin architecture.

---

# Acceptance criteria

The spike is successful if all of these are true:

1. PHP can invoke a Wasm Component through `stashd-plugin-host`.
2. The public experimental plugin boundary is described by WIT.
3. PHP does not directly embed Wasmtime.
4. The PHP↔Rust IPC is local, small and private.
5. An example plugin receives a read-only Vault Asset resource.
6. The plugin does not receive the underlying Vault path.
7. No Vault mutation operation exists in its granted API.
8. The plugin can produce output only through a staging capability.
9. Plugin progress reaches PHP.
10. A plugin failure does not crash PHP or the Stashd application.
11. Restarting the plugin host loses no authoritative Stashd state.
12. No Stashd database/domain model is duplicated in Rust.

---

# Questions this spike must answer

At the end, report evidence for:

1. Is direct Wasmtime Component Model hosting in Rust pleasant enough?
2. How ergonomic are WIT-generated bindings?
3. Do WIT resources give us the Vault isolation model we expect?
4. How awkward is async/progress plumbing?
5. Is the Unix-socket PHP↔Rust boundary straightforward?
6. Can the Rust host remain essentially stateless?
7. What parts felt naturally typed in WIT?
8. What parts felt like they should remain outside WIT?
9. Did anything suggest Extism would materially simplify the architecture?
10. What would block using this architecture for a real Input or Broadcast plugin?

Do not paper over problems.

The output of this spike is evidence for the final Plugin API design.

It is entirely acceptable for the conclusion to be:

"This part of the proposed architecture is wrong."

That is a successful spike if supported by evidence.

---

# Verification

Run all existing relevant Stashd tests plus new spike-specific tests.

For local development, run the spike from the Lerd custom application
container so PHP and `stashd-plugin-host` share the same process and
filesystem namespace:

```bash
podman exec -i -w /var/www/html lerd-custom-stashd \
  /bin/bash -lc './scripts/plugin-spike.sh'
```

The development image supplies Cargo, the Rust toolchain, the
`wasm32-wasip2` target, and native build tools. The script places its socket
and other transient outputs under the container's temporary directory; it
does not require a host socket mount or exposed port.

Run:

- PHP formatting/static checks expected by the repository
- Rust formatting
- Rust clippy
- Rust tests
- component build
- end-to-end PHP → Rust → Component test

Do not leave the main repository test suite broken.

---

# Final report

Report:

1. files added/changed
2. architecture actually implemented
3. WIT packages/interfaces created
4. IPC design
5. PHP client design
6. Rust host design
7. resource/grant design
8. staging design
9. failure isolation behaviour
10. tests performed
11. acceptance criteria passed/failed
12. pain points
13. surprises
14. questions still unresolved
15. recommendation: proceed / modify / abandon this substrate

Then STOP.
