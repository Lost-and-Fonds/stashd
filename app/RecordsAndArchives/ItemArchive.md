# Item archive and revisions, format 1

`ItemArchive` is a Tempest-autowired production service. It uses `Vault` for
canonical OCFL writes and reads; it needs no database, catalogue, cache or
process-local identity map. The Vault must already be initialized.

An **Item** is a logical identity. An **Item revision** is an immutable historical
snapshot. The **Item archive** is the production service that commits and reads
those revisions. The versioned `item.json` document is the archival record.

```php
$items = $container->get(\App\RecordsAndArchives\ItemArchive::class);
$first = $items->commitRevision('urn:example:book:123', ['title' => 'Original']);
$second = $items->commitRevision('urn:example:book:123', ['title' => 'Revised']);
$original = $items->read(\League\Uri\Urn::fromString($first->id()), $first->head());
$latest = $items->read(\League\Uri\Urn::fromString($first->id()));
```

## Identity policy: exact-uri-v1

The caller supplies an already canonical absolute ASCII URI. Relative references,
whitespace, controls, raw Unicode/IRIs, forbidden URI characters, malformed percent
escapes and URIs rejected by League URI are rejected before any Vault write.
Non-HTTP schemes are supported. Core does **no normalization**: no trimming,
case folding, percent decoding, fragment removal, port removal, query sorting or
path rewriting. League URI is used for validation only; its serialization is
never used for identity. URI-semantic canonicalization belongs to the producer.
Different canonical strings remain different Items, including equivalent-looking
URLs. Changing a canonical string does not rename or merge its existing Item.

**Open integration decision:** before relying on this policy across producers,
confirm where producer canonicalization is enforced and how independent sources
agree on a canonical URI for the same Item. This service validates URI syntax;
it cannot establish that cross-source semantic agreement. Keep that integration
gate explicit when adding producers.

Reproduce the ID without Stashd:

1. Take the exact supplied URI bytes (ASCII).
2. Compute RFC 4122 UUIDv5 with the standard URL namespace UUID
   `6ba7b811-9dad-11d1-80b4-00c04fd430c8` and name
   `stashd:item:exact-uri-v1:` followed by those bytes.
3. Format as `urn:stashd:item:<lowercase-hyphenated-uuid>`.

Example: `urn:example:book:metadata-only` produces
`urn:stashd:item:af8b11f8-60a7-5da8-b6d4-11d6ab23e2d5`.
The algorithm is deterministic across Vaults; the Vault identity remains separate.
The stored URI is checked against the derived Object ID before reading or revising
a committed Item, so a mismatched record is not silently adopted.

## Durable record and revisions

Each Item has its own peer OCFL Object. Its versioned logical `item.json` contains:

- `format: stashd-item`, `version: 1` (the record schema version).
- `identity_policy: exact-uri-v1`, `object_id`, and the full original `uri`.
- `metadata`: the supplied complete metadata snapshot as JSON values.
- `pins`: zero or more exact `{object_id, version}` Asset references; `[]` is metadata-only.

Each changed `commitRevision()` snapshot creates an OCFL archival revision.
An identical full snapshot raises the OCFL library's no-staged-changes error. This is archive commit order, not source chronology. OCFL
inventory versions retain commit timestamps; the service does not invent source
observation times or provenance. Metadata must be JSON-compatible arrays/scalars/
null: PHP objects, resources, recursion, invalid UTF-8 and nonfinite numbers are
rejected. Float values such as `1.0` retain their JSON number representation.
This snapshot is not the future source-scoped metadata facet/reconciliation API;
it does not implement field precedence, metadata stream identity or source claims.

An omitted read version resolves the committed head once; an explicit version
must exist. It never falls back to latest. Self-describing records and inventories
allow a future catalogue rebuild to recover original URIs without reversing hashes
or consulting a database. No catalogue rebuild is implemented here.

## Write safety and limits

Validation and JSON serialization happen before writes. Existing records are
checked before revision. The service delegates publication to the existing Vault
write lock and OCFL implementation; earlier version inventories and payloads are
retained. Storage errors propagate, rather than becoming expected domain rejections.

