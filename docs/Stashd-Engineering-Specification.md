# Stashd Engineering Specification

> **Status:** Working v1 engineering specification  
> **Purpose:** This document captures the concrete product, architecture, implementation, and UX decisions for building Stashd v1.

---

# 1. Product Summary

**Stashd** is a self-hosted media preservation service for keeping local copies of online video/audio content and making them useful through generated outputs such as media-server libraries and podcast feeds.

The core promise:

> **Stash a channel. Broadcast it to Jellyfin.**  
> **Stash a playlist. Broadcast it as a podcast.**  
> **No broadcast? No problem. It stays in your Vault.**

Stashd is built for homelab users who want a reliable, fast, understandable, Docker-first tool for keeping the online media they care about.

Stashd is not a YouTube frontend. It is not a media player. It is preservation infrastructure.

---

# 2. North Star

> **Stashd should be the fastest, most reliable way to keep a permanent local copy of online video libraries while staying invisible until you need it.**

v1 should prove the core loop:

```text
Create Stash
Discover items efficiently
Capture metadata
Download/save to Vault
Broadcast to Jellyfin/Plex/podcast
Show progress
Handle storage/filesystem weirdness
Recover from failures
Upgrade safely
```

---

# 3. Core Terminology

## Stash

A **Stash** is the main user-facing concept.

A stash is anything the user wants Stashd to keep:

- a YouTube channel
- a YouTube playlist
- several playlists grouped as seasons
- individual video URLs
- a future Twitch/PeerTube/Nebula source
- a manually curated group of items

Examples:

```text
Oculus Imperia stash
Critical Role stash
French Documentaries stash
```

A stash may contain one or more internal inputs.

## Input

An **Input** is the technical upstream source inside a stash.

Examples:

```text
YouTube channel
YouTube playlist
URL list
Future Twitch broadcaster
Future PeerTube channel
```

Most users should not need to think about inputs unless they are configuring advanced organization such as playlists-as-seasons.

## Vault

The **Vault** is Stashd’s canonical local archive.

Every media item is saved to the Vault first.

The Vault is required in v1.

The Vault stores canonical source-derived assets and should remain useful even if Stashd is not running.

## Broadcast

A **Broadcast** is a generated view of a stash.

Examples:

```text
Jellyfin Series
Plex Series
Audio Podcast
Video Podcast
```

A broadcast does not own canonical media. It generates presentation assets, hardlinks, feeds, artwork, NFO files, and other output-specific files from the Vault.

## Item

An **Item** is a canonical media item discovered from a provider.

A single item may belong to multiple stashes and appear in multiple broadcasts, but it exists only once in the Vault.

---

# 4. Product Model

The user-facing model is:

```text
Stash
  ↓
Vault
  ↓
Broadcasts
```

Examples:

```text
Stash: Oculus Imperia
Inputs:
  - YouTube channel
Download policy:
  - Audio only
Broadcasts:
  - Audio Podcast
```

```text
Stash: Critical Role
Inputs:
  - Campaign 1 playlist → Season 1
  - Campaign 2 playlist → Season 2
  - Campaign 3 playlist → Season 3
Download policy:
  - Video
Broadcasts:
  - Jellyfin Series
```

```text
Stash: Random Channel
Inputs:
  - YouTube channel
Download policy:
  - Video
Broadcasts:
  - None

Result:
  Saved only to Vault.
```

---

# 5. Technology Stack

## Language

Stashd is written in **PHP 8.5+**.

The maintainer is most productive in PHP, and modern PHP is capable of building this kind of long-running, typed, service-oriented application.

## Framework

Stashd uses **Tempest** as the application framework.

The framework is an interface layer, not the application itself. Business logic must not be trapped in HTTP controllers.

## Runtime

Stashd uses **FrankenPHP** in classic mode, with Caddy as the HTTP server.

No PHP-FPM or Nginx should be required for the default deployment.

Supervisord is responsible for:

```text
HTTP workers
background workers
scheduler runtime
long-running process supervision
worker process supervision
```

## Database

Stashd v1 uses **SQLite only**.

No PostgreSQL, Redis, RabbitMQ, or external queue system is required for v1.

SQLite should be used seriously:

- migrations from day one
- foreign keys enabled
- proper indexes
- transactions
- short write transactions
- WAL mode
- busy timeout
- database backups before migrations

## Download Backend

Stashd v1 uses **yt-dlp** as the media download engine through the maintainer-controlled wrapper:

```text
ytdlphp
```

All yt-dlp interactions must go through a dedicated adapter. Domain code must not shell out directly.

---

# 6. Architectural Principles

## Operationally Boring

Stashd should be boring to run.

No required external services. No required database container. No required Redis. No required message broker.

A user should be able to copy a Docker Compose file, paste it, run:

```bash
docker compose up
```

…and get a working Stashd instance.

## Media-Item-First

The Vault is media-item-first.

Stashes organize media items; they do not own them.

This allows deduplication when the same video appears in multiple channels, playlists, or grouped stashes.

## State-Machine Core, Event-Driven Edges

Core workflow is state-machine-driven.

Events may be emitted from state transitions to support activity, notifications, future plugins, webhooks, and broadcast hooks.

Events are not the source of truth.

## Broadcasts Are Rebuildable

Broadcasts are generated views.

They should be:

```text
idempotent
rebuildable
verifiable
prunable
disposable
```

Deleting a broadcast folder should not destroy canonical data.

## The Database Stores Expected State

The database records what Stashd expects.

The filesystem is verified reality.

Stashd must tolerate filesystem drift.

## Plugin-Ready, But No Plugin Runtime in v1

