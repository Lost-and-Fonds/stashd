# Stashd -- Product Vision & Technical Architecture (v1)

> **Mission:** Build the fastest, most reliable self-hosted platform for
> preserving online video libraries and exposing them as first-class
> media collections.

------------------------------------------------------------------------

# North Star

**Stashd should be the fastest, most reliable way to keep a permanent
local copy of online video libraries while staying invisible until you
need it.**

------------------------------------------------------------------------

# Product Vision

Stashd is infrastructure, not an app.

It quietly mirrors online video sources into a local library, enriching
metadata and exposing the resulting collection through integrations such
as Plex, Jellyfin and private podcast feeds.

The goal is preservation, automation and ownership---not replacing the
original platform.

------------------------------------------------------------------------

# Design Principles

## Fast first

-   Incremental syncs
-   Cache aggressively
-   Never perform work twice
-   Detect new uploads as quickly as possible

## Reliability over cleverness

-   Missing a video is worse than downloading one twice.
-   Persistent queues.
-   Idempotent operations.
-   Crash-safe everywhere.

## Self-hosted first

-   No cloud dependency
-   No telemetry
-   LAN-friendly
-   Docker-first deployment

## Respect user libraries

-   Never rename existing media unexpectedly
-   Never move files automatically
-   Stable paths
-   Predictable metadata

## Platform agnostic

YouTube is simply the first provider.

Everything should be designed around generic providers.

------------------------------------------------------------------------

# High-Level Architecture

``` text
Sources
   │
   ▼
Discovery Engine
   │
   ▼
Sync Planner
   │
   ▼
Persistent Download Queue
   │
   ▼
Download Workers
   │
   ▼
Metadata Pipeline
   │
   ├────────► Database
   │
   ▼
Library Storage
   │
   ▼
Integration Layer
   ├── Plex
   ├── Jellyfin
   ├── RSS/Podcast
   └── REST API
```

------------------------------------------------------------------------

# Core Components

## Discovery Engine

Responsible only for discovering content.

Responsibilities:

-   Track channels
-   Track playlists
-   Detect uploads
-   Retry failures
-   Respect rate limits

------------------------------------------------------------------------

## Sync Planner

Compares desired state with current state.

Produces work items.

Should never perform downloads directly.

------------------------------------------------------------------------

## Download Queue

Persistent.

Recoverable after crashes.

Supports:

-   priorities
-   retries
-   cancellation
-   concurrency limits

------------------------------------------------------------------------

## Download Workers

Workers consume queue entries.

Responsible for:

-   downloading media
-   thumbnails
-   subtitles
-   chapters
-   verification
-   retry policy

------------------------------------------------------------------------

## Metadata Pipeline

Normalizes provider-specific metadata into a provider-independent
schema.

------------------------------------------------------------------------

## Storage Layer

Responsible only for storing:

-   video
-   artwork
-   subtitles
-   metadata

Should know nothing about YouTube.

------------------------------------------------------------------------

## Integration Layer

Transforms stored media into external integrations.

Examples:

-   Jellyfin
-   Plex
-   Podcast feeds
-   REST API

------------------------------------------------------------------------

# Provider Model

Every provider should implement a common interface.

``` text
Discover()

ListItems()

FetchMetadata()

Download()

FetchArtwork()

FetchSubtitles()
```

Future providers should require minimal changes elsewhere.

------------------------------------------------------------------------

# State Machine

Represent reality, not assumptions.

``` text
Unknown
↓

Discovered
↓

MetadataFetched
↓

Queued
↓

Downloading
↓

Downloaded
↓

Verified
↓

Published
```

Recovery should simply continue from the last completed state.

------------------------------------------------------------------------

# API Philosophy

Everything the UI can do should be possible through the API.

Benefits:

-   CLI
-   automation
-   scripting
-   future mobile apps
-   third-party integrations

------------------------------------------------------------------------

# UI Philosophy

The UI should feel like a NAS.

Characteristics:

-   dense information
-   keyboard friendly
-   searchable
-   responsive
-   dark mode first
-   minimal animations

------------------------------------------------------------------------

# MVP Scope

## Sources

-   YouTube Channels
-   YouTube Playlists

## Outputs

-   Plex-compatible library
-   Jellyfin-compatible library
-   Video podcast feeds
-   Audio podcast feeds

## Features

-   automatic sync
-   manual sync
-   retries
-   queue inspection
-   download history
-   health dashboard
-   logs

------------------------------------------------------------------------

# Explicit Non-Goals

Stashd is **not**:

