# Plugin OCI packaging

Production plugins are immutable OCI image layouts. A release contains the
Composer production tree, `stashd-plugin/plugin.json`, the entrypoint, and any
declared helper payloads. The manifest digest is the installed identity; tags
are discovery hints only.

Provider release jobs run `tools/build-oci.sh`, optionally with
`PLUGIN_HELPERS_DIR` containing pinned, checksum-verified executables. The
script emits a standard OCI layout with a single filesystem layer and records
the plugin id/version in OCI config labels. Build one layout per supported
platform and publish an OCI index.

Core verifies the manifest and layer digests before installing the layer with
`PackageManager::installOciLayout()`. Installed packages are immutable and
discovered from the active package directory using the same manifest path as
Composer packages. Helper grants are package-relative and sandboxed; the
runtime never resolves provider tools from host `PATH`.

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
