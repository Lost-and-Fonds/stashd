# Plugin OCI packaging

Production plugins are immutable OCI image layouts. A release contains the
Composer production tree, `stashd-plugin/plugin.json`, the entrypoint, and any
declared helper payloads. The manifest digest is the installed identity; tags
are discovery hints only.

Provider repositories declare helper semantics in `plugin.json` and exact
release inputs in `stashd-plugin/helpers.lock.json`. Core's generic
`PluginBuilder` resolves those declarations for the detected platform, verifies
checksums, installs locked Composer dependencies, and emits a standard OCI
layout with a single filesystem layer. No provider names occur in the builder.

Core invokes the pinned `/usr/local/libexec/stashd/umoci` v0.6.0 binary for
layout creation, layer packing, digest inspection, and rootless unpacking.
The image manifest digest is the OCI artifact identity returned by the builder
and verified by `PackageManager::installOciLayout()`; the installed package
store remains keyed by the plugin manifest's id and version. `umoci` is
verified in the core image from the official release assets (the amd64 SHA-256
is `b51c267ec394499e42c6fde47f240b7b7dba57ea49df0b5acd304378b82a3b71`; the
arm64 SHA-256 is
`5cfd17f2e7a4bcf9ed67ea1b955ca893d200349b9ce6a3d3707dba415f458a1f`).
Installed packages are immutable and discovered from the active package
directory using the same manifest path as Composer packages. Helper grants
are package-relative and sandboxed; plugins never receive the core `umoci`
path or its capability.

Local edits may still use the documented sibling Composer workflow. OCI builds
are the production-like path and require helper payloads explicitly.

## Schema audit

| Area | Classification | Action |
| --- | --- | --- |
| `media_server_connections` | Historical name; generic plugin connection semantics | Retained pending a deliberate schema baseline migration. |
| `broadcast_items.tokenSecretId/tokenPreview` | Obsolete publication-token fields | Existing removal migration retained; no new use. |
| `broadcast_sponsorblock_refreshes` | Removed provider-specific scheduling state | Existing removal migration retained; no replacement added. |
| `StashInputType`, quality profile ids | Generic stash acquisition state | Retained. |
| `providerKey`, `providerItemId`, `providerInputId` | Generic external identity | Retained. |

The migration chain is not consolidated in this milestone because doing so
would require a destructive developer reset. Git history remains the source
of superseded migration history.
