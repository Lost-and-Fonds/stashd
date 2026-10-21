# Local single-Object publication after process death

This contract covers Stashd's Item/Asset publication APIs on a local primary
Vault, with one writer per Object, using POSIX same-directory atomic rename.
It is a process-crash contract, not a power-loss durability claim.

## Ownership and the actual sequence

Stashd's Vault serializes Object writes with Symfony's FlockStore. Locks are
released by the OS on SIGKILL; correctness does not depend on a finally block.
The existing LocalArchiveStorage move uses native rename, restricts it to
siblings on the same filesystem, rejects symlinks, forbids replacing a version
directory and allows replacing a metadata file. Neither Tempest's write nor
writeStream provides atomic publication by itself.

ottosmops/ocfl 1.0.0 publishes as follows:

1. For a new Object, create its directory, NAMASTE declaration and pending ID
   metadata. These alone are not committed history.
2. Hash the new logical state, inheriting unchanged content from older versions.
3. Create vN.tmp-<random>; copy/write new content there. Stashd reads the stored
   bytes back before permitting publication. AssetArchive first takes a private
   acquisition snapshot outside the Vault.
4. Write the version inventory and its checksum into that staging directory.
5. Rename that sibling directory to vN, refusing an existing destination.
6. Write a temporary root inventory and rename it over inventory.json.
7. Write inventory.json.sha512 **in place**.
8. Discard pending Object metadata and reopen the Object. Asset receipts are
   returned only after verifying the archived record and requested bytes.