v1 should define clean interfaces for future plugins, but should not ship a full third-party plugin runtime, marketplace, signing system, or dynamic loading model.

---

# 7. Runtime Roles

The Docker image should support role-based commands:

```text
stashd all
stashd serve
stashd worker
stashd scheduler
```

Default:

```text
stashd all
```

The v1 default deployment should be one container.

Advanced role splitting may be possible later, but SQLite + multi-container + NAS mounts can be tricky, so the all-in-one deployment is the recommended v1 path.

---

# 8. Configuration

## Runtime Configuration

Runtime configuration is set through environment variables only where necessary.

Examples:

```text
STASHD_PUBLIC_URL
STASHD_LOG_FORMAT
PUID
PGID
UMASK
```

## Application Configuration

Application settings live in SQLite and are editable through the UI/API.

Examples:

```text
stashes
broadcasts
provider credentials
Jellyfin/Plex connections
download policies
quality profiles
filters
```

## UX Rule

Users should not need to edit env files after first boot.

The normal flow is:

```text
copy compose file
paste
docker compose up
open UI
create admin account
create first stash
```

---

# 9. Default Docker Compose

Default port:

```text
8474
```

Default compose:

```yaml
services:
  stashd:
    image: ghcr.io/hipsterjazzbo/stashd:v1
    container_name: stashd
    ports:
      - "8474:8474"
    volumes:
      - ./data:/data
      - ./media:/media
    restart: unless-stopped
```

The default port `8474` is chosen to avoid common homelab collisions.

---

# 10. Storage Model

## Storage Roots

Stashd uses named storage roots:

```text
data
vault
broadcasts
temp
cache
```

Default layout:

```text
/data
  stashd.sqlite
  secrets/framework app key/config
  logs maybe
  backups

/media
  vault/
  broadcasts/
  temp/
  cache/
```

Advanced users may split roots across mounts:

```text
/data                    application data
/media/vault             canonical Vault
/media/broadcasts        generated broadcast views
/media/temp              temporary work files
/media/cache             provider/cache files
```

## Backup Critical

Must back up:

```text
/data
/media/vault
```

Can regenerate:

```text
/media/broadcasts
/media/temp
/media/cache
```

The UI should make this obvious.

## Storage Capabilities

Stashd must actively test:

```text
path exists
path readable
path writable
free space
hardlink support
symlink support
filesystem availability
```

Do not rely only on filesystem IDs. Actually test hardlink creation.

## Hardlink First

Broadcast media files should use hardlinks to Vault assets whenever possible.

Fallback policy:

```text
1. hardlink
2. optional symlink, only if enabled
3. optional copy, only with explicit confirmation
4. remux/transcode only according to broadcast policy
```

Stashd must not silently duplicate media files.

## Atomic Writes

Generated files should be written atomically where practical:

```text
write temporary file
flush/close
rename into final path
```

This applies to:

```text
feed XML
NFO files
metadata JSON
artwork
generated podcast audio/video files
```

## Partial Downloads

Downloads should stage into Temp first:

```text
/media/temp/downloads/job_...
```

Then move into Vault after success and verification.

---

# 11. Filesystem Drift

Users may move, delete, rename, or restore files manually.

Stashd must tolerate this.

## Rules

```text
Database = expected state
Filesystem = verified reality
```

Stashd should not crash if files are missing.

Stashd should not automatically delete database rows because files are missing.

Stashd must distinguish:

```text
storage root unavailable
```

from:

```text
individual asset missing
```

If a storage root is unavailable, dependent jobs pause. Do not mark every asset missing.

## Recovery Actions

For missing Vault assets:

```text
Retry download
Locate file
Ignore item
Remove from stash
```

For missing broadcast assets:

```text
Rebuild broadcast
```

## Verification

Routine checks should be lightweight:

```text
exists
size
readability
```

Deep verification can be user-triggered:

```text
checksum
plugin-provided media validation where required
full Vault scan
```

---

# 12. Storage Schema

## `storage_locations`

```text
id                  storage_...
key                 data | vault | broadcasts | temp | cache
role
label
path
state               ready | missing | unwritable | low_space | unavailable | failed
readable
writable
free_bytes
total_bytes
filesystem_id
supports_hardlinks
supports_symlinks
last_checked_at
last_error
created_at
updated_at
```

## `storage_checks`

```text
id                  storagecheck_...
storage_location_id
check_type          writable | hardlink | symlink | free_space | filesystem
state               ready | failed | warning
message
details_json
created_at
```

---

# 13. Metadata Model

## Core Principle

Provider metadata, canonical metadata, stash-specific editorial metadata, and broadcast-specific presentation metadata are separate.

Stashd captures provider metadata when an item is first stashed and treats that snapshot as local truth.

Future syncs do not overwrite existing titles, descriptions, artwork, filenames, episode numbers, NFO files, or podcast metadata unless the user explicitly requests a metadata refresh.

## Manual Metadata Refresh

A metadata refresh:

```text
fetches current provider metadata
stores a new raw metadata snapshot
shows changes where practical
applies changes only because the user requested it
```

## Raw Metadata

Raw provider metadata is preserved for debugging and migration.

Secrets must be redacted before raw metadata is stored.

---

# 14. Database Schema

All public entities use prefixed ULIDs:

```text
stash_...
input_...
item_...
asset_...
broadcast_...
bitem_...
cmd_...
job_...
activity_...
user_...
token_...
```

Using prefixed ULIDs directly as primary keys is acceptable.

## `stashes`

