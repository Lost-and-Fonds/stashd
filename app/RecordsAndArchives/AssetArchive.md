# Local Asset preservation

`AssetArchive` preserves complete logical file snapshots through the production
`Vault`, Tempest Storage/Flysystem and `ottosmops/ocfl`. It needs no database,
search index, worker or plugin. The CLI boots a new Tempest application on every
invocation; no application memory is needed to retrieve historical bytes.

## Run it yourself

From Core, with dependencies installed and Lerd running:

```sh
lerd link                     # only if this checkout is not already linked
lerd shell
```

Run the following **inside the Lerd shell**, from the Core directory. The demo
uses a new directory under the mounted checkout so its files survive leaving
the container. The source supplied to import must be visible inside Lerd.

```sh
mkdir -p "$PWD/tmp"
DEMO=$(mktemp -d "$PWD/tmp/asset-demo-XXXXXXXX")
export VAULT_ROOT="$DEMO/vault"
php tempest vault:init

# Substitute any arbitrary file visible in Lerd for this generated binary.
head -c 134217728 /dev/urandom > "$DEMO/source.bin"
sha512sum "$DEMO/source.bin" > "$DEMO/source.sha512"
php -d memory_limit=32M tempest asset:import \
  urn:stashd:demo:item master original.bin "$DEMO/source.bin" > "$DEMO/v1.json"
cat "$DEMO/v1.json"
ASSET_ID=$(php -r '$r=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR); echo $r["object_id"];' "$DEMO/v1.json")
rm "$DEMO/source.bin"

# A fresh application opens the existing Vault and reads v1.
php -d memory_limit=32M tempest asset:export \
  "$ASSET_ID" v1 original.bin "$DEMO/restored-v1.bin"
sha512sum "$DEMO/restored-v1.bin"
cat "$DEMO/source.sha512"

printf 'revised\000binary\n' > "$DEMO/revision.bin"
php tempest asset:import \
  urn:stashd:demo:item master original.bin "$DEMO/revision.bin"
rm "$DEMO/revision.bin"
php tempest asset:export "$ASSET_ID" v2 original.bin "$DEMO/restored-v2.bin"
php tempest asset:export "$ASSET_ID" v1 original.bin "$DEMO/restored-v1-after-v2.bin"
cmp "$DEMO/restored-v1.bin" "$DEMO/restored-v1-after-v2.bin"
php tempest asset:inspect "$ASSET_ID" v1
```

To leave and restart the shell, save `DEMO`, `ASSET_ID` and `VAULT_ROOT` paths,
then run `exit`, `lerd shell` and `export VAULT_ROOT=/the/saved/demo/vault`.
Export accepts only a new absolute destination; it never overwrites. It verifies
the exported bytes, closes streams and removes a failed partial export.

Example import output (digest abbreviated here only):

```json
{"format":"stashd-asset","schema_version":1,"identity_policy":"exact-item-uri-key-v1","object_id":"urn:stashd:asset:ac0e27a4-c875-5d22-8b9d-4e243207df68","item_uri":"urn:stashd:demo:item","item_object_id":"urn:stashd:item:c61d9c66-6739-59a4-b8d0-567f9303d580","asset_key":"master","acquisition":"local-copy","files":{"original.bin":{"size":134217728,"algorithm":"sha512","digest":"63077c6f…79de4e40"}},"version":"v1"}
```

## Identity and version contract

Item identity uses `ItemArchive`'s existing `exact-uri-v1` policy. Asset identity is
`urn:stashd:asset:` plus a UUIDv5 in `Uuid::NAMESPACE_URL`, with this exact name:

```text
stashd:asset:exact-item-uri-key-v1:<JSON array [Item Object ID, Asset key]>
```

The JSON uses PHP `json_encode(..., JSON_THROW_ON_ERROR)` with default escaping.
The Item URI is validated but never normalized. Asset keys are exact, UTF-8,
case-sensitive safe relative paths. Different URI spellings or keys create
separate identities, even for identical bytes. Filename, title, provider ID and
digest do not determine identity. Changing a canonical URI or key requires future
explicit identity migration; there is no alias/equivalence policy. UUIDv5 is a
reproducible identity mapping, not a cryptographic content-integrity mechanism.
Revisions validate stored identity, owning Item and the prior head's byte evidence
under the existing Vault Object lock.

Each version contains `.stashd/asset.json`: schema/policy, owning Item URI and
Object ID, Asset key, acquisition method and per-file SHA-512/size. This makes
identity recoverable from the Vault alone. An Item record need not already exist;
the Asset records ownership but creates no Item version or Item-to-Asset pin.

`preserve($itemUri, $assetKey, $files)` takes the **entire** logical file set.
Files omitted on revision disappear from that new version, remaining in previous
versions. Multi-file versions publish together through one OCFL VersionBuilder.
The CLI intentionally imports one-file snapshots; use the service for multi-file
snapshots. Identical full snapshots currently produce the library's honest
`commit() called with no staged changes` error. No fabricated revision or new
acquisition event is returned.

## Integrity and failure boundary

1. Reject unsafe keys/paths, path-prefix conflicts, reserved `.stashd` paths,
   missing/nonregular sources and symlinked sources/ancestors before OCFL writes.
2. Copy sources into a random private mode-0700 local staging directory through
   Tempest Storage. Check opened source identity and ordinary size/time changes.
   Accepted bytes are this completed private snapshot; sources must be quiescent
   during acquisition. After acceptance, original source changes cannot affect it.
