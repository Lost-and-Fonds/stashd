# Plugins

A Stashd plugin is a versioned OCI artifact consumed by Stashd's plugin API and
runtime. Plugins are not Core Composer dependencies and need not be PHP.

Install a trusted first-party reference as an administrator:

```bash
docker compose exec stashd php tempest stashd:plugin-install ghcr.io/lost-and-fonds/jellyfin:0.1.1
docker compose exec stashd php tempest stashd:plugin-list
```

The installer resolves the reference to a platform-specific immutable digest,
validates `plugin.json`, and stores it below `/data/plugins`. Because `/data`
is persisted by the standard Compose deployment, installed plugins survive a
Core image update or container replacement. Installed is separate from
configured: a plugin can be installed without credentials or Connections.

OCI distributes an artifact; the manifest's runtime field selects the runtime
Stashd understands. Current first-party artifacts use the PHP runtime, but the
installation contract is language-independent. There is no catalogue or
automatic update system yet; obtain trusted references from the plugin project
documentation. Installing an OCI reference is explicit administrator trust and
does not imply third-party code is safe.