```text
id
name
description
sync_mode             automatic | manual
download_policy       video | audio_only | metadata_only | manual_download
video_quality_profile_id
audio_quality_profile_id
organization_mode     flat | chronological | series | seasoned_series
state                 ready | failed | disabled
created_at
updated_at
```

## `stash_inputs`

```text
id
stash_id
provider_key
input_type            channel | playlist | url_list | video
source_uri
provider_input_id
title
state                 ready | failed | disabled
sync_mode             automatic | manual | null
last_checked_at
next_check_at
last_success_at
last_failure_at
consecutive_failures
created_at
updated_at
```

## `stash_items`

```text
id
stash_id
media_item_id
stash_input_id
state                 active | removed | hidden | ignored
position
season_number
episode_number
season_title
display_title
display_description
first_seen_at
last_seen_at
removed_at
removed_reason
ignored_reason
created_at
updated_at
```

Season/episode/editorial metadata belongs here, not on `media_items`.

## `media_items`

```text
id
provider_key
provider_item_id
canonical_uri
title
description
creator_name
creator_provider_id
duration_seconds
published_at
thumbnail_uri
state                 discovered | metadata_ready | download_pending | downloading | ready | failed | ignored
metadata_captured_at
metadata_refreshed_at
last_seen_upstream_at
upstream_state        available | unavailable | private | deleted | region_blocked | unknown
created_at
updated_at
```

Unique constraint:

```text
unique(provider_key, provider_item_id)
```

## `media_item_sources`

```text
id
media_item_id
stash_input_id
provider_key
provider_input_id
discovered_uri
discovered_at
position
raw_position
```

This records discovery provenance.

## `raw_metadata_snapshots`

```text
id
media_item_id
stash_input_id
provider_key
snapshot_type         discovery | metadata_capture | metadata_refresh | download_info
raw_json
created_at
```

## `assets`

Single asset table for v1.

```text
id
media_item_id
broadcast_id
broadcast_item_id
role                  vault_original | source_thumbnail | subtitle | transcript | podcast_audio | episode_artwork | feed_artwork | feed_xml | nfo | hardlink | metadata_json | source_json
kind                  video | audio | image | subtitle | metadata | link | feed | other
path
relative_path
mime_type
container
video_codec
audio_codec
language
size_bytes
checksum
duration_seconds
derived_from_asset_id
state                 pending | processing | ready | stale | missing | failed
last_verified_at
missing_at
missing_reason
created_at
updated_at
```

## `broadcasts`

```text
id
stash_id
type                  jellyfin_series | plex_series | audio_podcast | video_podcast
name
slug
state                 pending | processing | ready | stale | failed | disabled
token_secret_id
token_preview
settings_json
last_planned_at
last_built_at
last_verified_at
last_error
created_at
updated_at
```

## `broadcast_items`

```text
id
broadcast_id
stash_item_id
media_item_id
state                 pending | processing | ready | stale | failed | disabled
published_path
published_uri
last_published_at
last_verified_at
last_error
created_at
updated_at
```

## `broadcast_triggers`

```text
id
broadcast_id
type                  jellyfin_scan | plex_scan | webhook
enabled
settings_json
state                 ready | failed | disabled
last_triggered_at
last_success_at
last_failure_at
last_error
created_at
updated_at
```

## `broadcast_trigger_runs`

```text
id
trigger_id
reason
state                 pending | processing | ready | failed
started_at
finished_at
response_summary
error
created_at
```

## `commands`

```text
id
type                  stash.sync | stash.backfill | item.refresh_metadata | broadcast.rebuild | broadcast.rotate_token | system.prune_temp
target_type
target_id
options_json
state                 accepted | rejected | running | completed | failed
created_by_user_id
created_at
updated_at
```

## `jobs`

```text
id
command_id
intent                routine_discovery | initial_backfill | metadata_capture | metadata_refresh | download | enrich | broadcast | repair | storage_check
entity_type
entity_id
state                 pending | processing | ready | failed | cancelled
priority
attempts
max_attempts
scheduled_at
started_at
finished_at
heartbeat_at
progress_current
progress_total
progress_percent
progress_label
progress_rate
progress_eta_seconds
last_error
payload_json
created_at
updated_at
```

## `activity_events`

```text
id
level                 info | success | warning | error
type
message
entity_type
entity_id
stash_id
media_item_id
broadcast_id
job_id
command_id
group_key
metadata_json
created_at
```

Activity is stored individually and collapsed in the UI when appropriate.

## `provider_accounts`

```text
id
provider_key
name
auth_type             none | api_key | oauth | cookies | session
secret_id
state                 ready | failed | disabled
last_checked_at
last_error
created_at
updated_at
```

## `provider_strategy_runs`

Optional but useful for diagnostics.

```text
id
provider_key
strategy_key
strategy_purpose      discovery | metadata | download | availability
job_id
cost                  low | medium | high | last_resort
state                 ready | failed
started_at
finished_at
error
metadata_json
```

## `media_server_connections`

```text
id
type                  jellyfin | plex
name
base_uri
token_secret_id
state                 ready | failed | disabled
last_checked_at
last_error
created_at
updated_at
```

## `users`

Single-owner model for v1, but table allows later expansion.

```text
id
username
password_hash
role                  owner
created_at
updated_at
```

## `api_tokens`

```text
id
user_id
name
token_hash
token_preview
scopes_json
last_used_at
expires_at
created_at
revoked_at
```

## `secrets`

Reusable secrets are encrypted using Tempest’s application key through Stashd’s secrets service.

```text
id
key
type
encrypted_value
nonce
metadata_json
created_at
updated_at
last_used_at
revoked_at
```

## `settings`