The existence check is **not atomic with first creation**. Concurrent first
creators can both observe absence; `Vault::createObject()` reopens under its
Object write lock and rejects the loser if history has since committed. It never
turns that stale create into an overwrite. The caller may retry the complete
`commitRevision()` call. Subsequent full snapshots are serialized by the Vault lock, with no
optimistic concurrency/precondition API. Callers must not use raw Vault writes to
bypass Item record validation. This is the current single-host flock guarantee,
not a distributed commit coordinator or a promise of power-loss durability.

## Verified exact Asset references

`ItemIdentity` holds the existing `exact-uri-v1` derivation unchanged. Both
services depend on it; `ItemArchive` can call `AssetArchive::inspect()` without
a circular dependency. `ItemArchive::objectIdForUri()` remains available.
Neither identity algorithm nor the version-1 persisted record schema changed.

Pins must be a list of records containing exactly two string fields:
`object_id` (a canonical lowercase `urn:stashd:asset:<uuid>`) and `version`
(`v1`, `v2`, etc., with no leading zeros or aliases). Duplicate Object/version
pairs are rejected. List order is preserved; there is no inferred latest version.

Before writing, every exact target is opened from the canonical Vault and
verified through production `AssetArchive::inspect()`: identity policy, logical
file set, accepted size/SHA-512 evidence and OCFL inventory state. Its persisted
Item URI **and** Item Object ID must match the proposed Item. Missing,
incompatible, corrupt and foreign references reject the whole Item revision.
The driver only passes arguments and translates the production domain rejection.

Item reads verify `item.json` against its exact OCFL version's digest and return
its stored pins. Reading a historical Item does not replace its pins or scrub all
referenced Assets; use Asset inspect/export to verify and retrieve those exact
versions. This keeps the Item's historical record readable if an Asset later
becomes unavailable or corrupt. Writes verify every proposed pin again.

Each commit supplies the complete metadata and pin snapshot. Omitting pins means
`[]`; it does not inherit the previous Item's pins. Older Item records retain their
own metadata and relationships. Asset preservation never advances Item history.

**Asset and Item commits are separate.** Preserve Assets first, then explicitly
commit the Item. If a later Item commit fails, successfully committed Asset
versions remain valid, potentially unlinked archival material. There is no
rollback of those Assets, invented Item relationship, combined success receipt
or automatic reconciliation. A future preservation commit journal must reconcile
this possibility. Local Object locks and their first-creation race described
above remain unchanged; no cross-Object atomicity or crash recovery is claimed.
Pin verification assumes committed versions are immutable and the Vault is not
externally modified during operations.

## Runnable connected CLI demonstration

From Core on the host, run `lerd shell`. Then run this inside Lerd:

```sh
mkdir -p "$PWD/tmp"
DEMO=$(mktemp -d "$PWD/tmp/item-pins-XXXXXXXX")
export VAULT_ROOT="$DEMO/vault"
URI=urn:stashd:demo:pinned-item
php tempest vault:init
php tempest item:commit "$URI" '{"title":"Known"}' > "$DEMO/item-v1.json"

printf 'original\000archived bytes\377\n' > "$DEMO/source.bin"
sha512sum "$DEMO/source.bin" > "$DEMO/original.sha512"
php tempest asset:import "$URI" master file.bin "$DEMO/source.bin" > "$DEMO/asset-v1.json"
ASSET_ID=$(php -r '$r=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR);echo $r["object_id"];' "$DEMO/asset-v1.json")
ITEM_ID=$(php -r '$r=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR);echo $r["object_id"];' "$DEMO/item-v1.json")
php tempest item:commit "$URI" '{"title":"Original"}' \
  "[{\"object_id\":\"$ASSET_ID\",\"version\":\"v1\"}]"

printf 'revised\000archived bytes\376\n' > "$DEMO/source.bin"
sha512sum "$DEMO/source.bin" > "$DEMO/revised.sha512"
php tempest asset:import "$URI" master file.bin "$DEMO/source.bin"
php tempest item:commit "$URI" '{"title":"Revised"}' \
  "[{\"object_id\":\"$ASSET_ID\",\"version\":\"v2\"}]"
rm "$DEMO/source.bin"

# Every command starts a fresh Tempest application and opens only Vault records.
for V in v2 v3; do
  php tempest item:inspect "$ITEM_ID" "$V" > "$DEMO/item-$V.json"
  PIN_ID=$(php -r '$r=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR);echo $r["pins"][0]["object_id"];' "$DEMO/item-$V.json")
  PIN_VERSION=$(php -r '$r=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR);echo $r["pins"][0]["version"];' "$DEMO/item-$V.json")
  php tempest asset:export "$PIN_ID" "$PIN_VERSION" file.bin "$DEMO/from-item-$V.bin"
done
cat "$DEMO/item-v2.json" "$DEMO/item-v3.json"
cat "$DEMO/original.sha512" "$DEMO/revised.sha512"
sha512sum "$DEMO/from-item-v2.bin" "$DEMO/from-item-v3.bin"
```

