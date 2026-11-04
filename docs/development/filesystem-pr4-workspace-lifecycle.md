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

Normal return/failure after locking the receipt uses the same cleanup policy. Staging-file disposal also
checks its original directory and writer inode before unlinking: a replacement
or link is retained, not mistaken for the copy's temporary file. Existing final
file digest checks, matching-file inode reuse and per-file rename remain intact.

### Ordinary ownership-initialization failure

The [follow-up review](https://github.com/Lost-and-Fonds/stashd/pull/94#issuecomment-6624299066)
identified a gap before the receipt handle exists: a signing exception or failed
exclusive receipt open used to strand this invocation's empty directory.

The current invocation now retains the device/inode it observed immediately
after its successful `mkdir`. If setup throws before it owns a receipt handle,
its `finally` rechecks canonical path safety, directory type and that saved
identity, and calls non-recursive `rmdir` only if the directory is still empty.
Any contents, replacement directory or symlink are retained and reported. A
competing receipt lock disables this fallback; even an empty directory remains
with its live local owner. Conflicting receipt material is never removed by
this fallback.

This in-memory proof exists only in the invocation that created the directory.
Later retries still cannot reclaim unowned directories. Process death before
receipt creation cannot run this cleanup and remains the explicitly documented
conservative obstruction, not a reason to infer ownership from a name.

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
real Tempest signer, normally delegates signing/verification unchanged, and blocks on a
fixture PostgreSQL advisory barrier after observing actual verification and
filesystem deletion. Production has no crash hook. The parent observes the
blocked session and SIGKILL termination; the observer never fabricates a
preservation receipt, successful cleanup or accepted Operations Result.
For initialization tests it can instead throw during signing, or create a
conflicting receipt file so the publisher's real exclusive open fails. Negative
controls introduce nonempty, replaced or symlinked directories before the
controlled signing failure. All such changes are inside the disposable fixture.

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
| Initial implementation `lerd composer qa` | Exit 0; 607 passed, 42 pre-existing incomplete contracts, 34,682 assertions, 328.55 s, eight processes. |
| Follow-up ordinary initialization failure and three retention controls | 5 passed, 624 assertions, 40.25 s. |
| Follow-up complete lifecycle and #93 recovery/confinement cases | 26 passed, 6,309 assertions, 225.99 s, two processes. |
| Follow-up final `lerd composer qa`, including all new and existing cases | Exit 0; 612 passed, 42 pre-existing incomplete contracts, 35,288 assertions, 352.32 s, eight processes. |

Formatting and lint pass. Static analysis reports only the same four existing
informational notices in ItemArchive, PreservationTransaction and JobRegistry.
The original external exact-byte/tree verifiers and inode/history assertions
are unchanged. No tests are excluded or marked as expected failures.

Before the follow-up fix, the controlled signing-failure case failed because
the empty workspace still existed. All five initialization cases now assert
the failed publication exposes no reports; empty owned setup directories are
removed, whereas conflicting receipts, contents, replacement directories and
links survive. Later retries still fail on unowned obstructions. Once the test
explicitly removes its own introduced obstruction, the same frozen run retries
successfully with the source absent and passes the external exact-tree verifier.

An intermediate regression run exposed a separate fixture-timing bug: the
changed-root control observed no successor Attempt. Its message's `delivered_at`
was only 60 seconds behind Messenger's cutoff despite subtracting 61 seconds,
because PostgreSQL rounded the fractional timestamp to the column's whole-second
precision. The fixture now truncates the current timestamp before subtracting
61 seconds. This changes only test delivery eligibility, not the production
60-second visibility policy or any authority/result assertions. A speculative
SQL-session teardown wait did not resolve that failure and was removed.

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