```text
key
value_json
updated_at
```

Use for app-level settings, not core domain state.

---

# 15. State Names

Use consistent state names where possible.

Common vocabulary:

```text
pending
processing
ready
stale
failed
missing
disabled
ignored
```

Entity-specific detail belongs in reason/error fields:

```text
last_error
failure_reason
missing_reason
removed_reason
ignored_reason
last_checked_at
```

Avoid `archived` as an internal state because it can imply inactive/hidden.

---

# 16. Provider Strategy Model

## Principle

A provider is a capability bundle, not a downloader.

Providers expose strategies for:

```text
discovery
metadata
download
availability
authentication/session handling
```

## Provider Interface Shape

Conceptual PHP-ish model:

```php
interface Provider
{
    public function key(): string;

    public function name(): string;

    public function supportsUri(StashdUri $uri): bool;

    public function resolveInput(StashdUri $uri): ResolvedInput;

    /** @return list<DiscoveryStrategy> */
    public function discoveryStrategies(): array;

    /** @return list<MetadataStrategy> */
    public function metadataStrategies(): array;

    /** @return list<DownloadStrategy> */
    public function downloadStrategies(): array;
}
```

## URI Handling

Use a Stashd-owned URI value object around PHP 8.5 native URI/URL support.

Raw URL strings should only appear at input/output boundaries.

Application code should use typed URI objects.

## Strategy Profile

Strategies declare:

```text
key
purpose
cost                  low | medium | high | last_resort
requires_auth
supports_incremental
supports_backfill
supports_private_items
preferred_for_routine_use
priority
```

## Job Intents Drive Strategy Choice

Job intents include:

```text
routine_discovery
initial_backfill
metadata_capture
metadata_refresh
download
repair
broadcast
enrich
storage_check
```

The scheduler creates jobs with intent. Providers select the cheapest reliable strategy for that intent.

## YouTube v1 Strategy Model

For YouTube:

```text
Routine discovery:
  YouTube RSS feed

Initial backfill:
  YouTube Data API where configured
  yt-dlp fallback if necessary

Metadata capture:
  YouTube Data API where configured
  yt-dlp fallback if necessary

Download:
  yt-dlp via ytdlphp

Heavy inspection:
  yt-dlp fallback, used sparingly
```

## Future Provider Sanity Check

The same model should support:

```text
Twitch:
  Helix/EventSub discovery
  Helix metadata
  yt-dlp download

PeerTube:
  API/feed discovery
  API metadata
  direct/HLS download or yt-dlp fallback

Nebula:
  auth/session-heavy discovery/metadata/download
  likely yt-dlp-backed

Vimeo:
  API discovery/metadata
  API or yt-dlp download

Internet Archive:
  search/metadata APIs
  direct file download
```

---

# 17. Scheduler and Sync

## User-Facing Sync Modes

Only two user-facing modes:

```text
Automatic
Manual
```

### Automatic

Stashd chooses the safest and most efficient discovery strategy.

Internally, it may use:

```text
adaptive intervals
RSS polling
API polling
jitter
backoff
provider strategy costs
recent activity signals
failure history
```

### Manual

Stashd performs discovery only when the user explicitly triggers it.

## Scheduler Fields

Inputs should track:

```text
last_checked_at
next_check_at
last_success_at
last_failure_at
consecutive_failures
last_new_item_at
strategy_hint
```

The scheduler creates jobs. It does not perform provider work directly.

---

# 18. Download Policy

Each stash has a download policy:

```text
video
audio_only
metadata_only
manual_download
```

## Meaning

```text
video:
  automatically download video + audio

audio_only:
  download/extract/generate audio for audio use

metadata_only:
  discover and capture metadata, but do not download media

manual_download:
  discover items but wait for user action before downloading
```

## Queue Rule

All downloads go through the persistent job queue.

Even “Download now” creates a high-priority download job.

## Concurrency

Default max concurrent downloads:

```text
1
```

Concurrency is global in v1.

Provider-aware backoff, cooldowns, and jitter should exist to avoid bot-like behavior.

---

# 19. Format and Quality Profiles

## Vault vs Broadcast Assets

The Vault preserves downloaded source media as closely as practical.

Broadcasts may generate derived assets.

## Video

Video transcoding is disabled by default.

Preferred behavior:

```text
download best match according to profile
hardlink when possible
remux when allowed/needed
transcode only if explicitly enabled
```

Quality/profile examples:

```text
Preserve Original
Media Server Compatible
Media Server Efficient
Best Available
Up to 1080p
Up to 720p
Smallest reasonable file
```

No universal H.265 default. H.265/HEVC can be an advanced preference.

## Audio Podcast

Audio podcast broadcasts may generate derived audio files through a
Podcast-plugin-owned native helper. The helper and its media profile are not
part of Stashd core.

Default v1 podcast audio profile:

```text
MP3
128 kbps
stereo
```

Alternative future/advanced profile:

```text
AAC/M4A
96 kbps
```

## Disk Usage Warnings

Any format/profile setting that creates derived files must show disk impact clearly.

---

# 20. Stash Filters

V1 supports stash-level filters.

Required v1 filter:

```text
title regex include/exclude
```

Optional useful filters:

```text
description regex
minimum duration
maximum duration
published date constraints
```

## Filter Timing

Filters run after discovery and metadata capture, before download.

```text
discover item
capture metadata
apply stash filters
download / skip / metadata-only
```

## Filter Result

Excluded items are not silently discarded.

They are retained as ignored/skipped with a reason:

```text
stash_items.state = ignored
stash_items.ignored_reason = filter_title_regex
```

## UX

