# Microtext Lab — development-only round-trip observability

**Status:** design proposal, not an implemented UI or a test success claim.

**Scope:** a small, read-only page at `/dev/microtext` for watching **real** Stashd
Filesystem/Microtext acceptance and stress runs, including the Operations workload
and Tempest event observations. It is not a general-purpose monitoring product,
an alternate test runner, a new Operations department or a production feature.

The first useful target is the 100/101/1,000-file suite from
[PR #89](https://github.com/Lost-and-Fonds/stashd/pull/89), after its merge.
PR #89 is independent and should not be held up by this Lab. The pinned
10,000-file **real application** exercise remains PR 5; the existing fixture
self-check is not equivalent to that exercise.

## Purpose and non-negotiable constraints

The dashboard should answer three questions at a glance:

1. **What has truly happened?** How many files were discovered, admitted, settled,
   preserved in the canonical Vault and independently reconstructed? Keep these
   distinct: an accepted Operations Result is not an OCFL or output-byte check.
2. **Where is it waiting?** Observe the run's admission cursor, pending/running
   per-file Jobs, attempts, retries, failed work and queue saturation relative
   to the 100-outstanding limit. A ceiling of 100 is a capacity limit, not a
   promise of 100 parallel filesystem readers.
3. **Are events telling the truth?** Compare received Tempest events with
   committed SQL and verified OCFL evidence, showing dropped, late, duplicate
   and uncorrelated observations rather than concealing them.

Read-only by design. The Lab may select runs, filter events, pause the **display**,
and inspect errors; it must not start, stop, retry, approve, delete or alter any
application Job, test fixture or Vault. The canonical test harness remains the
authority for pass/fail.

## Critical boundary: disposable test fixtures

PR #89's acceptance/stress workloads use generated PostgreSQL databases, private
schemas and disposable Input/Vault/Output directories. A normal long-running
Stashd web process connected to its own database **cannot see that work**.

Do not solve this by moving the tests into a shared development database,
allowing arbitrary database credentials in a web request, or giving the dashboard
direct filesystem paths. Instead, provide an opt-in **run-scoped observer** inside
the test invocation's existing configuration boundary:

```text
     Existing isolated test process / workers
         |  SQL snapshots from the same fixture database
         |  OCFL verifier and process-tree observations
         |  actual Tempest event dispatch observations (separate lane)
         v
  Dev-only observer -> bounded, scrubbed run records
                            |
                            v
     Read-only Tempest Lab endpoint -> browser
                snapshot polling / SSE
```

The observer is **not** the work coordinator: it must never change the order of
admission, preservation, publication or acknowledgement. If it crashes, the test
continues, and the UI displays a stale-observer warning.

Recommended first version: one file-backed observation spool per Lab run under an
explicitly configured, private, local development directory (e.g.
`.tempest/dev/microtext-lab`), with owner-only permissions. Append events as
NDJSON; atomically replace a small current-snapshot JSON file. Store no database
credentials, source content, arbitrary relative/absolute filesystem paths,
user-supplied metadata, cookies or secret headers. Lab run IDs are random,
non-path identifiers. The HTTP handler reads **only** those scrubbed records,
not database connection strings or original Vault fixtures.

Create the Lab run descriptor **before** discovery starts, then update its
correlation IDs as they become known. The observer and its workers must share a
well-defined output directory/container mount if they run in separate Lerd
processes. Verify that this shared path exists in the intended Lerd workflow;
do not silently assume host `/tmp` is visible inside every container.

This first approach avoids a new persistent database table and keeps
instrumentation outside the correctness paths. If native Tempest EventBus
middleware or SSE support changes how events reach another process, inspect
the installed Tempest implementation before coding; do not rely on a past
RoadRunner/FrankenPHP frontend that is absent from the current `dev` baseline.

## UI structure

The Lab is intentionally compact, not a replacement for Stashd's eventual
user-facing activity or operations pages.

### Header and run selector

- `Microtext Lab` with **DEV ONLY**, selected Lab run ID and current phase:
  fixture generation, discovery, admission, acquisition, source deletion,
  frozen selection, Broadcast, verification, finished or failed.
- File count and run preset (3, 100, 101, 1,000; eventually 10,000).
- Start time, elapsed duration and observer age. Explicit “observer stale”
  when no snapshot has arrived within three polling intervals.
- Current linked discovery run UUID and Broadcast run UUID when known; show
  their absence as unknown, never fabricate them.
- Last N local runs with a capped total disk budget; selecting a previous run
  cannot change its state. A historical trace may remain viewable after the
  disposable source/database has been removed.

### KPI strip

Show **discovered / durably admitted / settled / outstanding capacity / queued /
running / failed awaiting retry** as distinct numbers.

From PR #89: `input:admission` reports `outstanding` using active **or failed**
Assignments. Therefore **do not label it “in flight.”** It is unresolved
capacity occupied; queued, running and failed require their own real status
projection. Do not derive active workers by subtracting settled counts.

Additional cards: successful preservation outcomes, unchanged verified outcomes,
committed OCFL Item/Asset versions (when independently inspected), successfully
published files, total verified bytes and verifier result. Unknown and “not yet
verified” are first-class states; avoid showing zero before sampling has started.

### Timeline

- A line/area chart at most one point per second: admitted, settled, queued,
  running, failed and capacity occupied against the **100** ceiling.
- Optional throughput (settled files/s) and verified source/output bytes/s,
  labeled as interval rates and not invented benchmark numbers.
- Phase-duration bars for discovery, preservation, Broadcast and final
  verification, once known.
- Sampled process-tree RSS and CPU if available. **Peak RSS** means the
  maximum *sampled sum of concurrently alive process RSS*, not the sum of every
  process's individual lifetime peak. Show sample interval and mark missing
  samples rather than claiming a measured peak from absent data.
- Avoid doing an exhaustive OCFL tree walk every second. Use bounded SQL
  snapshots frequently; expensive physical inventory checks at milestones
  and final verification.

### Event inspector

Display an ordered, filterable list of **actual captured Tempest dispatches**,
not synthetic “events” inferred from changing counters. Each row should show:
type, event UUID, occurred UTC time, observer receipt time, correlation/causation
IDs, related run/Assignment/Attempt/Item IDs, and the **observation category**.

Use three clearly named categories:

- **Domain event observed:** an actual Tempest EventBus dispatch captured
  by the dev observer; it may precede commit and is not durable evidence.
- **Persisted transition observed:** an independently sampled change in
  PostgreSQL; this is not an EventBus dispatch.
- **Archive/output verified:** a successful independent physical check of
  preserved or published bytes. These are checks, not Events.

When the corresponding later persisted fact is absent or inconsistent, show
“unconfirmed” or “contradicted”; do not silently suppress the event or
magically convert it into a committed archival fact. Event loss is observable,
not an application failure unless an explicit event contract requires delivery.
Filtering and temporal correlation may use heuristics, but must label inferred
links as inferred.

### Useful diagnostics

- A concise diagnostics panel for **run total, queue peak, retries, interrupted
  Attempts, worker exits, failed admissions, cursor position, skipped-source
  check, snapshot lag, missed sequence IDs and dropped event records**.
- Show the last error with safe code/type and redacted message; never expose
  connection DSNs or arbitrary exception dumps.
- Independent verifier: pending/running/pass/fail; expected, found, missing,
  extra and corrupted output counts, plus OCFL inventory/pin verification
  status. A green Operations Result must not turn this green by implication.

## Events: first real consumer, not a fictional durable event bus

Current `dev` has EventManagement **typed definitions** and a source catalogue
(`docs/event-management-catalogue.md`, `EventCatalogueAudit`). The audit
explicitly states emission, verification and consumption are not yet proven.
`EventMetadata` provides event UUID, occurred-at UTC and correlation/causation
references. The event catalogue warns that Tempest dispatch is synchronous and
does **not** imply a database transaction committed.

The Lab should be the first **dev observer**, not silently upgrade all defined
events to durable or publicly visible notifications. Begin with the smallest
useful, actually emitted lifecycle events, after verifying their constructor
contracts and emission boundaries. Prefer existing EventManagement types where
their semantics genuinely match. Do not “emit AcquisitionCompleted” for an
individual file merely because its Job ended if the typed event means a complete
sealed acquisition scope.

For new emissions:
- Emit only from an ownership boundary that knows the fact actually occurred.
- Document whether an event is pre-commit, post-commit best effort or
  observation-only; a post-commit crash can still lose an EventBus dispatch.
- Carry stable run/Assignment/Attempt/Item identifiers and safe metadata.
- Record one event-observation test with a real Tempest subscriber, including
  **non-emission on rollback/revoked authority** where that is the contract.
- Keep the event capture optional and **failure-isolating**: a disabled or failed
  Lab listener cannot alter application persistence or make the test pass/fail.
- Bound the live event log; loss/drop counts must be visible. Avoid emitting
  a large event for every tiny progress tick in the 10,000-file workload.

Use native Tempest events and middleware after checking the installed APIs.
For HTTP transport, the first working UI can poll snapshots every ~1 s and
read event pages. Add SSE only when its reconnect, authorization and
multi-process delivery behaviour is demonstrably correct. SSE is a view
transport, not archival event durability.

## Proposed read-only endpoints

All endpoint shapes are **reviewable** until the framework integration is
implemented and validated:

| Endpoint | Function |
| --- | --- |
| `GET /dev/microtext` | Development-only HTML page rendered through available Tempest view facilities. |
| `GET /dev/microtext/api/runs` | Bounded, safe list of local Lab runs. |
| `GET /dev/microtext/api/runs/{id}/snapshot` | Latest immutable, scrubbed snapshot for one run. |
| `GET /dev/microtext/api/runs/{id}/events?after={seq}` | Bounded actual event-observation page with monotonic sequence and reconnect cursor. |
| `GET /dev/microtext/api/runs/{id}/stream` | Optional, **after** polling is proven; SSE notifications with resume IDs. |

No POST, PUT, DELETE, start/kill/retry buttons or credentials in URLs.

## Security and runtime gates

- **Production must not register these routes.** Both environment type
  `development` and an explicit `STASHD_MICROTEXT_LAB=1` opt-in must pass.
  A disabled route responds 404, not a leaked HTML skeleton or JSON error
  containing private paths.
- Guard access through local-only development binding or existing authenticated
  development access; do not assume a proxy's `X-Forwarded-For` is trustworthy.
  If accessed through a remote Lerd/proxy setup, use the existing authentication
  controls rather than trusting any client-supplied header.
- Never let `{id}` become a filesystem path. Validate fixed safe ID grammar and
  use an allow-listed root. Reject symlinks/path traversal and unknown run IDs.
- Never ship private fixture administrator credentials, PDO DSNs or raw
  event payloads to the browser. Event allow-list and output redaction are
  deliberate review items.
- Keep retention and disk use capped (e.g. last 10 runs, 5 MB per event stream,
  bounded snapshots); old run cleanup never touches fixture Input/Vault data.
- Off by default in CI, production, and normal test invocations. A run with no
  Lab observer must have the same behaviour and archival results.

## Acceptance tests before UI implementation

1. **Off means absent:** route and API return 404 in production even if flag is
   set; flag off means absent in development. No diagnostic file is opened.
   Unauthorized remote access cannot retrieve run records.
2. **Genuine three-file run:** harness opts in, publishes three files through
   real PR #89 Operations/OCFL, and produces snapshots whose persisted counters
   match a separate SQL witness. The external verifier remains independent.
3. **Run isolation:** two disposable runs with distinct fixture databases and
   overlapping source names never combine identities, counts, events or files.
4. **Worker death:** kill a real worker during the PR 1/2 failure barrier. Lab
   shows actual interrupted/retried work (or unknown until sampled), never
   prematurely claims success; observer failure does not stop recovery.
5. **Event truth:** real EventBus dispatch is captured with its metadata; a
   simulated dropped/duplicated diagnostic observation is flagged; an event
   dispatched before a rollback never becomes “committed” without separate
   evidence. Typed non-emission cases are tested where required.
6. **Negative control:** corrupt or omit a published file while all Assignments
   are complete. The Lab reports **verification failed**, not a green run.
7. **Capacity:** for 101 files the recorded peak unresolved capacity is <=100.
   Failed pending-retry Assignments are not mislabelled as running.
8. **Privacy and boundedness:** no DSN, password, absolute source paths or raw
   bytes in API/NDJSON. Run/event list pagination is bounded, reconnect cursors
   cannot leak another run, and slow clients cannot cause unbounded memory use.
9. **Disabled-mode equivalence:** confirm the observer does not alter archived
   object IDs, version counts, Job admission decisions, timing-dependent
   correctness or final output bytes; no event-listener exception breaks
   Operations.
10. **Manual UX check:** watch an actual 101-file run with the browser open,
    trigger a deliberate admission interruption, reload the page, and verify
    that snapshot reconciliation and event markers still make sense.

## Implementation split

Make the Lab in its **own dev-tools PR**, after PR #89 is merged; keep the
directory-round-trip implementation and core acceptance contracts untouched.

1. Define a small typed `LabObservation` DTO/schema and an opt-in observer
   attached to test fixture configuration. Validate actual run correlation and
   safe file output **before** building any UI. No new business records.
2. Expose a read-only snapshot/event API through Tempest, with runtime gates
   and tests for disabled/unauthorized access.
3. Build a responsive, minimal page: KPI strip, timeline, event inspector,
   phase/verification status. Use the installed Tempest view stack rather than
   introducing a frontend build system for a debug page.
4. Add native EventManagement dispatch observations and failure-path tests,
   clearly separating observed events from persisted facts. Start with
   the lifecycle types that already make semantic sense.
5. Validate against real 3 and 101-file runs. Optionally run with the
   100/101/1,000 stress suite on an explicitly opted-in local invocation;
   **do not** make Lab observability mandatory for stress CI.

Review the actual current `dev` HTTP/view setup and installed Tempest docs
before implementation. The repository also has older frontend/event work on
other branches; do not import those assumptions into the current Core.

## Definition of done

- I can open `/dev/microtext` locally during an opted-in actual run and see
  timely **truthful** progress without opening a terminal.
- The page survives refresh/reconnection and can inspect a recent completed run.
- A missing event, stale observer or completed-but-corrupt file is obvious.
- Production contains no reachable Lab endpoints, the archive is unaffected
  when Lab is disabled, and the test suite stays authoritative.
- No new queue, generic telemetry platform, production event log or
  irreversible migration was introduced just for a development dashboard.
