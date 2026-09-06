# Stashd

**Stashd is a self-hosted tool for preserving media you care about.**

The basic idea is simple:

**find something → preserve it properly → keep the useful context around it → make it available somewhere convenient**

In Stashd terms, that currently looks roughly like:

**Inputs → Stashes → Vault → Broadcasts**

A stash describes something you want to preserve. Inputs bring material into it. The vault holds the canonical archived copies. Broadcasts make those copies useful again — as files, podcast feeds, media-library entries, or eventually other formats.

Stashd is currently in **active development**. It works, I use it, and parts of it are already quite useful. It is also absolutely not finished, the architecture is still evolving, and I am willing to rip out systems that turn out to have been bad ideas.

If you are looking for mature, boring archival infrastructure that you can install and forget about for ten years, this is probably not that yet.

If the idea interests you, though, welcome.

## Why Stashd exists

A surprising amount of the media I value lives somewhere I don't control.

Podcasts disappear. YouTube channels get deleted. Websites rot. Feeds change. Social platforms become hostile, shut down APIs, or simply stop existing. A creator may publish one body of work across half a dozen services, all of which preserve a different fragment of the actual thing.

Downloading a file solves only part of that problem.

I want to preserve the **work**, not merely the bytes.

That means keeping things like:

- titles and descriptions
- publication dates
- artwork
- chapters
- subtitles and transcripts
- original URLs
- relationships between related items
- provenance: where something came from and how it was acquired
- enough metadata to make the archive intelligible years later

And after preserving it, I want to be able to actually use it.

An archive that can only be admired through a filesystem tree is technically an archive, but it isn't a particularly pleasant one.

## What I'm aiming for

Stashd is intended to become a fairly opinionated personal preservation system.

Some of the goals are:

### Preserve once, use anywhere

There should be one canonical archived copy of an item in the vault.

From there, Stashd should be able to expose or transform it for other systems without creating a pile of unrelated duplicate archives.

For example, the same preserved podcast or video might appear through:

- a podcast feed
- Jellyfin or Plex
- a normal filesystem
- a static archive
- an offline knowledge collection
- another preservation service

The vault is the record. Everything else is a view of it.

### Preserve context, not just files

Media items don't exist in isolation.

A podcast episode may have an accompanying blog post. A social post might announce or link to it. A video may have captions, chapters, artwork, references, or a corrected description added later.

Stashd should be able to preserve those relationships rather than flattening everything into an undifferentiated directory of files.

### Let different kinds of sources belong together

A stash should describe **the thing being preserved**, not merely the protocol used to fetch it.

So, for example, one stash might eventually contain:

- a podcast feed
- a website
- a YouTube channel
- public social posts

…because all of them are parts of the same publication or project.

The transport is an implementation detail. The archive is the point.

### Fixity

Preservation is not just keeping a file around. At some point, you also need to be able to answer:

**Is this still the same file I originally preserved?**

Stashd already has some basic integrity machinery. Downloads are staged before being promoted into the Vault, routine checks can verify that files still exist, have the expected size and remain readable, and deeper checks can validate media or recalculate checksums.

That is useful, but it is not the finished fixity model.

The direction is to make fixity a first-class property of every preserved asset:

- record a cryptographic checksum when an asset enters the Vault
- retain the algorithm used rather than assuming one forever
- periodically recalculate and compare those checksums
- keep an append-only history of verification events and their outcomes
- distinguish between a missing file, unavailable storage and actual checksum corruption
- expose integrity state clearly through the API and UI
- keep detection separate from repair, so Stashd never silently replaces damaged material and calls the problem solved

Filesystem features such as ZFS checksums and scrubs are extremely useful, but they solve a slightly different problem. Stashd's fixity records should travel **with the archive**, independently of whichever filesystem happens to contain it.

Longer term, I also want Stashd's preservation metadata to be broadly compatible with established archival practice — particularly PREMIS-style objects and events — and to support portable manifests or preservation packages when exporting a Vault.

The eventual goal is simple:

> Years after something was archived, Stashd should be able to show not only what was preserved, but that the preserved copy is still intact, when it was last checked, and what has happened to it since ingest.

### Prefer good existing tools over reinventing them

Stashd should not contain a bespoke implementation of every protocol on Earth.

Where robust libraries or tools already exist, I want to use them.

Where something genuinely reusable is missing and Stashd has to build it, I'd rather that work become a standalone open-source library than disappear into the application as a private pile of parsing code.

`yt-dlp` is very good at being `yt-dlp`. Stashd does not need to become a worse `yt-dlp`.

### Make preservation pleasant enough that people actually do it

Archival software has a tendency to assume that anyone using it secretly wanted a second career as a records administrator.

I do not.

Stashd should make the responsible thing the easy thing: create a stash, tell it what matters, and let the machinery do the boring work.

There is a lot of automation hiding behind that sentence.

## Current state

Stashd is under heavy development and the exact feature list changes fairly quickly.

The major pieces currently revolve around:

- creating and managing stashes
- ingesting media into a canonical vault
- preserving media and metadata
- subtitles and transcripts
- publishing preserved material back out through useful formats
- a web interface for managing the whole thing
- a real plugin runtime for source- and destination-specific behaviour

Some existing internals are still likely to move, disappear, or be replaced as the architecture settles down.

This is deliberate.

## Plugins

Plugins are not just a future plan: Stashd already uses them.

