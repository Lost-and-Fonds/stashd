# M7 native conformance and operational smoke (historical)

The executable M7 fixture was superseded by the productionized conformance
test at `packages/native-plugin-runtime/tests/production_m7.php`. The original
notes remain as historical evidence; run
`packages/native-plugin-runtime/tests/run.sh` for the maintained check.

This fixture assembles the spike layers without changing production Stashd:

```text
M6 package activation
  -> M1 bubblewrap runner
  -> M2 framed RPC
  -> M3 transport-neutral WIT shapes
  -> M4 PHP SDK
  -> M5 invocation capabilities
  -> host validation/promotion boundary
```

The fixture package contains only a provider-neutral Input/Broadcast example and
a declared PHP helper. The host keeps the Vault canary outside the sandbox,
injects a credential only into the deterministic fixture transport, and performs
promotion after the plugin has returned a relative publication descriptor.

Run the complete check with:

```text
./spikes/native-plugin-runner/m7/run.sh
```

The command first verifies the temporary PostgreSQL state, then builds a fresh
Bookworm/Podman image with bubblewrap, and finally runs the M6 regression chain.
The image uses a non-root UID, drops all capabilities, enables
`no-new-privileges`, uses no runtime socket, and retains the established
namespace policy including no `/proc` mount.

M7 intentionally did not alter WIT, production plugin code, Wasmtime, or
installation/runtime production paths. M7.5 moved maintained behavior into
`packages/`; this directory is retained only for historical milestone notes.