3. Independently stream/hash each snapshot, recording SHA-512 and byte count.
4. OCFL hashes and copies only these private paths. The storage bridge reads back
   each adopted copy and verifies its SHA-512 **before** VersionBuilder publishes
   the version. Inline writes are read back as well. No vendor files are changed.
5. Reopen the committed Object, verify identity and exact logical file set, then
   independently stream every committed file. Require its size/hash to match the
   accepted evidence and the OCFL inventory state, including the archival record.
   Only then return the actual Object ID/version. Export also verifies its output.

OCFL deduplication may reuse earlier content within an Object. The current head is
verified before revision and the resulting version is verified afterwards. Across
logical Assets, identical bytes never merge identities. Streams use bounded memory;
metadata and file listings scale with file count. Reads verify the whole requested
Asset snapshot before opening the requested payload stream, adding I/O latency.

This is a trusted local developer boundary, not a hostile multi-user ingestion
API. PHP pathname checks cannot prove race-free `openat(O_NOFOLLOW)` acquisition
against an adversarial local process changing directory entries, nor detect every
same-size concurrent source edit. Private staging protects the accepted snapshot
from the original producer, not from another process with the Stashd user's OS
privileges. The Vault must not be externally modified during operations.

**Publication is not crash atomic or proven power-loss durable.** The upstream
library renames the version directory, replaces the root inventory, then writes
the root digest sidecar separately. A crash can leave an orphan version or an
inventory/sidecar mismatch. An I/O failure after publication is an uncertain
outcome; no receipt is issued, no committed history is deleted, and no rollback or
manual inventory rewriting is attempted. Inspect the Vault before retrying.
The missing upstream mechanism is a verified prepare/publish boundary with a
recoverable publication journal, reconciliation of orphan versions and root
inventory/sidecar pairs, and proven flush ordering/durability guarantees. This PR
does not claim that mechanism, automatic crash recovery, or multi-Object atomicity.

Private staging is synchronous scratch space, cleaned on normal return/failure.
A process crash may leave a `stashd-asset-*` directory in the container's temporary
directory; it is not an acknowledged custody checkpoint or recoverable commit
candidate. No custody is acknowledged before successful verification.

Scope completeness, provenance events, Item version pins, replication, repair and
the full candidate journal remain explicitly INCOMPLETE in their acceptance
contracts. The architecture decision log and reconciliation supply the agreed
invariants; the candidate draft's field names and state machine are not ratified
contracts and are not implemented here.

## Verification

Run from the host in Core:

```sh
./bin/test.sh tests/Acceptance/RecordsAndArchives/AssetPreservationTest.php \
  tests/Acceptance/RecordsAndArchives/AssetCliTest.php
```

The wrapper selects Lerd locally and Pest directly in CI. The original nine
Asset contracts now exercise production services through a thin driver. Added
proofs independently inspect physical inventories/sidecars and payload hashes,
reject symlinks/unsafe revisions without changing history, detect corruption, and
inject a corrupt physical storage copy to prove prepublication rejection. The
fault decorator still writes to real storage; it implements no Asset semantics.

The CLI test starts new Tempest processes with a 32 MiB PHP limit for a 64 MiB
binary import, revision, source deletion, both historical exports, inspection and
no-overwrite rejection. Deliberately forcing `readStream()` to return v2 made the
v1 CLI export fail with `Export bytes do not match preserved byte evidence`;
the implementation was restored.

Manual Lerd demonstration, PHP 8.5.11, 2026-10-21:

- Object: `urn:stashd:asset:ac0e27a4-c875-5d22-8b9d-4e243207df68`.
- v1: 134,217,728 bytes; original source deleted; export before and after v2
  matched independently with `sha512sum` and `cmp`.
- v1 SHA-512: `63077c6faf6e28085490c8d15b97af776650c18c1a0888ae75e35aaf182826849dfd490fff09927fc243f581d0dcdf62058fe4e77e56674a70127f4b79de4e40`.
- v2: 15 bytes, SHA-512 `cb0e6b92a1c43814e2b989b73332a893e5c68d8c0f1d00547fff0c8a61600c938c423d542d95d4d018acde57efaa9eda1b03eb9752b76fb9062f48c45751307a`.
- v1 export PHP peak allocated memory: **14,680,064 bytes (14 MiB)**,
  measured with `memory_get_peak_usage(true)` in a shutdown callback; limit 32 MiB.
  This measures PHP allocation, not whole-process RSS or filesystem cache.

Final full suite: **104 passed, 36 incomplete, 459 assertions**, no failures.
The 22 Asset service cases and the real CLI case pass. No acceptance assertions
were removed or relaxed; unsupported pinning, provenance and recovery contracts
are marked incomplete before their still-unimplemented boundaries.

Focused Mago analysis of the new service/commands is clean. Changed-file lint
passes without diagnostics. Broader
lint retains an existing `ObjectWriteLockTest` unsafe-finally error. Broader
analysis retains existing Vault/adapter typing diagnostics and Pest closure
`$this` inference errors. Formatter was run only on changed implementation and
Asset proof files; unrelated existing formatting was preserved. Consequently
the repository-wide `composer qa` aggregate is not claimed green.

Python `hashlib` also independently checked every physical payload digest and
both version inventory sidecars in the manual demo; the library's `ocfl validate`
checked the resulting Object as valid, with only W007 warnings for missing optional
version message/user blocks, as a separate conformance smoke check. This is not
an independent full OCFL standards certification.
