# Native plugin runtime boundary

M7.5 moved the proven native-plugin behavior out of the milestone spikes and
into two deliberate package boundaries. This document records the boundary
that is stable for the first native provider port; it does not replace the
existing WIT contract or migrate the current Wasmtime runtime.

## Package ownership

```text
packages/plugin-sdk/
  generated/                  transport-neutral WIT/schema artifact
  src/                        handwritten PHP authoring API and mapping
  tests/                      SDK/conformance checks

packages/native-plugin-runtime/
  src/Rpc/                    framed RPC v1 codec
  src/Runner/                 process launch, handshake, invocation, cleanup
  src/Sandbox/                one authoritative bubblewrap policy
  src/Capabilities/           invocation-scoped host capability broker
  src/Package/                 validation, activation, rollback, linking
  tests/                      provider-neutral assembled conformance smoke
```

The SDK is the only package a PHP plugin author should need. It hides framing,
file descriptors, bubblewrap, namespaces, and process supervision. The runtime
package is host-side code and has no Vault, database, or provider semantics.

## Stable public surfaces for M8

The following are stable enough for the Jellyfin native port:

- manifest identity, runtime, entrypoint, capabilities, and package version;
- RPC v1 length-prefixed JSON framing, handshake, request IDs, responses,
  notifications, and cancellation/timeout errors;
- the transport-neutral shapes extracted from the existing WIT;
- PHP Input/Broadcast lifecycle interfaces and DTOs;
- `PluginContext` logging, progress, HTTP, staging, and resource interfaces;
- v1 inline response and opaque resource-handle behavior;
- versioned package directories, immutable packages, active-version switching,
  rollback, disable/remove, and development links.

The SDK capability implementations remain host-provided. Provider ports must
not add provider-specific behavior to these packages.

## Sandbox policy

`SandboxPolicy` is the single construction point for the bubblewrap command.
The policy uses a non-root user namespace, isolated PID/IPC/UTS/network
namespaces, cleared environment, read-only package/runtime mounts, per-job
staging, private `/tmp`, minimal directories, parent-death handling, and no
`/proc` mount. The package, Vault, database, application data, host home,
runtime sockets, and direct network are not exposed.

`Invocation` owns capability grants and cleanup for one invocation. Paths are
validated at the host boundary; plugin descriptors never become arbitrary host
paths. Credentials are injected by the host and are not returned to plugin
code.

## Dependency review

No new production dependency was added in M7.5. Existing PHP standard-library
code was retained deliberately:

- `proc_open`/pipes are used because the runner needs a bidirectional inherited
  channel and explicit bubblewrap argv. Symfony Process is already present in
  the application, but wrapping this low-level protocol would not remove the
  required framing, capability loop, or parent-death policy.
- archive extraction and path checks are Stashd-specific package safety rules.
  Generic archive libraries do not provide the required rejection of traversal,
  symlink, and hardlink escapes with the same small audit surface.
- JSON framing is the deliberately tiny RPC v1 wire format; a protocol
  framework would add no useful semantics.
- manifest versions are intentionally limited to the currently supported
  concrete compatibility checks. A full semantic-version resolver is deferred
  until a real compatibility matrix requires it.

The package has therefore added no transitive maintenance burden. This choice
must be revisited before expanding package/version policy, not hidden behind
the spike code.

## Spike disposition

The executable M4–M7 PHP/runtime copies were removed after the equivalent
production-package conformance test passed. Their README files remain as
historical milestone evidence. M1–M3 protocol/schema evidence remains in the
spike tree where it is useful for comparison; production behavior does not
load any file under `spikes/`.

The Wasmtime Component runtime remains active production code. Native runtime
productionization is additive and does not change WIT or remove Wasmtime.