The UI should allow testing filters against recent items.

Example:

```text
This rule would download 18 of the latest 50 items.
```

Invalid regex must not crash workers.

---

# 21. Broadcast System

## Broadcast Principle

A broadcast is a generated, rebuildable view of a stash.

Broadcasts do not own canonical media.

## V1 Broadcast Types

```text
Jellyfin Series
Plex Series
Audio Podcast
Video Podcast
```

No top-level Broadcasts navigation in v1. Broadcasts are managed inside Stashes.

## Broadcast Lifecycle

Each broadcast type implements:

```text
plan
prepare assets
publish
verify
trigger
prune
```

## Broadcast Interface

Conceptual model:

```php
interface BroadcastType
{
    public function key(): string;

    public function name(): string;

    public function plan(Broadcast $broadcast): BroadcastPlan;

    public function prepare(BroadcastPlan $plan): void;

    public function publish(BroadcastPlan $plan): void;

    public function verify(Broadcast $broadcast): BroadcastVerificationResult;

    public function prune(Broadcast $broadcast): BroadcastPruneResult;
}
```

## Staleness

A broadcast becomes `stale` when dependencies change:

```text
new item added
item hidden
season mapping changed
artwork changed
settings changed
Vault asset replaced
subtitle added
funding link changed
```

`stale` means regeneration is needed. It does not mean broken.

## Disk Usage Safety

Broadcast planning should estimate disk impact where possible.

Example:

```text
This audio podcast broadcast will generate approximately 42 GB of audio files.
```

---

# 22. Broadcast Triggers

Broadcasts may define post-publish triggers.

Examples:

```text
Jellyfin library scan
Plex library scan
Webhook
```

Triggers run after files/assets are published and verified.

Trigger failure should not necessarily mark the broadcast failed if generated files are valid.

Example:

```text
Broadcast item: ready
Jellyfin trigger: failed
Reason: invalid API key
```

Trigger execution should be batched.

Do not trigger Jellyfin/Plex once per item during large backfills.

---

# 23. Podcast Broadcasts

## Feed URL

Podcast and HTTP-served broadcasts use private tokenized path URLs.

Example:

```text
/b/{broadcast-token}/feed.xml
/b/{broadcast-token}/items/{item-token}/episode.mp3
/b/{broadcast-token}/artwork/{item-token}.jpg
```

Avoid query-string auth because some podcast clients handle query params poorly.

## Token Storage

Broadcast tokens are stored encrypted at rest so the UI can always show/copy the private feed URL.

Tokens remain:

```text
private
revocable
rotatable
unique per broadcast
path-based
```

UI warning:

```text
Anyone with this URL can access this broadcast.
Regenerate the URL if it was shared accidentally.
```

## Feed Artwork

Podcast feed artwork is generated automatically from the source/channel profile image.

Feed artwork should be editable or replaceable by the user.

## Episode Artwork

Podcast episode artwork is generated automatically from source thumbnails.

Episode artwork does not need to be editable in v1.

Future versions may add smarter cropping to preserve text/faces/subjects.

## Funding Links

Stashd v1 automatically detects creator funding/support links and includes the best available link in podcast broadcasts.

Funding links may come from:

```text
video descriptions
channel/about metadata
playlist descriptions
provider metadata
manual stash settings
```

Known targets:

```text
Patreon
Ko-fi
Buy Me a Coffee
YouTube Membership
Nebula
creator website
merch store
Substack
GitHub Sponsors
Open Collective
```

Selection priority:

```text
1. Manual funding link set by user
2. Patreon / Ko-fi / Buy Me a Coffee / GitHub Sponsors / Open Collective
3. YouTube Membership
4. Nebula / creator-owned subscription platform
5. Creator website
6. Merch store
7. First plausible support link found
```

The selected funding link is shown transparently in the UI and can be corrected.

## Creator Engagement

Stashd must not simulate upstream views, watch time, likes, comments, or other engagement.

Future versions may add creator support reminders after repeated local listens/views.

---

# 24. Enrichment Pipeline

Subtitles, transcripts, and translations are enrichment assets.

They are not part of the core v1 requirement, but the architecture must support them.

## Enrichment Flow

```text
Media item ready
  ↓
subtitle discovery
  ↓
subtitle capture
  ↓
optional transcription
  ↓
optional translation
  ↓
broadcasts consume subtitle/transcript assets
```

## Provenance

Subtitle/transcript assets should record provenance:

```text
provider_official
provider_auto_generated
local_transcription
machine_translated
user_uploaded
```

Also record:

```text
source language
target language
format
generation method
```

## Opt-In Requirements

Any enrichment that creates additional files, uses heavy compute, or sends data to external services must be explicitly opt-in and clearly described in the UI.

---

# 25. Authentication and Access Control

## v1 Model

Single-owner authentication model.

```text
one admin/owner user
session-based UI login
API tokens for automation
private broadcast path tokens
```

No multi-user permissions or OIDC in v1.

## Web/API

The web UI requires login.

The API supports scoped API tokens.

API tokens are shown once and stored hash-only.

## Broadcasts

Podcast feeds and HTTP-served broadcast media use path tokens.

Media-server broadcasts are filesystem views consumed by Plex/Jellyfin and do not require Stashd HTTP authorization.

## Private by Default

Everything is private by default.

No public catalog. No unauthenticated web UI.

---

# 26. Secrets

Stashd uses Tempest’s application key as the root encryption material.

All application code should go through a Stashd-owned secrets service.

Reusable secrets stored encrypted:

```text
broadcast feed tokens
YouTube Data API key
future provider sessions/cookies
Jellyfin API keys
Plex tokens
```

