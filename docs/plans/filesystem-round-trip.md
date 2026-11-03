# Filesystem round-trip implementation plan

**Status:** PR 1 implemented for draft review; see [implementation decisions and proof](../filesystem-roundtrip-pr1-decisions.md). PRs 2–5 remain unstarted.

## Basis and approach

This plan incorporates the [review comment on #85](https://github.com/Lost-and-Fonds/stashd/pull/85#issuecomment-6594803803). [#28](https://github.com/Lost-and-Fonds/stashd/issues/28) defines the Filesystem milestone; [#85](https://github.com/Lost-and-Fonds/stashd/pull/85) and its [fixture specification](../development/microtext-fixture.md) define independent verification and stress evidence. Those documents remain authoritative rather than being reproduced here.

Reuse existing discovery, acquisition, identity reassociation, preservation transactions, exact-version selection and Filesystem publication. Connect them to minimal real Catalogue records and the existing Worker → Messenger → Durable execution path.

Keep PR 1 substantial enough to deliver an authentic single-file round trip. Do not split it into infrastructure-only increments merely to reduce its size.

## Persistence ownership and interfaces

Map models to existing facts before writing migrations. All new database models extend `App\Persistence\Model`.

| Model or facility | Responsibility and relationship to existing records |
|---|---|
| `Stash` | New Catalogue record for a user's collection. |
| `Item` | Stable Catalogue identity linked to the existing canonical Item URI; no duplicate archival revision history. |
| `StashItem` | New membership relationship between Stash and Item. |
| `StashInput` | Relates a Stash to an existing Input configuration owned by `IntegrationConfigurations`. |
| `ItemSource` | Associates a canonical Item with an existing `DiscoveryStore` source association. Evolve the existing acquisition identity mapping rather than introduce another competing mapping. |
| `DiscoveryStore` | Continues owning discovery runs, source associations, observations, continuation and coverage. |
| `IntegrationConfigurations` | Continues owning Input/Broadcast configuration. |
| Vault archives | Continue owning preserved revisions, bytes and exact Item–Asset pins. |
| Operations | Continues owning admission, Demands, Attempts and accepted execution results. |

Preserve existing configuration identifiers and minted Item URIs during migration. Reassociation from canonical provenance must retain its existing conflict checks.

Retain existing configuration/inspection commands. Add Stash creation, Input attachment and Stash-scoped selection. Execution commands admit concrete discovery, per-file acquisition and frozen-publication Jobs and return identifiers; existing workers perform execution. Selection resolves verified canonical revisions through the real Broadcast coordinator.

Use Tempest facilities and the existing application connection. Inspect actual transaction ownership before combining Integration writes with Operations admission. No second queue or generic persistence/orchestration framework.

Keep the in-process lifecycle compatible with canonical plugin-api discovery, staging and publication semantics. At planning inspection, remote plugin-api main declares 0.18.0, while local files declare 0.19.0; local changes do not establish the canonical baseline. This milestone requires no external runtime or SDK dependency.

## Five-PR checklist

Completion means acceptance evidence, negative controls and required QA pass—not merely that code or tables exist.

- [x] **PR 1 — Authentic single-file round trip.** Acceptance: real Stash/Catalogue → Operations → canonical Vault → source removed → Broadcast; independent bytes verified; duplicate delivery and post-commit replay proven. Dependencies: revised plan reviewed; #85 fixture available. **Status: implemented for draft review; not merged.**
- [ ] **PR 2 — Bounded directory runs.** Acceptance: 100/1,000 files; bounded admission; crash-safe cursor; unchanged rescans create no additional archival revisions. Dependency: PR 1. **Status: unstarted.**
- [ ] **PR 3 — Mutations and historical output.** Acceptance: required mutation corpus; stable identity for modified paths; retained history; original and changed selections independently verified. Dependency: PR 2. **Status: unstarted.**
- [ ] **PR 4 — Broader interruption/recovery.** Acceptance: real worker interruptions, publication recovery and stale-result rejection; no false completion or duplicate authoritative results. Dependencies: PRs 1–3. **Status: unstarted.**
- [ ] **PR 5 — Measured 10,000-Item workflow.** Acceptance: real application entry points replace illustrative copy; source-independent external verification; truthful counts and resource measurements. Dependencies: PRs 1–4 and #85. **Status: unstarted.**

### PR 1 — Authentic single-file round trip

Write the fresh-process acceptance tests before implementation.

- Add the minimal Catalogue relationships above and concrete departmental Jobs, reusing existing adapters, coordinators and archives.
- Generate a one-file Microtext corpus with its manifest outside A. Configure a Stash/Input, discover, acquire through Operations, select the exact preserved revision, remove A, restart and publish B. Verify B externally.
- Independently inspect membership, canonical Item history, exact pins and payload evidence.
- **Critical replay proof:** interrupt after canonical preservation commits but before worker success acknowledgement. Remove A before retry. The retry must recover the same persisted transaction, verify canonical evidence and complete without reopening A or creating another OCFL version.
- Cover duplicate delivery. Retain custody/recovery evidence until accepted results make cleanup safe.
- **Negative control:** falsely report Job completion while omitting preservation; the acceptance proof must fail.

Primary hazards are identity duplication, premature evidence cleanup and confusing Operations completion with canonical preservation. Basic path, collision and publication safety remain mandatory here.

### PR 2 — Bounded directory runs and safe admission

Reuse durable discovery batches and paged observations. Add only the persisted admission progress needed to feed per-file Jobs with bounded outstanding work. Start with at most 100 outstanding acquisition Assignments per run, replenishing as they settle.

Do not assume discovery cursor writes and `Worker::do()` share an atomic transaction. Use **admit before advancing the cursor**, with replay against persisted run/source acquisition identity. A crash after admission may repeat admission, but cannot repeat canonical preservation or skip a file. Persisted discovery remains the replayable source of work; introduce no second generic queue.

Tests written first must interrupt both sides of that boundary, restart and verify the exact output set. Explicitly test that a cursor cannot commit past a failed admission.

Exercise 100 and 1,000 files, unchanged rescans and publication reruns. Assert actual Item/version counts and unchanged output inode identity.

**Negative control:** advance the cursor before a failed admission; the restart proof must detect the missing file.

No new entity models; extend existing run records.

### PR 3 — Mutations and historical reconstruction

Use the separate mutation corpus specified by #85. Reuse unchanged-content comparison, canonical provenance and frozen exact-version selections.

Tests written first verify modified-path identity, rename-as-new behaviour, retained missing-source history and reconstruction of both historical selections after source removal. Only exhaustive discovery can establish absence.

**Negative control:** replace historical reads with latest-head reads; historical output verification must fail.

Keep observations in existing discovery persistence. No new archival-history store or rename inference.

### PR 4 — Broader interruption and recovery

Extend beyond PR 1's mandatory acquisition replay proof: interrupt accepted custody, OCFL commit, staged Broadcast and rename-before-acknowledgement. Exercise stale workers and concurrent recovery using real processes.

Reuse transaction journals, custody reconciliation, publication locks and fenced Operations results. A recovering worker verifies evidence before adoption; an SQL check does not authorize a later filesystem rename.

Write interruption and confinement tests first. Retry the same transaction or frozen selection; matching complete outputs may be reused.

**Negative control:** remove final result fencing; stale-worker acceptance must fail.

No new recovery manager or entity models. Document per-file publication atomicity and remaining external-writer limitations.

### PR 5 — Real stress workflow

Replace #85's illustrative copy with production Stash/Input/Operations/Broadcast commands. Keep the external manifest, reference pin and verifier independent.

Progress through 100, 1,000 and 10,000 files. Repeat unchanged ingestion before removing A, then remove disposable acquisition staging, restart and publish solely from canonical Vault bytes. Verify exact output and canonical Item–Asset evidence separately from Job status.

Record phase timing, process-tree peak RSS, queue widths, Assignments, Attempts, retries, exceptions, writes and database/Vault growth. Set no invented throughput target.

**Negative control:** corrupt one output; verification must fail.

Keep heavyweight execution post-merge, on release or manual. Routine CI runs small application proofs and quick fixture regressions.

## Review and completion gates

For every implementation PR: acceptance tests first, canonical `./bin/test.sh`, demonstrated failing negative control, restored implementation, relevant regressions and full `composer qa` in the intended Lerd environment.

Use additive migrations that preserve existing configuration, source associations and archival identities. Do not reset working databases automatically.

Defer watchers, external plugin runtime, broad metadata infrastructure, hardlinks and automatic pruning as specified in #28/#85. No automatic merge.

PR 1 was subsequently authorized explicitly and is now implemented for draft review. Do not start PR 2 before that review.

The original plan was based on inspection. PR 1 runtime proof and QA are recorded in its implementation PR. Local roots remain operator-controlled, and no hostile-writer, whole-tree atomicity or power-loss guarantee is claimed.