There are two genuine gaps: vN can exist before root publication, and the root
inventory/checksum are separate files. In-place step 7 additionally destroys the
previous root checksum if killed during the write. This last defect belongs to
the library. It is reproduced without Stashd by a standalone separate-process
SIGKILL test and corrected in [upstream PR #1](https://github.com/ottosmops/ocfl/pull/1).
The fix writes every sidecar to a sibling temporary file and renames it into
place. It preserves the old checksum until that rename. No new Filesystem
interface or Stashd policy is added upstream.

## Stashd acknowledgement and inspection

The root checksum is the acknowledgement point. It must match either the root
inventory or an immutable version inventory. A complete vN directory, a staged
inventory or the intended next version number is never an acknowledgement.

`vault:inspect-object <object-id>` opens the configured Vault in a fresh CLI
process and reports `head`, `versions` and precise `issues`. It runs under the
Object write lock. It verifies the selected inventory, all its immutable
historical inventory copies and sidecars, historical state agreement, manifest
coverage and payload digests using the library validator plus stricter Stashd
snapshot checks. The validator is given a temporary **in-memory view** of the
acknowledged root inventory and its referenced version directories. The
physical Object is not presented as healthy: every excluded root entry is
reported separately as incomplete/unacknowledged evidence. The returned Object
uses the real filesystem, not that validation view.

If the root inventory has advanced but its sidecar has not, the old checksum
identifies the previously acknowledged immutable inventory. Exact Item and Asset
reads resolve through that inventory; a request for an unacknowledged version
fails. Ordinary reads verify inventory copies and the selected record/Asset
bytes, rather than hashing unrelated versions' payloads on every stream open.
The explicit inspection and write preflight perform full history fixity checks.
Consequently a damaged newer payload does not prevent a valid exact older Asset
from being read, while full inspection reports the damage.

`item:inspect` and `asset:inspect` include the full publication inspection in
their output. The existing browser uses some raw library enumeration paths;
a root-pair mismatch can still make its overview unavailable. Use the CLI for
reconciliation; this change adds no recovery UI.

Any incomplete or suspicious material blocks subsequent Stashd writes. The
operator must reconcile it separately. Inspection never renames, repairs,
deletes, fills in inventories, promotes an orphan, or retries an intended
revision. Even a completely copied orphan is retained. A crash after checksum
publication can leave a committed revision without a caller receipt; inspection
reports it as committed, independently of PostgreSQL/queue state. A leftover
pending metadata file is reported and blocks writes too. A pristine empty shell
can retry, but is never returned as committed history.

An unreadable/missing checksum, mismatched inventory copies, missing content or
bad digest produces explicit issues and no fully verified head. No attempt is
made to guess a head from the largest directory number or repair an Object
whose acknowledgement cannot be established. Earlier library versions may
already have truncated a root sidecar; this change cannot retroactively prove
which intended revision was acknowledged. All remaining material stays intact.

## Verification

Run through the repository wrapper:

```
./bin/test.sh tests/Acceptance/RecordsAndArchives/CrashPublicationTest.php
./bin/test.sh tests/Acceptance/RecordsAndArchives
```

The crash suite uses real disposable storage and production ItemArchive,
AssetArchive, Vault, locks, storage adapter and OCFL writer. A test-only subclass
performs real local I/O, injects a partial physical write at the requested
boundary and kills its own writer process with SIGKILL. No exception unwinding
or normal cleanup can run. This is fault injection at the storage boundary;
no archival result, inventory, receipt or application service is mocked. Each
inspection and historical read starts in a new PHP process. The real Tempest
`vault:inspect-object` command is exercised separately.

Forty cases cover both Item and Asset, first revision and an update after two
committed revisions, at each boundary below:

| Boundary | Acknowledged after interruption |
| --- | --- |
| Partial content write/copy | Previous head, or none |
| Completed content write/copy | Previous head, or none |
| Partial staging version inventory | Previous head, or none |
| Completed version inventory + checksum | Previous head, or none |
| Version directory renamed to vN | Previous head, or none |
| Partial temporary root inventory | Previous head, or none |
| Root inventory renamed | Previous head, or none |
| Partial temporary root checksum | Previous head, or none |
| Root checksum renamed | New head |
| Before pending metadata cleanup | New head |

Native JSON parsing and SHA hashing independently verify acknowledged root
checksums, every version inventory sidecar, historical state blocks and every
manifest payload. Snapshots prove all pre-existing version files survive
unchanged, repeated inspection changes no Vault bytes, and rejected retries
preserve all evidence. Additional tests damage acknowledged inventory copies,
sidecars and payloads and require explicit rejection without deletion.

Before the dependency fix, the application checksum-partial regression loses
its verified prior head (`null` instead of `v2`, for both Item and Asset). The library-only reproducer reports `Crash destroyed the
previously acknowledged inventory checksum`. Both pass after atomic sidecar
replacement. The Core suite also checks that the unacknowledged new version
cannot become readable merely because a complete vN exists.

## Temporary dependency and limits

Core temporarily uses the public hipsterjazzbo/ocfl fork branch
`fix/atomic-inventory-sidecars`, aliased as 1.0.1, locked to commit
`c7b79ee85e6b95011a34d9623c16daa80c238a77`. The source is published on GitHub;
production has no dependency on this workspace or sibling directories.
Once upstream releases this correction, remove the VCS repository and branch
alias, restore the normal released version constraint, update only this package
and rerun the crash suite. Stashd's acknowledgement policy remains in Core.

There is no fsync/fdatasync or directory synchronization here. Successful
rename and SIGKILL tests do not prove survival of power loss, kernel failure,
device write-cache loss or filesystem damage. The crash windows inside a
completed rename syscall are governed by the local filesystem's atomicity,
not by a PHP exception handler. Tests do not validate network filesystems,
S3/B2, independent hosts, distributed writers, cross-Object transactions,
acquisition checkpoints, queue/database reconciliation or replica repair.
Tests ran in PHP 8.5 under Linux Lerd/Podman, with disposable Vaults on the
container's local overlay filesystem backed by Btrfs. Other filesystems require
their own verification. Full inspection and write preflight reread history and can be expensive for
large Objects. No automatic startup sweep or destructive cleanup is added.

## Recorded result (2026-10-21)

- Records & Archives: 180 passed, 0 failed, 33 pre-existing incomplete contracts
  (catalogue, cross-Object interruption, replicas and other unimplemented driver
  operations remain incomplete; no claim that those features work).
- Crash suite: 44 passed, including 40 process-crash cases and four inspection
  command/damage cases.
- Final negative control: installing only the original InventorySidecar source
  makes both update/checksum-partial cases fail (`null` versus expected `v2`);
  first-revision cases honestly report no committed history. Fixed source was
  restored before the full acceptance run.
- Formatting and lint of changed PHP pass. Focused static analysis of all five
  changed production classes passes. `composer qa` was run; repository-wide
  lint stops at the existing unsafe-finally error in ObjectWriteLockTest.
  Formatter-only changes are retained in a separate commit. The upstream
  standalone regression was run before/after; the upstream full suite was not.

## Read consistency review regression

Candidate inventories are read once; their checksum is calculated from the
exact JSON string subsequently parsed. Ordinary reads do not hold the writer
lock, so hashing a path and then reopening it could authenticate a different
root inventory after an atomic replacement.

`PublicationReadConsistencyTest` deterministically replaces the real root
inventory after opening its old stream or capturing its old bytes. The root
checksum still acknowledges v1 while the replacement contains v2. Before the
fix, `Vault::getObject` incorrectly returns v2. Afterward it returns v1, reads
its original metadata, rejects v2 and independently verifies the acknowledged
inventory and payloads. This test performs real local I/O and rename; no
archival result is mocked. It fails before the fix and passes afterward.