Hash-only secrets:

```text
admin password
API tokens
sessions
```

Secrets must be redacted from:

```text
logs
activity events
job payloads
raw metadata snapshots
API error details
```

If the Tempest app key changes and secrets cannot decrypt, the UI should explain recovery:

```text
restore original app key
clear stored credentials
rotate broadcast URLs
```

---

# 27. REST API and Commands

## API Style

REST-ish JSON API under:

```text
/api/v1
```

The UI uses the same API as external clients.

No private UI-only backend paths for normal operations.

## Resources

Core resources:

```text
/stashes
/items
/jobs
/activity
/settings
/system
```

Broadcasts are managed under stashes.

Example:

```http
GET    /api/v1/stashes
POST   /api/v1/stashes
GET    /api/v1/stashes/{id}
PATCH  /api/v1/stashes/{id}

GET    /api/v1/items
GET    /api/v1/items/{id}

GET    /api/v1/stashes/{id}/broadcasts
POST   /api/v1/stashes/{id}/broadcasts
GET    /api/v1/stashes/{id}/broadcasts/{broadcastId}
PATCH  /api/v1/stashes/{id}/broadcasts/{broadcastId}

POST   /api/v1/commands
GET    /api/v1/commands/{id}

GET    /api/v1/jobs
GET    /api/v1/jobs/{id}
```

## Commands

Avoid resource-specific action endpoints where practical.

User/system actions are submitted as commands:

```http
POST /api/v1/commands
```

Example:

```json
{
  "type": "broadcast.rebuild",
  "target_id": "broadcast_01JZ8MA8G...",
  "options": {
    "full": true
  }
}
```

Response:

```json
{
  "id": "cmd_01JZ8P...",
  "state": "accepted",
  "jobs": [
    "job_01JZ8Q..."
  ]
}
```

## Error Shape

```json
{
  "error": {
    "code": "storage_hardlink_unavailable",
    "message": "Hardlinks are unavailable between Vault and Broadcasts.",
    "details": {}
  }
}
```

Error details must be secret-safe.

## OpenAPI

Document the API with OpenAPI from early development.

---

# 28. Jobs, Progress, and SSE

## Jobs

Jobs represent executable background work.

All long-running operations create jobs.

Examples:

```text
routine discovery
initial backfill
metadata capture
download
broadcast rebuild
storage check
transcode
podcast audio generation
```

## Progress

Every long-running task should expose progress whenever possible.

Examples:

```text
Downloading: 42% · 12.4 MB/s · ETA 8m
Backfill: 180 / 612 items
Broadcast rebuild: 120 / 438 items
Transcoding: 63% · ETA 4m
```

Unknown totals should still show useful labels:

```text
Scanning playlist page 3
Waiting for Jellyfin scan request
```

## Heartbeats

Running jobs update `heartbeat_at`.

Stalled jobs can be detected and retried/recovered.

## Live Updates

Use:

```text
REST API for reads/writes/commands
SSE for live updates
Polling fallback where SSE is unavailable
```

SSE event examples:

```text
job.created
job.progress
job.completed
job.failed
activity.created
storage.warning
system.health_changed
worker.heartbeat
```

Events are notifications, not the source of truth.

---

# 29. Activity, Logging, and Observability

## Activity

Activity is user-facing.

It answers:

```text
What has Stashd been doing?
```

Activity events are stored individually.

The UI may collapse related events.

Example DB rows:

```text
item.discovered → Episode 101
item.discovered → Episode 102
item.discovered → Episode 103
```

UI display:

```text
Found 3 new episodes in Oculus Imperia
```

Expandable:

```text
Episode 101
Episode 102
Episode 103
```

Grouping should be based on:

```text
event type
stash
command/job
short time window
```

## Logs

Logs are for debugging.

Support:

```text
text logs
JSON logs
```

Default:

```text
text
```

Use structured context:

```text
job_id
command_id
stash_id
broadcast_id
provider_key
strategy_key
duration_ms
error_code
```

Never log secrets.

## Health

Simple endpoint:

```http
GET /health
```

Detailed authenticated endpoint:

```http
GET /api/v1/system/health
```

Detailed health includes:

```text
database writable
storage roots ready
workers alive
scheduler running
yt-dlp available
bundled plugin helper availability
provider credential status
media-server connection status
disk warnings
```

## Error Codes

Expected failures should have stable error codes.

Examples:

```text
storage_hardlink_unavailable
storage_root_unavailable
provider_auth_failed
provider_rate_limited
download_failed
download_missing_output
broadcast_trigger_failed
metadata_capture_failed
secret_decrypt_failed
```

Errors should be actionable in the UI.

---

# 30. UI Design and Interaction Model

## UI Personality

Stashd should feel like a NAS appliance or self-hosted system dashboard, not a streaming platform.

The UI should be:

```text
calm
dense
fast
clear
dark-mode friendly
low-animation
debuggable
trustworthy
```

Avoid:

```text
YouTube clone
social feed
giant entertainment cards everywhere
growth-hacking UI
mystery spinners
heavy gradients
excessive animation
SaaS marketing polish
```

## Visual Reference

Stashd’s visual design should take inspiration from:

```text
Glance: https://github.com/glanceapp/glance
```

Specifically:

```text
lightweight self-hosted dashboard
compact cards
clear status indicators
muted dark-friendly colors
strong information hierarchy
minimal visual noise
```

Do not copy Glance directly.

## Typography

Stashd should lean into monospace or semi-monospace typography.

Use monospace generously for:

