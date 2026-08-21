# YouTube Input plugin migration record

Status: the external YouTube Component is now the implementation selected for
logical provider `youtube` on `feature/plugin-contract-generalization`.

The migration preserves durable `providerKey = youtube` values. The package
identity and version remain implementation provenance; they are not part of
Input or MediaItem identity. The former PHP provider was removed after the
fixture-backed and lifecycle checks below passed.

## Migrated behavior

- channel handles, `/channel/...`, `/c/...`, and `/user/...` references resolve
  inside the Component;
- playlist, watch, `youtu.be`, and Shorts references resolve to opaque plugin
  identities;
- `refresh` and `complete` discovery choose RSS, Data API, or a plugin-owned
  fallback based on intent and granted capabilities;
- Data API pagination, playlist membership, video detail enrichment, and
  provider-owned Shorts/live filtering remain inside the Component;
- acquisition, audio policy, artwork, metadata, captions, and caption language
  handling are implemented by the Component through its generic helper and
  staging grants;
- generic plugin failures expose outcome and retryability, while YouTube
  diagnostics remain plugin-side;
- existing `providerKey = youtube` Inputs and MediaItems are reused without a
  migration or duplicate identities.

## Deliberate differences

The old implementation's PHP strategy classes, ytdlphp wrapper types, byte-level
progress callback, and temporary filename conventions were implementation
details rather than public behavior. The Component returns generic staged
artifact roles and semantic progress; core validates and preserves those
artifacts. SponsorBlock was removed from Stashd and is not part of this
migration.

Credential-free complete discovery is limited to the fallback mechanisms the
Component can use with its currently granted capabilities. Core does not
select or retry YouTube mechanisms; the plugin owns that decision.

## Evidence

The deterministic proof is run from the Lerd development container:

```bash
./scripts/youtube-input-spike.sh
```

The relevant application tests also exercise existing logical YouTube rows,
deduplication, options, acquisition, staging, Vault ingest, and failure
recovery. PostgreSQL is used for the durable identity/lifecycle proof.

This is still experimental plugin architecture, not a stable Plugin API v1.
The next bounded plugin milestone is Podcast as the first external Broadcast
plugin; it must drive its own contract rather than copying YouTube concepts.
