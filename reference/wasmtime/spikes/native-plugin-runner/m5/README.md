# M5 — Generic broker capabilities (historical)

The executable M5 spike was superseded by the productionized runtime package
at `packages/native-plugin-runtime/`. The original notes remain as historical
evidence; the maintained assembled checks are in
`packages/native-plugin-runtime/tests/run.sh`.

M5 connects the M4-shaped SDK to a fixture-only, invocation-scoped host
broker. It remains a spike: there is no production integration, WIT change,
credential store, installer, or provider behavior.

The broker provides:

- exact allowed-origin HTTP with host-side redirect checks and protected
  credential-header injection;
- inline responses up to a small threshold, then a read-only SDK resource
  backed by invocation staging rather than JSON/RPC body buffering;
- explicitly granted read-only asset handles without exposing their paths;
- relative, symlink-checked staging writes and opaque output descriptors;
- package-local helper execution using the M1 bubblewrap policy;
- redacted structured log/progress events and explicit invocation cleanup.

Run the complete M5 check from the repository root:

```sh
./spikes/native-plugin-runner/m5/run.sh
```

The script runs M3 and M4 checks, builds a fresh Bookworm test image, runs M5
inside a non-root container with all capabilities dropped and
`no-new-privileges`, then runs the existing M1/M2 regression suite.
