# YouTube Input Component parity audit

Status: experimental audit on `feature/plugin-contract-generalization`.

The standalone Component now completes the normal Stash lifecycle for the
channel path. This audit records the boundary between that proof and the
broader behavior of the existing PHP YouTube provider. It is evidence for a
future migration decision, not a promise that every historical provider
feature belongs in the Input contract.

## Behavior matrix

| Area | Built-in PHP behavior | External Component | Result |
| --- | --- | --- | --- |
| Channel handles and `/channel/...` | Supported; aliases resolve to channel identity | Supported for the channel forms registered in `plugins/youtube/plugin.json` | Parity for the proven channel path |
| `/c/...` and `/user/...` | Supported | Component parses these channel references | Fixture coverage should be expanded before migration |
| Playlists | Supported | Not implemented | Gap |
| Watch URLs, `youtu.be`, Shorts | Supported as video Inputs | Not implemented as Input sources | Gap |
| Invalid channel references | Stable unsupported-source failure | Structured plugin unsupported failure | Semantically equivalent outcome, messages differ |
| RSS refresh | RSS/Atom discovery | RSS/Atom discovery in Wasm | Parity for channel fixtures |
| Data API backfill | Uploads playlist, pagination, batched video details | Same semantic flow through host grants | Parity for committed fixtures |
| Credential-free backfill | RSS fallback, then optional yt-dlp fallback | RSS fallback when the credential grant is unavailable | Intentional narrower scope |
| Upstream failure fallback | Provider strategy selection/fallback varies by failure | Plugin reports the failure; no generic retry/fallback orchestration | Gap to decide from production use |
| Core deduplication/order/filtering | Existing Stashd machinery | Same existing machinery | Parity proven in lifecycle test |
| Titles/descriptions/timestamps/duration/artwork | Returned by RSS/Data API and enrichment | Returned as generic discovered-item facts | Parity for fixture fields |
| Content classification | Includes regular, Shorts, live/premiere classification | Data API classification is returned as generic `kind` | Facts exist; Input options are not yet exposed by the adapter |
| Video acquisition | ytdlphp/yt-dlp, metadata/source sidecars | Component invokes granted yt-dlp and returns primary, metadata, artwork, captions | Primary path proven; sidecar shape differs |
| Audio-only acquisition | Format policy and MP3 output | Component owns audio format selection | Fixture path proven |
| Captions/language selection | Existing downloader/provider options | Component supports caption options, but the application adapter does not yet persist/map YouTube Input options | Gap |
| Provider-specific retry classification | Bot check, rate limit, transient unavailable, timeout | Typed plugin/helper/runtime failures, with less detailed provider classification | Gap |
| SponsorBlock | Core behavior is keyed to `providerKey === 'youtube'` | Component items currently use `youtube-component` | Transitional coupling remains |

## Input options classification

- Title filtering is core behavior and already remains in core.
- Include Shorts and include live/premiere are provider-declared options in the
  built-in provider. The Component emits the classification facts, but the
  external adapter does not yet expose or persist equivalent option metadata.
  This is a real parity gap, not a reason to add YouTube branches to core.
- Caption/subtitle selection is acquisition configuration and belongs inside
  the YouTube plugin, with a small generic acquisition-options mapping if a
  real migration requires it.
- SponsorBlock is optional enrichment/post-processing, not Input discovery.
  It should not be added to the Input contract in this milestone.

## SponsorBlock disposition

`SponsorBlockProviderEligibility` currently accepts only persisted media items
whose `providerKey` is `youtube`. The external lifecycle uses
`youtube-component`, so it does not accidentally enter the existing
SponsorBlock path. Removing the built-in implementation would therefore
require either a migration/alias for logical YouTube identity or a small
provider-capability identity change. A full enrichment plugin API is deferred.

## Progress disposition

The Component and host already report semantic stages such as resolving,
discovering, acquiring, processing, and complete. The legacy downloader
callback is typed around ytdlphp byte progress, so the external lifecycle does
not yet merge those events into the ordinary download job progress stream.
This is a generic progress-boundary debt, not a reason to expose yt-dlp fields
in WIT. The existing host event capture remains useful for the proof.

## Identity and source constraint

The experimental manifest currently uses `youtube-component` so the built-in
`youtube` provider and the external Component can coexist for parity. This is
an implementation identity, not a settled durable provider identity. Before
removal, existing `providerKey = youtube` Inputs need an explicit alias or
migration to the installed Component without creating duplicate MediaItems.

The WIT intentionally accepts a plain `source: string`; the application still
uses `StashdUri` while all current Inputs are URI-shaped. Do not push the WIT
back toward a URI-only type before a non-URI Input supplies evidence.

## Decision

The built-in implementation cannot be removed yet. The external Component is
the normal implementation for the proven channel lifecycle, but playlists,
video/Shorts Inputs, provider Input options, richer acquisition parity,
failure classification, SponsorBlock identity compatibility, and persisted
identity migration remain unresolved.
