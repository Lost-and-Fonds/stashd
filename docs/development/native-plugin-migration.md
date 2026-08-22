# Native plugin migration roadmap

The native runtime is additive while the existing Wasmtime Component runtime
remains production code. The stable boundary and security policy are recorded
in [native plugin runtime](../architecture/native-plugin-runtime.md).

## Completed foundation

- Native runner, bubblewrap policy, RPC v1, WIT/schema bridge, PHP SDK,
  brokered capabilities, package lifecycle, and provider-neutral operational
  conformance were proven in bounded milestones.
- The productionized runner, SDK, package lifecycle, and capability policy no
  longer depend on executable spike code.
- The first native provider port established parity without adding provider
  semantics to core.

## Remaining migration sequence

1. Port the next provider only after the production runtime and conformance
   suite remain green.
2. Port each provider independently, keeping provider tests and fixtures with
   the provider package and retaining the Wasmtime implementation as rollback
   coverage.
3. Compare parity, startup/deployment behavior, and operational failures
   before changing the default runtime.
4. Decide whether the Wasmtime runtime can be removed only after every active
   plugin has native parity, rollback is proven, and the compatibility window
   has been deliberately closed.

The separately extracted `plugin-contract` package is explicitly deferred.
Revisit it only if a real provider port exposes contract drift or awkward
shared DTO ownership; do not extract it speculatively.

RSS/Input work resumes only after the native runtime's next provider parity
gate is complete and does not require a new generic capability.
