# M4 — PHP SDK and provider-neutral example (historical)

The executable M4 spike was superseded by the productionized package at
`packages/plugin-sdk/`. The original notes remain as historical evidence;
run `packages/plugin-sdk/tests/run.sh` for the maintained SDK checks.

This is the first author-facing shape of `stashd/plugin-sdk`. It is a
spike-local, dependency-free PHP 8.5 API; it is not a Composer package and is
not loaded by production Stashd.

The SDK exposes ordinary PHP lifecycle interfaces and immutable DTOs for the
active Input and Broadcast contracts. `PluginContext` contains only small
capability interfaces. HTTP is deliberately an unavailable fixture capability
in M4, and staging is represented by an interface for the later host-backed
implementation.

`WireMapper` is the boundary-side mapping from handwritten ergonomic objects
to the transport-neutral shapes generated in M3. Example plugin code does not
call it and does not know about framing, JSON envelopes, file descriptors,
bubblewrap, namespaces, or process supervision.

The provider-neutral example registers one Input and one Broadcast. Its
behavior is intentionally generic: it returns deterministic item/artifact
metadata, choices, progress, and a typed retryable failure. It has no
Jellyfin, Plex, Podcast, YouTube, or media-server semantics.

Run the complete M4 check from the repository root:

```sh
./spikes/native-plugin-runner/m4/run.sh
```

The check also runs the M3 bridge and the existing M1/M2 regression suite.