```text
job IDs
logs
storage paths
commands
API tokens
feed URLs
provider IDs
filenames
diagnostics
progress output
technical tables
```

A fully monospace UI is acceptable if it remains readable and polished.

Avoid novelty terminal gimmicks.

## Navigation

V1 navigation:

```text
Dashboard
Stashes
Vault
Activity
Settings
```

No top-level Broadcasts page in v1.

Broadcasts live under Stashes.

## Create Stash Flow

```text
New Stash
  → paste URL
  → preflight resolves source
  → choose download policy
  → choose broadcasts
  → review disk/API/CPU impact
  → create
  → jobs run with visible progress
```

## Preflight

When a user pastes a URL, Stashd should run a lightweight preflight job.

Preflight should:

```text
resolve provider/input
fetch basic metadata
estimate item count where possible
estimate duration/storage where possible
show approximate impact
```

Use the cheapest safe provider strategy.

Avoid heavy yt-dlp inspection unless necessary.

## Command UX

Long-running actions should not block the UI.

The UI should say:

```text
Sync queued
Backfill started
Broadcast rebuild queued
Metadata refresh queued
```

Then show live progress through jobs/activity.

## Storage Warnings

Any setting that may increase disk, CPU, provider API, network, or external service usage must clearly say so before being enabled.

Examples:

```text
This audio podcast broadcast will create additional audio files.
Estimated size: ~60 MB per hour at 128 kbps.
```

```text
Hardlinks are unavailable. Enabling copies may duplicate media files.
Estimated additional storage: up to 840 GB.
```

## Error UX

Errors should explain:

```text
what happened
what is affected
how to fix it
```

Bad:

```text
Job failed.
```

Good:

```text
Jellyfin scan failed

Stashd published the files successfully, but Jellyfin rejected the scan request.

Reason:
Invalid API key.

Fix:
Update the Jellyfin API key in Settings → Media Servers.
```

## Progressive Disclosure

Simple users should see:

```text
Create Stash
Choose Broadcast
Done
```

Advanced users can configure:

```text
playlist-as-season
title regex filters
format profiles
storage fallback policy
provider strategy diagnostics
broadcast triggers
```

## Explain Generated Files

Every generated file should be explainable:

```text
Generated by: Oculus Imperia Audio Podcast
Role: podcast audio
Derived from: Vault original
Can be regenerated: yes
Safe to delete: yes, after broadcast rebuild
```

---

# 31. Testing Strategy

## Test Layers

Stashd uses layered testing:

```text
unit tests
filesystem integration tests
provider fixture tests
mocked downloader tests
broadcast generation tests
job/worker tests
API tests
Docker smoke tests
```

## Unit Tests

Test pure logic:

```text
filename sanitization
ULID validation
state transitions
strategy selection
storage estimates
RSS/feed generation
funding-link detection
subtitle language handling
broadcast planning
activity grouping
```

## Filesystem Integration Tests

Test:

```text
hardlink detection
hardlink creation
symlink disabled behavior
copy fallback disabled behavior
atomic writes
partial download cleanup
missing Vault asset behavior
missing broadcast asset rebuild
storage root unavailable behavior
filename/path sanitization
```

## Provider Tests

Provider tests use fixtures, not live services by default.

Fixtures:

```text
YouTube RSS feed XML
YouTube Data API JSON
yt-dlp metadata JSON
future Twitch/PeerTube fixtures
```

## Download Tests

Normal CI mocks ytdlphp/downloader behavior.

Live provider/download tests are opt-in only.

Example flag:

```text
STASHD_LIVE_PROVIDER_TESTS=1
```

## Broadcast Tests

Podcast tests:

```text
feed XML generation
path-token URLs
episode GUID stability
feed artwork generation
episode artwork generation
funding tag inclusion
audio enclosure metadata
atomic feed writes
```

Jellyfin/Plex tests:

```text
folder layout
filename generation
season/episode naming
hardlink generation
NFO generation
subtitle copying/linking
stale detection
rebuild
prune
```

## Fake Provider

Build a fake provider early.

It should simulate:

```text
channel with 3 items
playlist with 20 items
new item appears after sync
metadata fetch failure
download failure
rate limit
private/deleted item
```

This supports reliable UI development and E2E tests without touching YouTube.

## Docker Smoke Tests

Docker smoke tests are a release gate.

They must validate:

```text
container starts
FrankenPHP and supervisord start
Tempest boots
SQLite database is created/migrated
storage roots are created
storage checks pass
health endpoint returns ok
admin/bootstrap flow works
worker process starts
scheduler process starts
fake provider can discover items
fake download writes media to Vault
fake broadcast writes output
SSE endpoint connects
logs contain no obvious fatal errors
container stops cleanly
restart preserves data/secrets/feed URLs
```

Common homelab scenarios:

```text
default bind mounts: ./data and ./media
PUID/PGID-style permissions
non-root runtime user
hardlinks under default /media layout
restart persistence
secret/key persistence
feed URL survival after restart
fake stash → fake download → fake podcast feed
fake stash → fake download → fake Jellyfin broadcast
```

A build that passes unit tests but cannot reliably boot under Docker is not releasable.

---

# 32. Packaging and Release Strategy

## Official Distribution

v1 ships primarily as:

```text
official Docker image
minimal Docker Compose file
```

Everything else is secondary.

Future:

```text
Unraid template
TrueNAS app
Helm chart
bare-metal PHP install docs
```

## Registry

Initial registry:

```text
ghcr.io/hipsterjazzbo/stashd
```

## Tags

```text
latest
v1
v1.0
v1.0.3
edge
```

Recommended:

