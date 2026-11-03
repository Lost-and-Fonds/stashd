# Microtext: the independent filesystem round-trip fixture

Microtext is a **permanent, disposable corpus generator and external verifier** for Stashd's first-party Filesystem Input → Catalogue → Operations → Vault → Filesystem Broadcast path. It uses Python 3's standard library, deliberately separate from the PHP preservation implementation. It does not use Stashd's hashes, database or OCFL readers to decide whether output matches the expected bytes.

**Status:** This PR supplies the fixture and its own proof, **not** the Filesystem Input/Broadcast implementation or an end-to-end Stashd result. The Actions workflow initially does a plain copy to validate the fixture; replace that copy with the real Stashd workflow in a later PR, without weakening the same external checks.

## Generate and verify

```sh
root="$(mktemp -d)"
python3 tools/fixtures/microtext.py generate \
  --directory "$root/source" --manifest "$root/manifest.json" --count 10000
python3 tools/fixtures/microtext.py verify \
  --directory "$root/source" --manifest "$root/manifest.json" \
  --reference tests/fixtures/microtext/reference-v1.json

# Future Stashd acquisition and publication should replace this illustrative copy:
mkdir "$root/broadcast"
cp -a "$root/source/." "$root/broadcast/"
rm -rf "$root/source"
python3 tools/fixtures/microtext.py verify \
  --directory "$root/broadcast" --manifest "$root/manifest.json" \
  --reference tests/fixtures/microtext/reference-v1.json
```

Choose a new temporary directory for each run. Generation refuses to overwrite an existing source directory or manifest, and the manifest **must remain outside the source root**. The verifier is read-only and does not require the original source to exist. Only delete paths you yourself created for the test.

For quick smoke tests, use `--count 10` or `--count 100` and omit `--reference`; custom sizes are reproducible but are not pinned by the 10,000-file reference. To check fixture regressions locally: `python3 -m unittest discover -s tools/fixtures -p 'test_microtext.py' -v`.

## Frozen fixture v1

- Default seed: `stashd-microtext-v1`; generator format: `stashd-microtext-v1`.
- Exactly 10,000 independent source files in the full-size corpus; each file holds **5–10 words**, UTF-8 lowercase with a trailing LF. The first word is a distinct `entry000000`-style identifier.
- SHA-256 of the **file bytes** determines the lowercase 16-hex-character filename. Two 1-hex-character directories shard files into a nested layout: `a/b/abcdef0123456789.txt` (example only). The generator fails on a truncated-name collision rather than silently overwriting a file.
- The external manifest stores the exact relative path, index, byte count and **full** SHA-256 for every file. Its JSON serialization is deterministic. This metadata is written outside the scan root.
- `tests/fixtures/microtext/reference-v1.json` pins the SHA-256 of the entire canonical 10,000-entry manifest. That lock prevents changes to the seed, word list, generator algorithm, order or expected bytes from silently redefining success. The lock itself is **not** a replacement for the external per-file manifest.
- Changing the generator or canonical corpus intentionally requires an explicit fixture version bump and newly reviewed reference. Do not automatically update the reference when a test fails.

The truncated SHA-256 is a compact filename/checksum, *not* a security guarantee: the external verifier uses all 256 bits and exact file sizes. The separate index prefix ensures text uniqueness even if two word sequences would otherwise match. The verifier rejects missing, extra, changed, symlinked or malformed files, as well as invalid manifest entries and reference mismatches. A changed file should fail, not quietly become a newly accepted fixture.

## Desired Stashd end-to-end success contract (future PRs)

