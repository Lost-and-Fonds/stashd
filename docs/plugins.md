# Plugins

A Stashd plugin is a versioned OCI artifact consumed by Stashd's plugin API and
runtime. Plugins are not Core Composer dependencies and need not be PHP.

Install a trusted first-party reference as an administrator:

```bash
docker compose exec stashd php tempest stashd:plugin-install ghcr.io/lost-and-fonds/jellyfin:0.2.2
docker compose exec stashd php tempest stashd:plugin-install ghcr.io/lost-and-fonds/plex:0.2.2
docker compose exec stashd php tempest stashd:plugin-install ghcr.io/lost-and-fonds/podcast:0.2.3
docker compose exec stashd php tempest stashd:plugin-install ghcr.io/lost-and-fonds/youtube:0.3.9
docker compose exec stashd php tempest stashd:plugin-list
```

Current first-party artifact digests:

- Jellyfin `sha256:23712289b9c074779915322a06b045ed12f0dae7101e7e8e4863e458726abc86`
- Plex `sha256:3653575471fe76ce9ce18404418595fc8761dafba9421820d2975af093554b5e`
- Podcast `sha256:46b4d8f551b7e6f25e2988ba958907c09b8bef00afe30c03f4215757a2867861`
- YouTube `sha256:a4d0ddedf99c878c7e4707a03307af73005380ecdea99a5e032e8c4677df7aa7`

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
