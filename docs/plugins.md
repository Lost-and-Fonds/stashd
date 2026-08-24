# Plugins

A Stashd plugin is a versioned OCI artifact consumed by Stashd's plugin API and
runtime. Plugins are not Core Composer dependencies and need not be PHP.

Install a trusted first-party reference as an administrator:

```bash
docker compose exec stashd php tempest stashd:plugin-install ghcr.io/lost-and-fonds/jellyfin:0.2.1
docker compose exec stashd php tempest stashd:plugin-install ghcr.io/lost-and-fonds/plex:0.2.1
docker compose exec stashd php tempest stashd:plugin-install ghcr.io/lost-and-fonds/podcast:0.2.2
docker compose exec stashd php tempest stashd:plugin-install ghcr.io/lost-and-fonds/youtube:0.3.6
docker compose exec stashd php tempest stashd:plugin-list
```

Current first-party artifact digests:

- Jellyfin `sha256:47eb224dd67228f22019e19882c94f1c42d8b4d5b84882acdf51cbfe11e35bb8`
- Plex `sha256:bb3e0286c702bdcf438f33f204fb2d7ed809f7b351ea45912af1c37af28383e6`
- Podcast `sha256:d76044a267c19d884ee0a6366d6a9ef25dbe8e13c2ef5edbfb2dd1fdaaabfe41`
- YouTube `sha256:8ea3bb6dc0e8a42a6045b653cea5f234cb7f553226960add57aaaed23394f0ff`

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
