# Filesystem workspace lifecycle implementation

**Draft implementation; return for review before merge.** This follows the
[review on #93](https://github.com/Lost-and-Fonds/stashd/pull/93#issuecomment-6620709976).
The acceptance baseline remains `d322325` on #93, unchanged. Its original
[failure report](filesystem-pr4-acceptance.md) records the pre-fix findings,
not the behavior of this implementation branch.

## Scope and API

The production change stays in `FilesystemPublisher`, apart from making the
existing `BroadcastInvocation::$run` a `public readonly` property. That value
identifies the frozen selection; it grants no publication or deletion authority.
The publisher also receives Tempest's existing `Signer` through dependency
injection. There is no new database table, preservation history, queue or
generic recovery API. `PreservationTransaction`, Operations result fencing and
the existing Broadcast SQL serialization are unchanged.

## Ownership and liveness

Each private directory is still named `.stashd-publication-<32 random hex>`.
Before creating its plan, name index or staging bytes, the publisher creates a
zero-byte sibling receipt with exclusive creation:

```text
<workspace>.<workspace device>.<workspace inode>.<signature>.owner
```

Tempest signs the JSON encoding of these ordered values:

```text
["stashd-filesystem-workspace-1", frozen run ID, canonical destination path,
 destination device, destination inode, workspace basename,
 workspace device, workspace inode]
```

The receipt filename carries the signed claim, so SIGKILL cannot leave a
partially written JSON claim. Neither the random name nor its device/inode
numbers authorize cleanup without a valid signature for this run and root.
The receipt is mode 0600 and the directory is mode 0700. The publisher holds an
exclusive, nonblocking native `flock` on the receipt's open handle until cleanup
finishes or the process exits. It does not expire or depend on a PostgreSQL
session. A living worker that loses SQL authority therefore still excludes a
successor from deleting its workspace.

The existing SQL publication lock continues to serialize ordinary invocations.
This local lock only protects private workspace lifetime; it is not a new
permission to accept Results or a fence around every final-file side effect.

## Reclamation

Before creating another workspace, publication inspects the destination with
literal directory iteration, not a glob pattern. Valid destination names that
contain brackets must not hide abandoned or operator-owned material.

For each discovered receipt, reclamation:

1. Rejects duplicate receipts, unowned directories and unknown matching names.
2. Checks that the receipt is a zero-byte, single-link regular file, opens it
   without truncation, and acquires its nonblocking local lock.
3. Matches the open handle to the receipt's current device/inode, verifies the
   signature against the current run and root, and checks the workspace's exact
   directory device/inode. Canonical path validation rejects symlink ancestors.
4. Preflights every present entry before deleting any. Only regular single-link
   `plan`, `bytes` and `name-<64 lowercase hex>` entries are recognized. Unknown
   files, directories, links and special entries obstruct cleanup.
5. Before each unlink, repeats receipt/root/workspace verification and compares
   the entry with its preflight device/inode/mode. There is no recursive delete.
6. Removes the empty directory, verifies ownership again, then removes the
   receipt last. The caller retains the lock until all of this finishes.

If another SIGKILL happens between unlinks, missing entries are already cleaned
and the receipt still authenticates the remainder. If it happens after `rmdir`,
the successor can verify and remove the sibling receipt without a directory.
An obstruction introduced after cleanup death is detected on the next full
preflight, rather than skipped because cleanup previously started.

Normal return/failure uses the same cleanup policy. Staging-file disposal also
checks its original directory and writer inode before unlinking: a replacement
or link is retained, not mistaken for the copy's temporary file. Existing final
file digest checks, matching-file inode reuse and per-file rename remain intact.

## Framework choices

The installed Tempest storage and process documentation and implementations were
examined, along with `Signing/Signer`, `GenericSigner`, `Signature`, `SigningKey`,
`SigningConfig`, its default config and initializer. The installed docs contain
no cryptography chapter; the installed signer implementation is the API source.
Signing and verification use that facility, not a second application HMAC API.

Tempest's `GenericStorage`/Flysystem interface does not expose `lstat` identities,
link counts or locks on a retained receipt handle. Its recursive directory
deletion cannot meet the requirement to preflight and retain unknown material.
Native PHP file operations are therefore deliberately local to this publisher.
The installed Symfony `FlockStore` was also examined: it uses persistent hashed
temporary lock files rather than the removable receipt inode that must survive
this directory's removal. Native `flock` on the receipt fits that exact lifetime
without adding another lock namespace or cleanup policy. Revisit these choices
if the installed storage/lock facilities expose equivalent confined, per-entry
identity and receipt-handle semantics. The real-process tests below exercise
these boundaries, including loss of both SQL sessions.

Test workers reuse the existing isolated CLI harness; its optional bootstrap
argument selects an observer for these tests only. That observer decorates the
real Tempest signer, delegates signing/verification unchanged, and blocks on a
fixture PostgreSQL advisory barrier after observing actual verification and
filesystem deletion. Production has no crash hook. The parent observes the
blocked session and SIGKILL termination; the observer never fabricates a
preservation receipt, successful cleanup or accepted Operations Result.

## Evidence

The first new cleanup test was run before the production fix and failed because
the real interrupted worker left **zero ownership receipts**, where one was
required. After implementation:

| Gate | Observed result |
| --- | --- |
| Both original SIGKILL failures, staging and rename/before-report | 2 passed, 653 assertions, 55.42 s. |
| Unchanged complete #93 matrix, including custody, partial OCFL, damaged Asset, live takeover and confinement | 10 passed, 2,176 assertions, 161.57 s. |
| New lifecycle suite: three cleanup-interruption variants, six ownership/content negatives, live owner after both SQL sessions disappear | 10 passed, 3,230 assertions, 171.11 s. |
| Additional literal-root regression | 1 passed, 279 assertions, 17.90 s. |
| Focused PR 1–3, synchronous Filesystem, Operations concurrency/delivery/fencing, Acquisition, Broadcast, Catalogue fencing and work-context regressions | 52 passed, 5,556 assertions, 251.45 s, two processes. |
| Independent Microtext/mutation Python checks | 11 passed. |
| Final `lerd composer qa`, including all new tests and unchanged #93 tests | Exit 0; 607 passed, 42 pre-existing incomplete contracts, 34,682 assertions, 328.55 s, eight processes. |

Formatting and lint pass. Static analysis reports only the same four existing
informational notices in ItemArchive, PreservationTransaction and JobRegistry.
The original external exact-byte/tree verifiers and inode/history assertions
are unchanged. No tests are excluded or marked as expected failures.

The three cleanup-interruption cases independently observe partial unlink,
partial unlink followed by a new unknown obstruction, and completed `rmdir`
with its receipt still present. Successful recovery removes all workspace
material and passes the external no-extras verifier with the source gone.
Negative controls retain unknown hidden content, a linked staging name,
replacement directory, changed root, another run's ownership and a forged
signature. The live-owner test observes actual successor Attempts failing
while the old PHP process remains alive after both claim and publication
sessions are terminated; after its exit a new build completes normally.

The private fixture-admin configuration created for this run and generated
Python bytecode were removed; Lerd debug capture was restored to enabled.
No test PHP worker remains. Database/role inspection found only the same two
pre-existing fixture identities (`827d41e078c94da1` and `dddfea62feb71070`),
with no active fixture sessions; neither was modified or removed.

### CI execution budget

The log for baseline [run 41751600893](https://github.com/Lost-and-Fonds/stashd/actions/runs/41751600893)
reports the two known product failures and a completed Pest duration of
**724.77 seconds**, then the action's **12-minute timeout**. The timeout is a
separate execution-budget problem, not evidence of a third product defect.

CI now runs two Pest processes instead of one, keeping the 12-minute step
deadline and all test suites/assertions. It also creates a private ephemeral
`.env` signing key for its isolated checkout and removes it in the existing
always-run fixture cleanup. Local eight-process QA timing is not a prediction
of hosted two-process timing; the implementation PR's actual CI is the budget
check, and its result will be linked in the PR discussion.

## Operational limits

- A stable Tempest `SIGNING_KEY` is now required for Filesystem publication.
  Losing or rotating it while receipts remain makes those receipts unverifiable;
  material is retained as an obstruction, never deleted using a fallback guess.
- A crash before receipt creation can leave an empty, unowned directory. A
  legacy workspace or another frozen run's receipt also obstructs publication.
  These need operator investigation; this change does not invent a general
  cross-run janitor or silently bless old directories.
- Cleanup preflights present entry identities in memory. This is not evidence
  for PR 5's 10,000-Item scale target; that stress run was not repeated.
- The destination must remain operator-controlled, with external writers
  excluded. Checks detect observed replacements/links, not every hostile
  check/unlink or inode-reuse race. Local flock semantics must work on the
  actual filesystem; distributed/network filesystem guarantees are not claimed.
- These are process-crash guarantees, not power-loss/fsync durability or
  whole-directory atomicity. Receipt authentication does not preserve archival
  bytes; the existing Vault and preservation transaction still own that job.