The core is intended to own the things that actually need to be universal — the vault, identity, persistence, orchestration, permissions, lifecycle, and the filesystem boundary — while integrations own the peculiarities of individual services and formats.

The current first-party plugins include:

### YouTube

The YouTube input understands channels, handles, playlists, individual videos, Shorts and YouTube Music URLs.

Routine discovery can use YouTube's feeds, with the Data API available when complete enumeration is needed. Actual acquisition is delegated to `yt-dlp`, and preserved material can include the media itself, metadata, thumbnails and captions.

The important architectural bit is that **YouTube behaviour belongs to the YouTube plugin**. Core shouldn't gradually become a museum of special cases for every website anyone might want to archive.

### Podcast

The Podcast plugin is currently a Broadcast: it turns material preserved in the vault back into standards-compatible podcast feeds.

It handles RSS plus the relevant iTunes, Atom and Podcasting 2.0 metadata, can publish audio or video, and can expose preserved captions as transcripts.

This is an area still receiving active polish. Producing a feed that technically validates is not the same thing as producing a *good* podcast feed.

### Jellyfin and Plex

Jellyfin and Plex are Broadcast plugins which turn suitable vault material into layouts those media servers can consume, and can interact with configured servers to discover libraries and trigger refreshes.

Again, these are views of the vault rather than competing copies of it.

### The plugin system itself

Plugins are separately packaged and versioned rather than being chunks of optional code hidden inside the main application.

Stashd has a separate plugin contract and PHP SDK for plugin authors. Plugins run behind a capability boundary: they are given the specific things they need — such as HTTP access, credentials, helper programs, staging storage or selected media — rather than unrestricted access to Stashd's database and vault.

First-party plugins are distributed as OCI artifacts, including any helper binaries they require.

That separation is intentional.

One of the long-term goals is that adding support for another source or destination should not require teaching core what that service is. Ideally, someone should be able to implement an integration against the plugin API, package it, install it, and have Stashd orchestrate it without acquiring another permanent organ.

The plugin API is still evolving, because this whole project is still evolving.

Expect breakage.

## Planned plugins

This is a wishlist, not a release roadmap. Some of these will happen sooner than others, some will change shape, and some may turn out not to be worth doing.

### Inputs

- **Podcast** — preserve existing podcast feeds, including audio, artwork, metadata, chapters and transcripts.
- **Websites** — scheduled snapshots, change detection, linked assets and eventually WARC-style archival.
- **Social media** — preserve public posts and media, especially where they relate to other items in the same stash.
- **BitTorrent / RSS** — watch feeds and archive matching releases automatically.
- **Archive import** — ingest existing archives such as WARC, ZIM and structured filesystem collections.
- **Physical media** — workflows for preserving CDs, DVDs, Blu-rays, LaserDiscs, MiniDisc, VHS and other physical formats, including disc images, captures, checksums, technical metadata and provenance.

The physical-media side is especially interesting because the source is not a URL at all. Stashd should still be able to answer the same questions: **what is this, where did it come from, how was it captured, and can we prove the preserved copy has not changed?**

### Enrichment

- **Media metadata** — enrich imported films, television and other media from sources such as TMDB or TVDB.
- **Transcription and captions** — generate or improve transcripts for preserved audio and video.
- **Relationship detection** — identify links between items, such as a blog post, social post and podcast episode belonging to the same work.

### Broadcasts

- **Internet Archive** — optionally publish suitable material to the Internet Archive.
- **ZIM** — export selected Vault content for Kiwix and offline use.
- **Static archive** — produce a portable, browsable archive that does not require a running Stashd instance.

### Storage and backup

- **Object storage** — replicate Vault data to S3-compatible storage.
- **Remote storage** — backup and replication through established filesystem and transfer protocols.

The rule is simple:

> A plugin should solve a real preservation, enrichment or access problem — not just exist because something has an API.

## What Stashd is not

At least for now, Stashd is **not** intended to be:

- a large multi-user media platform
- a replacement for Jellyfin or Plex
- a torrent index
- a general-purpose cloud storage product
- a commercial content redistribution system
- an excuse to ignore creators' wishes or copyright

It is being designed primarily as a **self-hosted, single-owner archival tool**.

That constraint is important. It means I would rather make the single-user experience excellent than accumulate infrastructure intended for hypothetical enterprise deployments.

## A note about preservation

Stashd can help you make copies of things.

That does not automatically mean you have the legal or ethical right to redistribute those copies.

My interest here is personal preservation: keeping access to material that matters to you, maintaining context that would otherwise disappear, and making your own archive durable.

Use some judgement.

**Archive responsibly.**

## Installation

Installation and upgrade documentation are still evolving along with the application.

For now, expect Docker to be the primary supported deployment method.

If you're installing Stashd today, assume that releases may contain breaking changes and that you should keep backups of anything you care about.

I very much mean that second sentence.

## Development

Stashd is currently built primarily for my own use, which has one useful consequence: features tend to come from real preservation problems rather than a SaaS feature matrix.

It also means priorities can change when I actually use something and discover that the supposedly elegant implementation is annoying.

That has happened.

It will happen again.

Contributions, experiments, bug reports, plugins, and ideas are welcome, but please keep the project's basic direction in mind:

> **Preserve the thing, preserve its context, and make the preserved copy useful.**

Everything else is machinery.

## Status

**Experimental / active development.**

Useful today. Not stable yet.

Here be migrations.