-   a media player
-   a YouTube frontend
-   a recommendation engine
-   a torrent client
-   a social platform
-   a video editor

Integrate with existing tools instead of replacing them.

------------------------------------------------------------------------

# Roadmap

## MVP

-   single-node deployment
-   YouTube provider
-   downloads
-   podcast feeds
-   Plex/Jellyfin

## v1.0

-   webhooks
-   REST API
-   CLI
-   import/export
-   search

## Future

-   additional providers
-   distributed workers
-   transcripts
-   OCR
-   AI summaries
-   storage tiers
-   deduplication

------------------------------------------------------------------------

# Open Design Decisions Before Coding

These decisions should be finalized before implementation begins.

## Language & Framework

-   Go, Rust, or another language?
-   Web framework?
-   Background job library?

## Database

-   PostgreSQL for MVP?
-   PostgreSQL support?
-   Migration strategy?

## Download Backend

-   Wrap yt-dlp?
-   Native implementation?
-   Hybrid approach?

## Storage Layout

Directory structure:

    Library/
        Creator/
            YYYY-MM-DD - Title/
                video.ext
                thumbnail.jpg
                subtitles.vtt
                metadata.json

Need to finalize naming conventions.

## Metadata Schema

Define:

-   canonical video model
-   provider IDs
-   artwork
-   subtitles
-   chapters

## Scheduler

Need to decide:

-   polling intervals
-   adaptive scheduling
-   backoff strategy
-   priority rules

## Authentication

-   Local users?
-   Reverse proxy auth?
-   API keys?
-   OIDC later?

## Configuration

-   YAML
-   TOML
-   environment variables
-   web UI

Need one source of truth.

## API Design

-   REST
-   OpenAPI
-   versioning strategy

## Logging

Need structured logging.

Decide:

-   JSON
-   text
-   log levels
-   audit log

## Testing Strategy

-   unit tests
-   integration tests
-   end-to-end Docker environment

## Packaging

Primary distribution:

-   Docker image
-   Docker Compose example

Later:

-   Helm
-   Unraid template
-   TrueNAS app

------------------------------------------------------------------------

# Suggested Repository Structure

``` text
cmd/
internal/
pkg/
web/
migrations/
docs/
docker/
examples/
```

Keep provider implementations isolated from core orchestration.

------------------------------------------------------------------------

# Success Criteria

A successful MVP should allow a user to:

1.  Start a Docker container.
2.  Add a YouTube channel.
3.  Automatically mirror uploads.
4.  Watch content in Plex/Jellyfin.
5.  Subscribe to a private podcast feed.
6.  Never think about Stashd again unless something needs attention.

If the software becomes "boring infrastructure," it has succeeded.


---

# Brand & UI Direction Update

> **Updated:** 2026-06-16

The current preferred identity for Stashd is **wordmark-first**:

```text
stashd_
```

The trailing underscore is part of the brand, not decoration. It evokes a terminal cursor, daemon process, command prompt, and quiet background service.

## Core Brand Stack

```text
stashd_
Because the internet forgets.

Save the online media you care about to a local Vault, then broadcast it to Jellyfin, Plex, or your podcast app.

Your archive. Your rules.
```

## Brand Sentence

> Stashd turns fragile online media into a local archive you control, then rebroadcasts it into the apps you already use: Jellyfin, Plex, and private podcast feeds.

## Visual Direction

The UI and brand should feel like:

```text
Glance crossed with a well-designed system dashboard
```

Preferred visual traits:

- warm espresso-charcoal / dark brown-black backgrounds
- muted graphite-brown panels
- soft off-white or warm cream text
- muted amber accent
- monospace or semi-monospace typography
- compact dashboard density
- subtle borders
- calm technical presentation
- minimal visual noise

The primary logo should remain the wordmark `stashd_`.

The preferred favicon/app icon is a rounded-square or rounded-rectangle tile containing only the amber underscore.

Avoid using a mascot or a literal vault/play icon as the primary identity for now. The wordmark plus underscore system is simpler, more distinctive, and better aligned with the self-hosted daemon feel.

## Tone

Stashd should feel:

- calm
- technical
- self-hosted
- preservation-minded
- trustworthy
- quietly competent
- slightly cheeky

The project should retain a subtle “private little archive” energy without becoming piracy-coded.

Good microcopy examples:

```text
Tucked safely away.
No judgement. It’s in the Vault now.
Your Vault is your business.
Saved before it vanished.
```

Official messaging should stay trustworthy and preservation-focused.
