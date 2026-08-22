# Stashd native plugin runtime

This package owns the native-process runtime boundary: RPC v1 framing,
bubblewrap policy, invocation-scoped capability enforcement, package
activation, and process supervision. It does not own Stashd records, Vault
promotion, provider semantics, or the PHP SDK's ergonomic authoring API.

The runtime is intentionally independent of Wasmtime. WIT remains the
canonical semantic contract; the runtime consumes the transport-neutral schema
and carries those values over RPC v1.
