# Media server integrations (Phase 5B)

Phase 5B adds **minimal, Stashd-owned** Jellyfin and Plex clients for connection testing, library listing, and post-rebuild scan triggers. There is **no broad third-party Plex/Jellyfin PHP library** — only small direct HTTP calls behind `MediaServerClient`.

Podcast feeds and playback/session features are **not** in scope (Phase 5C+).

## Connection model

Connections are stored in `media_server_connections`:

| Field | Notes |
|---|---|
| `type` | `jellyfin` or `plex` |
| `name` | Display label |
| `base_uri` | Server URL (no trailing slash stored) |
| `token_secret_id` | FK to `secrets.id` — token plaintext never stored in normal columns |
| `settings_json` | Optional `library_id`, `library_name`, etc. (snake_case in DB) |
| `state` | `ready`, `failed`, `disabled` |

Tokens are written via `SecretsService` with type `media_server_token`. Keys follow `media_server:{connectionId}:token`.

## API

```text
GET    /api/v1/media-servers
POST   /api/v1/media-servers
GET    /api/v1/media-servers/{id}
PATCH  /api/v1/media-servers/{id}
DELETE /api/v1/media-servers/{id}
GET    /api/v1/media-servers/{id}/libraries
POST   /api/v1/media-servers/{id}/test
```

Commands (async via `POST /api/v1/commands`):

| Command | Purpose |
|---|---|
| `media_server.test_connection` | Verify token + reachability |
| `media_server.list_libraries` | List Jellyfin views / Plex sections |
| `broadcast.trigger` | Run configured scan trigger for a broadcast |

JSON uses snake_case. Secrets are redacted from errors, activity, and command results.

## Clients

| Class | Surface |
|---|---|
| External Broadcast Component | The installed plugin performs its protocol calls through the bounded host HTTP capability |
| external Plex Component | `GET /identity`, `GET /library/sections`, `GET /library/sections/{id}/refresh` |

Both implement `MediaServerClient` and use `MediaServerHttpClient` (`CurlMediaServerHttpClient` in production; `FixtureMediaServerHttpClient` when `ENVIRONMENT=testing`).

Optional live tests: `STASHD_LIVE_MEDIA_SERVER_TESTS=1`.

## Scan triggers (separate from publish validity)

After a successful `broadcast.rebuild` verify pass, broadcasts with `auto_trigger_scan: true` in settings may run a scan trigger automatically.

| Rule | Behavior |
|---|---|
| Trigger failure | Recorded on `broadcast_trigger_runs`; broadcast files stay valid |
| Broadcast state | Trigger failure does **not** mark broadcast `failed` when files verify OK |
| Retry trigger | `broadcast.trigger` re-runs scan only — does not rebuild files unless requested |
| Secrets | Redacted in trigger run errors and activity |

Trigger records remain transitional infrastructure for provider refreshes;
external Broadcast Components now perform their refresh after core
materializes outputs.

Activity events: `broadcast.trigger_succeeded`, `broadcast.trigger_failed`, `media_server.test_completed`.
