# Stashd

Self-hosted media preservation: Stash → Vault → Broadcasts.

## Quick start

```bash
docker compose up -d
```

Open `http://localhost:8474`, create the single owner account, and create a stash. Docker persists application data in `./data` and media in `./media`.

## Verify

```bash
composer test
composer test:docker-smoke
```

See the [runtime](docs/runtime/frankenphp.md), [providers](docs/providers/README.md), [storage](docs/storage/README.md), [broadcasts](docs/broadcasts/README.md), and [architecture](docs/architecture/) documentation. For product and engineering detail, see the [engineering specification](docs/Stashd-Engineering-Specification.md).

## Ecosystem

- [Documentation](https://github.com/Lost-and-Fonds/docs)
- [Plugin API](https://github.com/Lost-and-Fonds/plugin-api)
- [PHP plugin SDK](https://github.com/Lost-and-Fonds/plugin-sdk)
- [Jellyfin](https://github.com/Lost-and-Fonds/jellyfin) and [Plex](https://github.com/Lost-and-Fonds/plex) Broadcast plugins
- [Podcast](https://github.com/Lost-and-Fonds/podcast) (M10) and [YouTube](https://github.com/Lost-and-Fonds/youtube) (M11) plugin homes