1. **Real system path:** Configure a Stash and built-in, in-process Filesystem Input for directory A, discover each file as one independent Item, acquire/preserve each through real Operations, and publish to separate directory B through the ordinary Broadcast path. No direct A→B shortcut or external plugin runtime dependency. Built-in Filesystem must use the same generic lifecycle boundaries later used by plugins.
2. **Source-independent output:** Remove or make A unavailable **before** publishing B. The authoritative Vault supplies every output byte. The checker verifies B against the external frozen manifest, not against the source, the Vault's claimed checksum or Stashd's own reported success.
3. **Counts and identity:** 10,000 unique Items, 10,000 expected preserved Assets (one per Item for this case), exact reference paths and historical version references. Distinguish discovered, acquired, preserved, verified and broadcast states rather than reporting one generic completed counter. No false successes, silently skipped inputs or unbounded in-memory enumeration.
4. **Idempotence:** Re-scan/re-acquire and repeat Broadcast with unchanged material; no duplicate Items, needless Asset/Item versions or unnecessary output rewrites. Document precisely what counts as changed content, changed metadata and a new source observation.
5. **Mutations:** In a **separate** generated mutation corpus (do not change pinned v1), add 100 files, change 200, rename 50 unchanged-byte files and remove 30. New/changed content is noticed and preserved; old versions survive; missing source does not delete preserved material. File renames must not be guessed from content hashes alone. Missing source is a discovery observation, not deletion authority.
6. **Failure and recovery:** Interrupt workers during import, crash between custody and OCFL commit, interrupt output staging, retry duplicate delivery, introduce unreadable and mutating sources, corrupt published output, then restart. Preservation receipts and actual bytes determine success. No unfenced duplicate publication, truncated output files, discarded acknowledged custody, silent repair or false completion.
7. **Path and safety:** Reject symlink traversal, output-to-source or Vault overlap, destination escapes, unsafe names and surprising collisions; never move/delete source files. Start copy-only for Broadcast (hardlinks can expose canonical inodes to modification). Write temporary output then publish atomically where the backend actually supports it; never prune unrelated destination files.
8. **Bounded load:** Progress through the 10,000-file corpus without launching 10,000 simultaneous helpers or holding all file contents in RAM. Capture actual peak RSS, elapsed time, per-phase throughput, database and Vault size, queued/running/retried Job counts, and output count. Identify whether small-file metadata/transaction overhead dominates. These are observations, not invented performance thresholds.
9. **Generality:** The built-in Filesystem facility treats `.txt` as opaque bytes. It does not invent text semantics, infer titles or decide preservation policy from filename extensions. A later probe with arbitrary binary files should use the same path.

## Engineering sequence proposed for Sol to plan

First inspect the merged implementation and current architectural notes, especially [#28](https://github.com/Lost-and-Fonds/stashd/issues/28), the current plugin-API contract status, and `PreservationTransaction`, `AssetArchive`, `ItemArchive`, `Worker` and `VerifyAsset`. **Plan the work; do not implement the entire system in one PR.**

- Establish the smallest *single-file* authentic Stash → Input → Catalogue → Job → Vault → Broadcast round trip with independent output verification, ideally as an acceptance test written first.
- Extend to bounded directory discovery, repeatability and exact version updates, still with tiny smoke fixtures.
- Introduce explicit fault-injection/restart and filesystem confinement tests before scaling.
- Use the pinned 10,000-file fixture in the separate post-merge/release workflow. The full stress stage should call actual app entrypoints, then externally verify outputs and produce measurements.
- Split the work into focused PRs targeting `dev`, preserving the existing user-first success contracts. Follow repository `AGENTS.md` (Tempest-first; database models extend shared `AppPersistenceModel` where relevant). Do not introduce generic orchestration, new workflow engines, a second file storage subsystem or unsupported guarantees simply for the stress test.

## Workflow frequency

The standalone `.github/workflows/microtext-stress.yml` runs on **merged PRs targeting `dev`**, published releases and manual dispatch. A merely closed PR does not run it. The normal PR CI should execute only lightweight unit/smoke checks, **never 10,000-file round trips**. The stress workflow runs the corpus self-check for now; it does *not* claim to test preservation or publication until later implementation wires them in.