```yaml
image: ghcr.io/hipsterjazzbo/stashd:v1
```

## Architectures

Support:

```text
linux/amd64
linux/arm64
```

## Image Contents

The image includes:

```text
PHP 8.5+
Tempest app
FrankenPHP/Caddy
SQLite extension
yt-dlp
ytdlphp
ca-certificates
tzdata
image processing support
```

## Startup Behavior

On boot:

```text
validate paths
create required directories
ensure Tempest app key exists/persisted
run database migrations
run storage capability checks
start FrankenPHP/Caddy
start supervisord worker/scheduler roles
```

Errors must be clear and actionable.

Example:

```text
Stashd cannot write to /media/vault.

The container is running as UID 1000:GID 1000.
Check ownership of ./media or set PUID/PGID.
```

## Migrations

Before database migrations:

```text
create automatic SQLite backup
run migration
record app version
record migration history
```

Example backup:

```text
/data/backups/stashd-before-v1.0.4-2026-06-16.sqlite
```

Keep a small rolling set of backups.

## Versioning

Use SemVer.

Breaking changes include:

```text
incompatible database migration
config path change
broadcast URL format change
API breaking change
broadcast folder layout requiring rebuild
```

## Release Gate

Before publishing:

```text
unit tests pass
static analysis passes
filesystem tests pass
API tests pass
Docker smoke tests pass
migration smoke tests pass
multi-arch build passes
```

---

# 33. v1 Scope

## v1 Must Ship

```text
PHP 8.5+
Tempest
FrankenPHP/Caddy
SQLite only
Docker-first deployment
default port 8474
YouTube provider
YouTube RSS discovery
YouTube Data API metadata/backfill where configured
yt-dlp via ytdlphp download
provider strategy cost model
media-item-first Vault
required Vault
Jellyfin Series broadcast
Plex Series broadcast
Audio Podcast broadcast
Video Podcast broadcast
podcast path tokens
recoverable encrypted broadcast tokens
podcast feed artwork
auto-generated episode artwork
podcast funding links
Jellyfin/Plex scan triggers
title regex filters
commands API
persistent jobs
SSE live progress
activity timeline with UI grouping
storage diagnostics
filesystem drift tolerance
single-owner auth
encrypted secrets via Tempest app key
Docker smoke tests
automatic SQLite migration backups
```

## v1 Should Prepare For

```text
third-party plugin runtime
additional providers
OPDS broadcast
local transcription
translated subtitles
creator support reminders
multi-user permissions
OIDC/reverse-proxy auth
Prometheus metrics
distributed workers
native downloader
advanced artwork editor
smart thumbnail cropping
advanced season poster generation
```

## v1 Explicit Non-Goals

```text
No multi-user permission system
No cloud service
No required external database
No Redis/RabbitMQ
No Kubernetes-first deployment
No plugin marketplace
No YouTube frontend replacement
No recommendation engine
No media playback focus
No automatic upstream view/engagement simulation
No video transcoding by default
No optional Vault-free mode
No live provider tests required in normal CI
```

---

# 34. Future Roadmap

## v1.1 / Early Post-v1

```text
OPDS / Vault Catalog broadcast
subtitle capture improvements
automatic transcript support
translated subtitle pipeline
PeerTube provider
Twitch VOD provider
Internet Archive provider
better artwork generation
creator support reminders
Prometheus metrics
Unraid template
TrueNAS app
OIDC/reverse-proxy auth
advanced storage cleanup tools
```

## v2 Candidates

```text
distributed workers
multi-user / role-based permissions
third-party plugin runtime
native provider-specific downloaders
storage tiers
object storage support
AI summaries/search
full-text transcript search
chapter metadata model
source/provider chapter capture
multiple Stashd nodes
advanced editorial metadata editor
```

---

# 35. Release Philosophy

v1 is not “all planned features.”

v1 is:

> **The core loop is boringly reliable.**

A successful v1 user can:

```text
docker compose up
create account
stash a YouTube channel
download new videos automatically
broadcast as podcast or Jellyfin/Plex series
see live progress
understand storage impact
recover from failures
upgrade safely
```

If Stashd becomes boring infrastructure, it has succeeded.

---

# 36. Future: Chapters

## Status

Chapter support is **not required for v1**, but the v1 data model should not make it difficult to add later.

## Native / Provider Chapters

yt-dlp can expose chapter information where the source provides it, and Stashd should treat source/provider chapters as metadata or enrichment assets.

Future chapter metadata should support:

```text
media_item_id
source                 provider | yt_dlp | user
kind                   chapter | marker
category               chapter | other
title
start_seconds
end_seconds
confidence
external_id
raw_json
state                  pending | ready | stale | failed
created_at
updated_at
last_checked_at
```

Source/provider chapters should be preserved rather than flattened into broadcast-specific output too early.

Broadcasts may later consume chapter data for:

```text
podcast chapters
Jellyfin/Plex chapter metadata
NFO/sidecar files
embedded media chapters
chapter-aware UI display
```

## Suggested Future Jobs

```text
chapters.capture
chapters.refresh
broadcast.refresh_chapters
media_server.refresh_metadata
```

## Rules

- Stashd should not destructively modify Vault originals.
- Media-server broadcasts should be marked stale when new chapter metadata becomes available.
- Podcast broadcasts should be able to regenerate feeds/chapters when chapter metadata changes.

## v2 Roadmap Note

Add to v2 candidates:

```text
chapter metadata model
source/provider chapter capture
media-server metadata refresh when new chapters arrive
```

## Misc Notes

Smoke tests store app paths as container paths; host assertions must translate /media and /data through mounted temp dirs.
