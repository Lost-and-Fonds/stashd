# Wasmtime/Wasm historical reference

This directory is historical/reference code only. Native PHP plugins are
Stashd's production architecture. Do not update this directory to implement
current behavior.

It is excluded from production Docker builds, Composer autoloading, Cargo
workspaces, plugin discovery, normal CI, and routine tests. The active contract
is maintained in `Lost-and-Fonds/plugin-api`; the active SDK and providers live
in their own repositories.
