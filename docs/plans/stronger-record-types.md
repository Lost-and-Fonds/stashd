# Stronger Record Types Prompts

> **Stale-risk note (2026-07-03):** property names in this document predate the Tempest-native
> records slice (`cb5d4b1`, PR #3), which removed the `*Json` suffix everywhere
> (`$optionsJson` → `$options`, etc.) and retyped the polymorphic JSON columns as plain
> `array<string, mixed>` via Tempest's array↔JSON casting — the "do not convert to value objects"
> guidance below still stands, but the raw-`?string`-column premise no longer does. See the
> "Tempest-native records" entry in `docs/TODO.md`.

These prompts capture planned follow-up work for moving stashd records away from weak stringly typed fields and toward Tempest-native model property types.

## Timestamp Properties

Use this prompt for the first timestamp conversion slice.

```text
In /home/hazel/Projects/stashd, implement stronger typing for timestamp model properties using Tempest's first-party DateTime support.

Read AGENTS.md first. Use lerd MCP for PHP/composer/vendor commands. Do not run host PHP/composer/vendor binaries.

Goal:
Convert record timestamp properties from `string` / `?string` to `Tempest\DateTime\DateTime` / `?Tempest\DateTime\DateTime`, using Tempest's mapper/database DateTime caster and serializer.

Use first-party Tempest patterns:
- `Tempest\DateTime\DateTime`
- `Tempest\DateTime\Timezone`
- `Tempest\DateTime\FormatPattern`
- `Tempest\Validation\Rules\HasDateTimeFormat` if needed for SQL datetime columns
- Tempest DateTime methods such as `isBefore()`, `isAfter()`, `isBeforeOrAt()`, `isAfterOrAt()`, `plusSeconds()`, `minusSeconds()`, `format()`, and `toRfc3339()`

Important decisions:
- There are no existing users. Prefer correctness over API backwards compatibility.
- API/resource output may change if that is the more correct representation, but keep it deliberate and tested.
- Podcast feeds must still emit standards-compliant podcast/RSS date strings.
- Replace string/date hacks like `strtotime()`, `substr($date, 0, 10)`, and `gmdate()` arithmetic with Tempest DateTime methods.
- Update tests where the stronger type changes expectations.

Implementation scope:
1. First inspect Tempest DateTime database hydration/serialization behavior against Stashd's current camelCase PostgreSQL datetime columns.
2. Add or update a focused mapping/database test proving:
   - SQL datetime values hydrate to `Tempest\DateTime\DateTime`,
   - `Tempest\DateTime\DateTime` persists back to the current SQL datetime format,
   - `#[HasDateTimeFormat]` is used where required.
3. Remove `App\Support\RecordTimestamps` if the conversion makes it obsolete.
4. Do not replace `RecordTimestamps` with a new generic timestamp helper unless there is still real repeated behavior after conversion. Prefer explicit `DateTime::now(Timezone::UTC)` at lifecycle/event boundaries and Tempest's first-party DateTime serialization for persistence.
5. If keeping any shared helper, justify why it is still needed and keep it specific.
6. Convert timestamp properties in records as a coherent first slice. If full conversion is too broad, start with the lowest-risk cluster and leave a clear follow-up list.
7. Adjust all direct callers of converted properties:
   - `AuthService` expiry checks should compare DateTime objects, not use `strtotime()`.
   - `BroadcastNfoBuilder` should format the date with Tempest DateTime, not `substr()`.
   - job stale checks should build the stale cutoff with Tempest DateTime methods.
   - scheduler next-check calculations should use `plusSeconds()` or Tempest duration support.
   - podcast feed generation should format RSS/podcast dates from DateTime objects.
8. Update repositories, handlers, resources, and tests to stop assigning raw timestamp strings to converted properties.
9. Keep schema and column names unchanged.

Checks:
- Run focused tests for the changed records/callers.
- Run the relevant broader Pest suite if the slice touches auth, jobs, broadcasts, or podcasts.
```

Straightforward properties after the shared pattern exists:

- Plain `createdAt` / `updatedAt` fields that are only assigned through current `RecordTimestamps::apply()` usage.
- Created-only event rows such as activity/events, provided their resources are updated deliberately.

Complex timestamp properties to handle deliberately:

- `expiresAt`, because auth expiry should use Tempest date comparisons.
- `heartbeatAt`, because stale job queries compare against a cutoff.
- `nextCheckAt`, because scheduler logic currently does timestamp arithmetic with `gmdate()`.
- `publishedAt`, because NFO, downloads, provider metadata, and podcast feeds all consume it.
- Podcast episode dates, because output must remain RSS/podcast compliant even if internals become typed.

## Stable JSON Properties

Use this prompt for the first stable embedded JSON conversion slice.

```text
In /home/hazel/Projects/stashd, replace stable JSON string record properties with Tempest-native embedded value objects.

Read AGENTS.md first. Use lerd MCP for PHP/composer/vendor commands. Do not run host PHP/composer/vendor binaries.

Goal:
Use Tempest's first-party `#[SerializeAs]` model-property pattern to replace selected stable `*Json` string properties with typed embedded value objects.

Important principles:
- This slice is only for stable JSON whose shape represents a real domain concept.
- Prefer correct end-state model properties over temporary bridge code.
- Do not keep parallel `fooJson` and `foo` properties unless Tempest strictly requires a migration step.
- Avoid generic `JsonObject`, `JsonPayload`, or broad `Settings` buckets.
- Avoid DTO explosion: create a value object only when it owns behavior or a stable domain meaning.
- Do not convert polymorphic command/job/event payloads in this slice.
- Do not convert raw provider snapshots in this slice.

First preferred target:
- `ApiTokenRecord::$scopesJson` -> a typed value object such as `ApiTokenScopes`.

Secondary target if the first target is clean:
- `MediaServerConnectionRecord::$settingsJson` -> a typed value object only if inspection confirms the current shape is stable. Prefer a domain name like `MediaServerLibrarySelection` if the JSON only stores library selection fields.

Implementation requirements:
1. Inspect Tempest's `#[SerializeAs]` behavior for database model properties and existing vendor casters.
2. Add focused tests proving the value object persists to and hydrates from the existing JSON column.
3. Replace the record property rather than adding permanent bridge properties.
4. Update repositories/services/resources to stop manual `json_encode()` / `json_decode()` for converted fields.
5. Put validation and normalization in the value object where it belongs.
6. Keep public API output explicit through Resource classes.
7. Keep schema and column names unchanged unless a rename is clearly necessary and approved.

Checks:
- Run focused tests for auth/API token behavior.
- If converting media-server settings too, run focused media-server feature tests.
```

Straightforward stable JSON candidates:

- `ApiTokenRecord::$scopesJson`, because token scopes are an auth domain concept and should own normalization/deduplication.

Medium-complexity stable JSON candidates:

- `MediaServerConnectionRecord::$settingsJson`, if inspection confirms it only stores selected library fields. Prefer a name like `MediaServerLibrarySelection` over a vague settings bucket.
- `BroadcastTriggerRecord::$settingsJson`, if inspection confirms trigger settings are stable for the current trigger type.

Defer:

- `CommandRecord::$optionsJson`, `CommandRecord::$resultJson`, and `JobRecord::$payloadJson`, because they are polymorphic by command/job type.
- `ActivityEventRecord::$metadataJson` and `EventNotificationRecord::$payloadJson`, because they are event-shaped and semi-polymorphic.
- `RawMetadataSnapshotRecord::$rawJson`, because it intentionally preserves opaque provider data.
- `SecretRecord::$metadataJson`, unless a concrete secret metadata concept emerges and the security impact is reviewed.

## Entity Identity References

Use this prompt to strengthen entity identity handling without mechanically replacing every ID-like string.

```text
In /home/hazel/Projects/stashd, strengthen entity identity handling across records, repositories, and services.

Read AGENTS.md first. Use lerd MCP for PHP/composer/vendor commands. Do not run host PHP/composer/vendor binaries.

Goal:
Replace raw string/generic `PrefixedUlid` entity references with a correct end-product identity model:
- pass full records when the operation already has and needs the entity,
- use specific typed ID value objects at boundaries and repository lookup points,
- keep raw strings only at HTTP/API/database/serialization boundaries.

Important design rules:
- Do not mechanically convert every `fooId: string` to a typed ID without inspecting call flow.
- If the caller already has the target record loaded and the callee needs entity data, refactor the callee to accept the record.
- If the value crosses a boundary, is persisted in commands/jobs/events, or is used for repository lookup, use a specific typed ID object.
- Stop using generic `PrefixedUlid` in places where the entity type is known.
- Keep Tempest `PrimaryKey $id` on records unless there is strong evidence Tempest supports a better first-party custom primary-key model.
- Do not introduce Tempest relationship attributes in this slice unless they are clearly needed for the identity refactor.
- Do not rename `*Record` classes in this slice.

Suggested design:
1. Introduce a shared identity abstraction, for example `App\Support\Ids\PrefixedId`.
2. Add concrete ID value objects for known entity prefixes, for example:
   - `UserId`
   - `ApiTokenId`
   - `CommandId`
   - `JobId`
   - `StashId`
   - `StashInputId`
   - `StashItemId`
   - `MediaItemId`
   - `MediaItemSourceId`
   - `AssetId`
   - `BroadcastId`
   - `BroadcastItemId`
   - `BroadcastTriggerId`
   - `BroadcastTriggerRunId`
   - `SecretId`
   - `StorageLocationId`
   - `StorageCheckId`
   - `MediaServerConnectionId`
   - `ActivityEventId`
   - `EventNotificationId`
3. Keep each concrete ID class minimal. It should declare/validate its prefix and inherit parsing/string/PrimaryKey conversion behavior from the shared abstraction.
4. Add Tempest mapper caster/serializer support for the shared ID abstraction if record properties will store concrete ID types.
5. Update or replace `PrefixedUlidGenerator` so callers can generate concrete typed IDs, preferably via class-string input:
   - `generate(StashId::class)`
   - `generate(MediaItemId::class)`
6. Preserve existing database column names and values.

Audit and refactor process:
1. Inventory all raw entity reference strings in `*Record` classes and repository/service method signatures.
2. For each reference, classify usage:
   - record should be passed instead,
   - typed ID should be used,
   - raw string is acceptable boundary data.
3. Convert records' FK/reference properties to concrete typed IDs where they remain persisted references.
4. Refactor services to accept records instead of IDs where the caller already has the record and the service uses entity data.
5. Refactor repository methods to accept concrete typed IDs for lookups and list queries.
6. Refactor controllers/commands/jobs to parse boundary strings into concrete typed IDs.
7. Refactor command/job payload construction so payload arrays still serialize strings, but are produced from typed IDs.
8. Update Resource classes to emit string IDs explicitly.
9. Remove or sharply reduce generic `PrefixedUlid` usage. Keep it only if it still has a clear boundary role.
10. Add focused tests for ID parsing, prefix validation, database hydration/persistence, repository lookups, and representative controller/job flows.

Implementation guidance:
- Prefer doing the full coherent identity refactor over leaving permanent bridge code.
- If the full refactor is too large for one pass, split by domain boundary, not by one isolated property:
  - Auth
  - Stashes/Vault
  - Broadcasts
  - Jobs/Commands
  - System/Storage/Activity
- Each completed domain slice should leave no mixed raw/generic ID style inside that domain except boundary serialization.

Checks:
- Run unit tests for the ID value objects and mapper/caster behavior.
- Run feature tests for every converted domain slice.
- Run broader Pest suites if commands/jobs/broadcasts/auth are touched.
```

## URL And Filesystem Path Values

Use this prompt to separate URL handling from filesystem path handling and move both toward domain-specific value objects.

```text
In /home/hazel/Projects/stashd, strengthen URL and filesystem path handling with domain-specific value objects.

Read AGENTS.md first. Use lerd MCP for PHP/composer/vendor commands. Do not run host PHP/composer/vendor binaries.

Goal:
Separate URLs from filesystem paths and replace weak string handling with domain value objects:
- rename/reframe `StashdUri` as `StashdUrl`,
- use Tempest's `Tempest\Support\Uri\Uri` internally for URL parsing/manipulation,
- move `fake://` support out of production URL handling and into fake/test provider URL classes,
- introduce provider-specific URL classes where they normalize provider intent,
- introduce filesystem path value objects separately from URLs.

URL design rules:
- `StashdUrl` is the app-level validated URL wrapper. It should not represent filesystem paths.
- Production `StashdUrl` should accept real URL schemes only, likely `http` and `https`.
- `fake://` exists only for fake provider/test support. Do not allow it globally unless the fake provider is explicitly registered.
- Keep raw URL strings only at HTTP/API/database/external-process boundaries.
- Records should generally store/hydrate broad app-level URLs, not provider-specific URL subclasses.
- Provider code should marshal broad URLs into provider-specific URL classes when it needs provider intent.
- Public/API resources should emit strings explicitly.

Provider-specific URL design:
- Add provider URL classes only where they normalize real provider complexity.
- YouTube is the first strong candidate.
- Prefer explicit classes such as:
  - `YouTubeChannelUrl`
  - `YouTubeVideoUrl`
  - `YouTubePlaylistUrl`
- Each provider URL class should expose:
  - `accepts(StashdUrl|string): bool`
  - `create(StashdUrl|string): self`
  - a normalized/canonical URL method where meaningful
  - normalized provider intent fields, such as channel ID, handle, custom name, video ID, or playlist ID
- `YouTubeChannelUrl::create()` should accept channel-like YouTube forms such as `/channel/...`, `/@handle`, `/c/...`, and `/user/...`, but must not pretend every form already has a channel ID.
- Introduce a central URL marshaller/registry that can resolve an unknown string/app URL to the most specific provider URL class.
- Keep the marshaller explicitly registered for now unless Tempest custom discovery is implemented in a separate review.

Fake provider URL design:
- Introduce fake provider URL classes such as `FakeChannelUrl`, `FakePlaylistUrl`, or `FakeItemUrl` only for fake provider/test contexts.
- Fake URLs should be accepted because the fake provider is registered, not because the global app URL type accepts `fake://`.
- Update tests to use fake provider URL classes or fake provider registry setup deliberately.

Suggested record conversions:
- `StashInputRecord::$sourceUri` -> app URL type, renamed to `sourceUrl`
- `MediaItemRecord::$canonicalUri` -> app URL type, renamed to `canonicalUrl`
- `MediaItemRecord::$thumbnailUri` -> app URL type, renamed to `thumbnailUrl`
- `MediaItemSourceRecord::$discoveredUri` -> app URL type, renamed to `discoveredUrl`
- `StashRecord::$iconUri` -> app URL type, renamed to `iconUrl`, if inspection confirms it is a URL

Service endpoint URL:
- `MediaServerConnectionRecord::$baseUri` is not a provider/source URL.
- Introduce `MediaServerBaseUrl` or similar, wrapping Tempest `Uri`, allowing only safe endpoint schemes.
- Rename `baseUri` to `baseUrl`.
- Normalize trailing slashes inside the value object.
- Reject credentials/token-bearing endpoint URLs unless there is a deliberate reason to allow them.
- Update Jellyfin/Plex clients to build endpoint URLs from the typed base URL.

Filesystem path design:
- Do not use URL/URI classes for filesystem paths.
- Do not introduce one vague `Path` wrapper for everything.
- Path value objects must not assume:
  - POSIX-only separators,
  - case sensitivity,
  - file/directory existence,
  - `realpath()` availability,
  - hardlink/symlink support,
  - same-device moves,
  - Unicode normalization consistency,
  - local dev filesystem behavior matching TrueNAS/ZFS or other deployment filesystems.
- Constructors should validate lexical safety and domain/root containment where possible, but filesystem reality belongs in probes/checkers.
- Preserve stashd's storage rule: database stores expected state, filesystem is verified reality.

Path candidates:
- `StorageLocationRecord::$path`
- `AssetRecord::$path`
- `AssetRecord::$relativePath`
- `BroadcastItemRecord::$publishedPath`
- planned broadcast file/sidecar `sourcePath`, `relativePath`, `absolutePath`
- `DownloadedFile::$tempPath`
- `DownloadRequest::$tempDirectory`

Path implementation guidance:
- Inspect `PathSanitizer`, `VaultPathBuilder`, `BroadcastPathBuilder`, storage checks, downloads, and publication paths first.
- Prefer domain-specific path concepts such as storage root path, vault absolute/relative path, broadcast absolute/relative path, and temp download path.
- Make path builders return path value objects where practical.
- Keep filesystem calls explicit at the boundary via `->toString()` or similarly clear methods.
- Add tests for traversal prevention, root containment, case-sensitivity assumptions, missing files, and non-existing paths.

Schema and naming guidance:
- There are no existing users. Prefer correct naming over backwards compatibility.
- Rename database columns from `*Uri` to `*Url` where the domain term is URL.
- Include migrations and update schema tests when columns are renamed.
- Keep filesystem path columns named as paths, not URLs.

Implementation guidance:
- Prefer coherent concept slices over one-property bridge work:
  - app URL rename and caster/serializer,
  - YouTube URL classes and marshaller,
  - fake provider URL isolation,
  - media-server base URL,
  - vault/broadcast/storage path value objects.
- Do not mix this with `*Record` class renaming.
- Do not implement Tempest custom discovery in this slice; leave that for the later discovery review unless it becomes clearly necessary.

Checks:
- Run URL value object and provider URL parser tests.
- Run fake provider tests to prove fake URLs are isolated to fake/test contexts.
- Run provider/stash/vault/broadcast tests for converted URL fields.
- Run path sanitizer/storage/vault/broadcast tests for converted path fields.
- Run podcast/feed route tests if asset or broadcast paths are touched.
```

## Sensitive Record Properties

Use this prompt to make accidental security leaks harder when records are handled directly.

```text
In /home/hazel/Projects/stashd, add security guardrails around sensitive record properties using Tempest first-party visibility annotations.

Read AGENTS.md first. Use lerd MCP for PHP/composer/vendor commands. Do not run host PHP/composer/vendor binaries.

Goal:
Use Tempest's `#[Hidden]` model/mapper annotation to make accidental security leaks harder when developers or agents work with records directly.

Intent:
`#[Hidden]` is a guardrail against accidental leaks, not a replacement for explicit API resources, secret-safe logging, or security review.

Important principles:
- Prefer explicit public Resource DTOs for API output.
- Do not auto-serialize records directly to public responses.
- Do not replace stashd's `SecretsService` encryption with Tempest `#[Encrypted]` in this slice.
- Do not change token/session behavior unless required by hiding fields.
- If a hidden field must be loaded for internal use, make that opt-in explicit and test it.
- Keep raw secret values, hashes, ciphertext, nonces, and secret FK references out of accidental serialization/logging.

First inspect:
1. Tempest docs/vendor behavior for `#[Hidden]`.
2. Whether hidden properties are excluded from SELECT, serialization, or both.
3. The correct first-party way to explicitly include hidden properties when needed.

Strong `#[Hidden]` candidates:
- `UserRecord::$passwordHash`
- `ApiTokenRecord::$tokenHash`
- `SecretRecord::$encryptedValue`
- `SecretRecord::$nonce`
- `SecretRecord::$metadataJson` if metadata may contain sensitive context
- `ProviderAccountRecord::$secretId`
- `MediaServerConnectionRecord::$tokenSecretId`
- `BroadcastRecord::$tokenSecretId`
- `BroadcastItemRecord::$tokenSecretId`

Review before hiding:
- `ApiTokenRecord::$tokenPreview`
- `BroadcastRecord::$tokenPreview`
- `BroadcastItemRecord::$tokenPreview`

These previews are intentionally safe for authenticated display, but still security-adjacent. Decide whether hiding by default improves safety without creating noisy internal opt-ins.

Implementation requirements:
1. Apply `#[Hidden]` to agreed sensitive record properties.
2. Update repositories/services that require hidden fields to explicitly include/load them.
3. Keep resources explicit and verify they do not expose newly hidden fields unintentionally.
4. Add tests proving:
   - auth login still works,
   - API token lookup/revocation still works,
   - secret decrypt/read/write still works,
   - media server token resolution still works,
   - podcast broadcast/item token resolution still works,
   - generic serialization or accidental record mapping omits hidden fields.
5. Add or update tests around resource output to ensure ciphertext, nonce, password hashes, token hashes, and token secret IDs are absent.
6. If adding small value objects such as `TokenPreview` or `SecretKey`, keep them narrowly scoped and do not let that expand into a larger secret storage refactor.

Checks:
- Run focused auth, secret, media-server, and podcast token tests.
- Run API resource serialization tests.
- Run broader feature tests if hidden-field loading changes shared repository behavior.
```

## Later Encryption Annotation Review

Investigate Tempest's `#[Encrypted]` annotation as a future security/storage simplification, but do not combine it with the `#[Hidden]` guardrail slice.

Questions for that review:

- Does `#[Encrypted]` provide enough control over key management, nonce handling, rotation, authenticated encryption, and error reporting for stashd secrets?
- Can it preserve current `SecretsService` responsibilities such as typed secret records, redaction, revocation, metadata, and secret-safe errors?
- Would adopting it reduce code and risk, or hide security behavior that should remain explicit?
- What migration is required for existing ciphertext/nonce columns?
- Would Tempest encryption change how raw tokens, provider credentials, and media-server tokens are recovered for authenticated display/use?

## Semantic Scalar Values

Use this prompt to strengthen small numeric and bounded string values where a type prevents unit confusion or vague state.

```text
In /home/hazel/Projects/stashd, strengthen small semantic scalar fields with enums and value objects.

Read AGENTS.md first. Use lerd MCP for PHP/composer/vendor commands. Do not run host PHP/composer/vendor binaries.

Goal:
Replace ambiguous scalar fields with domain-specific types where the type prevents unit confusion, bounds errors, or vague state.

Use first-party Tempest types where appropriate:
- Prefer `Tempest\DateTime\Duration` for duration-like values if database mapping/serialization is clean.
- Prefer PHP enums for bounded string values.
- Add small stashd value objects only when Tempest/PHP first-party types are not enough.

Important principles:
- Be selective. Do not wrap every int/string just because it exists.
- A value object should encode units, bounds, normalization, or behavior.
- Avoid class explosion for values that are only display labels.
- Preserve schema names unless a better name is clearly warranted and approved.
- Keep public Resource output explicit and understandable.
- Verify Tempest mapper/database support before changing record property types.

Strong candidates:
- `StorageLocationRecord::$role` -> enum, e.g. `StorageLocationRole`
- `MediaItemRecord::$contentType` -> enum/value object, if the set is bounded enough
- `MediaItemRecord::$durationSeconds` -> `Duration`
- `AssetRecord::$durationSeconds` -> `Duration`
- `JobRecord::$progressEtaSeconds` -> `Duration`
- `AssetRecord::$sizeBytes` -> `ByteSize`
- `StorageLocationRecord::$freeBytes` -> `ByteSize`
- `StorageLocationRecord::$totalBytes` -> `ByteSize`
- `JobRecord::$progressPercent` -> `ProgressPercent`
- `AssetRecord::$mimeType` -> `MimeType`
- `AssetRecord::$language` -> `LanguageTag`

Review carefully before converting:
- `JobRecord::$progressRate`, because the unit must be clarified first.
- `AssetRecord::$container`, `$videoCodec`, `$audioCodec`, because codec/container taxonomy can get broad and provider-specific.
- `ActivityEventRecord::$type` and `EventNotificationRecord::$eventType`, because event names may intentionally remain open strings until the event taxonomy stabilizes.
- `providerKey`, `providerItemId`, `providerInputId`, and `creatorProviderId`, because provider identity should probably be handled with the provider URL/reference model.

Implementation requirements:
1. Inspect Tempest support for `Duration` database hydration/serialization.
2. Add focused mapper/database tests for any first-party or custom value object used in records.
3. Convert fields by coherent concept, not one-off wrappers:
   - durations,
   - byte sizes,
   - progress,
   - media descriptors,
   - storage roles.
4. Update repositories/services/resources/tests to stop manually assuming units in property names.
5. Prefer renaming PHP properties away from unit suffixes when the type carries the unit:
   - `durationSeconds` -> `duration`
   - `sizeBytes` -> `size`
   - `freeBytes` -> `free`
   - `totalBytes` -> `total`
   - `progressEtaSeconds` -> `progressEta`
6. If database column renames are appropriate in alpha, include migrations and schema tests. If column names remain unit-suffixed for storage clarity, document the reason.
7. Ensure API output remains clear by serializing value objects to explicit scalar fields, such as `durationSeconds`, `sizeBytes`, or `progressPercent`, unless a richer response shape is intentionally chosen.

Checks:
- Run unit tests for each value object.
- Run mapper/database tests for record persistence/hydration.
- Run feature tests covering vault assets, jobs/progress, storage health/checks, and media item resources.
```

## Later Naming Review

After stronger typing work, audit all `*Record` class names.

Decide whether `Record` should remain the persistence marker in feature-first namespaces, or whether selected records should become domain model names without the suffix. Do not mix this naming change into type-conversion prompts.

## Later Discovery Review

Investigate whether stashd should use Tempest's custom discovery mechanism for its own registries and capability lists.

Tempest supports application-level discovery by implementing `Tempest\Discovery\Discovery` and using `Tempest\Discovery\IsDiscovery`. A custom discovery class inspects classes in `discover()`, stores matches in `discoveryItems`, and registers them in `apply()`. See Tempest's discovery docs: <https://tempestphp.com/3.x/essentials/discovery#implementing-your-own-discovery>.

Candidate areas to evaluate:

- Provider registrations and provider-specific URL parsers.
- Command handler registrations.
- Job handler registrations.
- Broadcast format registrations.
- Media-server client registrations.
- Mapper/caster/serializer support classes introduced by stronger record typing.

Questions for the review:

- Which current initializers exist only to manually collect implementations?
- Would discovery make those registries simpler without hiding important runtime configuration?
- How should fake/test-only implementations be excluded from production discovery?
- Does discovery cache generation need deployment/runtime changes for stashd's Docker/RoadRunner setup?
- Are explicit registrations clearer for security-sensitive capability lists such as auth, secrets, public podcast routes, or provider credentials?