To leave and restart the Lerd shell, save `DEMO` and `ITEM_ID`, run `exit`, then
`lerd shell`, restore those variables and `export VAULT_ROOT="$DEMO/vault"`.
The inspection/export loop also works after this restart. Export destinations
must be new files; choose new names when repeating it.

`item:commit <uri> [metadata='{}'] [pins='[]']` accepts JSON metadata and a JSON
pin list, so multiple pins use the same list representation as the service.
`item:inspect <object-id> [version]` defaults to the Item head, while a specified
version must exist. Example commit output:

```json
{"object_id":"urn:stashd:item:c480d6f7-a3f9-508f-8e95-83b40bf5bb83","version":"v2"}
```

Historical inspection includes `pins` and a CLI-only `ocfl_version` string.
The existing `version: 1` remains the record schema version. The CLI does not
persist `ocfl_version` into `item.json`.

## Evidence

`ItemArchiveTest` exercises the production service through the thin acceptance
adapter, with a fresh Tempest container on every call and real local OCFL storage.
It independently reads inventory sidecars, version states, historical inventory
bytes, physical payloads and payload digests. It also checks identity distinctions,
a standard-library-derived UUID test vector, independent Vaults, exact-version
reads and rejection safety. Disabling the production revision write makes its
history proof fail (`v1` returned instead of `v2`); restoring the write passes.
This is independent archival evidence, not full external OCFL conformance,
crash-recovery or remote-storage certification.

The connected proof creates Item v1, pins Asset v1 in Item v2, pins different
Asset v2 bytes in Item v3, deletes the source and follows both historical pins
through separate Tempest CLI invocations. Deliberately discarding pins at commit
made this CLI proof fail on the stored historical relationship; restoring the
implementation passes. No internal services or persistence are mocked.

### Connected slice verification (2026-10-21)

- Focused baseline: 44 passed, 3 incomplete. New history proofs failed before
  implementation; all three explicit pinning markers have now been removed.
- `./bin/test.sh tests/Acceptance/RecordsAndArchives`: **125 passed,
  33 explicitly incomplete, 612 assertions, no failures**. Remaining contracts
  cover provenance, scope, recovery and other deferred work.
- Changed PHP files pass focused Mago lint/format checks. `ItemArchive`,
  `ItemIdentity`, commands and `ItemArchiveTest` pass focused static analysis.
  Repository-wide QA was not repeated for this slice.
- Actual Lerd shell was exited and reopened; fresh CLI invocations reconstructed
  Item v2 → Asset v1 and Item v3 → Asset v2 after source deletion. Python read
  physical OCFL inventories and payloads independently, verifying every digest
  and inventory sidecar for all three Item and both Asset versions.

Manual Item ID: `urn:stashd:item:c480d6f7-a3f9-508f-8e95-83b40bf5bb83`.
Asset ID: `urn:stashd:asset:28bd64fa-f7bc-582c-a665-f9ddff00fcaa`.

| Item revision | Exact Asset version | Bytes | Original/export SHA-512 |
| --- | --- | ---: | --- |
| v2 | v1 | 25 | `f01e79186c861517fa3fae4110f28be5ccc039d53ef0685df0a8b06f60af76a47a8b8ed925501cdc0a78cea433ae6fd6ee97682f42b664ea228b63bd2c071fbc` |
| v3 | v2 | 24 | `5ccb350bf796074ec857f1692403509ccf6d6fa597e3322a2d00ba117008f79ed1208d3f25e9d6c33f63245674b51d3fd3f911677df85663616b8aea0b57b447` |
