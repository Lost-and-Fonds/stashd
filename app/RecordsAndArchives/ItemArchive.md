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
- `pins: []`: explicitly metadata-only, with no claim of acquired material.

Each successful `commitRevision()` call creates an OCFL archival revision, including
repeated snapshots. This is archive commit order, not source chronology. OCFL
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

## Asset references deliberately unavailable

Every nonempty `pins` argument is rejected before writing. Core cannot yet
establish genuine Asset ownership: the canonical Asset preservation service and
its archival records do not exist on this baseline. No Asset schema is invented
here. Exact pins, ownership validation and successful pin history remain
unimplemented; the three `ItemHistoryTest` scenarios needing `asset.preserve`
remain INCOMPLETE. Passing rejection scenarios prove only that unsupported pins
cannot corrupt metadata-only history, not that Asset validation is complete.

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
