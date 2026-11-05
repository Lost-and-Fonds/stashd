# Filesystem stress: worker lifecycle checkpoint

This is **Checkpoint A only**, awaiting architectural review in [PR #96](https://github.com/Lost-and-Fonds/stashd/pull/96).
It is not the completed stress experiment or a preservation-performance demonstration.
The preview, existing command edits, independent preservation witnesses, admission and
Broadcast behaviour are unchanged.

## Boundaries exercised

- `OperationsWorkerPool` uses Tempest's `ProcessExecutor`, `PendingProcess` and
  `InvokedProcess` to own a fixed set of continuous `operations:work` consumers.
  A new optional `--notify-ready` receipt comes from Messenger's native
  `WorkerStartedEvent`. Readiness means availability, not busy execution.
- The caller supplies the command/environment and owns completion, cancellation
  and disposable resources. No per-file dispatch, retry engine, admission policy,
  restart policy or terminal code lives in the pool.
- Startup failure unwinds acquired children. Shutdown signals all workers before
  waiting on a shared grace deadline, escalates uncooperative processes and reaps
  every child. An unexpected exit is a run failure, including exit code zero.
- The test-only lifecycle probe initializes a restricted disposable database and
  Vault, then executes two real discovery Jobs. A PostgreSQL trigger/advisory lock
  establishes that a handler really is executing before interruption or failure.
  The same worker identities survive task transitions and an empty queue both
  before and between Jobs. Nothing here proves Asset bytes or parallel preservation.
- The probe will not dispose its database/storage unless worker shutdown is
  confirmed. Tests independently check `/proc` for workers and renderers,
  callback ownership, preserved signal handlers and observer-session usability.
  A separate administrator session confirms that both the database and role are gone.

## The Prompts failure discovered by the spike

The installed `laravel/prompts` is **v0.3.26**. Its `Task::run()` temporarily
replaces SIGINT with `exit()`, forks the renderer and does not explicitly reap it.
The renderer inherits PHP objects, including PDO connections and Symfony Process
handles. Normal PHP shutdown in that renderer can close the parent's PostgreSQL
session and run destructors for processes it does not own.

An initial lifecycle adapter masked SIGINT/SIGTERM around `parent::run()`. The
real controlling-terminal Ctrl+C test exposed that this is insufficient:
**PHP's `pcntl_signal()` unblocks the signal being registered.** The observer
connection closed before cleanup, and the probe retained an incomplete outcome.
The failed probe's worker/renderer PIDs were independently confirmed absent before
its specifically identified disposable database and marked workspace were removed.
No working database or Vault was used for that recovery.

`FilesystemStressTask` therefore overrides the **process lifecycle**, not the
terminal renderer. It uses Laravel's unchanged `TaskRenderer`, logger protocol, IPC
message handling, static rendering, sub-labels and collapsing summaries:

1. Leave the owner's SIGINT/SIGTERM handlers in place. The caller sets a
   cancellation flag and checks it at safe points.
2. Block cancellation across the fork without subsequently replacing those
   handlers. Only the owner unmasks cancellation before running the callback.
3. Terminate renderer copies without running inherited resource destructors,
   including when a renderer throws, and explicitly reap each renderer.
4. Treat unexpected renderer death as failure, even if its application Job completed.

The small `FilesystemStressTaskLogger` write guard converts a broken IPC pipe into
an exception rather than leaking the stock logger's PHP notices into the terminal.
It does not draw anything or change the message protocol.

There is no new terminal drawing implementation or fullscreen UI.
The approved `FilesystemStressPreviewCommand` remains unchanged and available.

### Exact v0.3.26 coupling

The public `Laravel\Prompts\task()` function constructs `Task` and calls `run()`;
it offers no way to retain the owner's cancellation handler or own/reap the fork.
Its immediate `exit()` can bypass the owner's `finally` cleanup. Restoring the
handler inside the callback is too late for the renderer copy made before it.

Only these methods are overridden:

| Override | Responsibility retained or changed |
| --- | --- |
| `Task::run(Closure): mixed` | Retains preparation, callback-in-parent, socket protocol and reset timing; replaces signal/fork/termination ownership. |
| `Prompt::getRenderer(): callable` | Returns the unchanged `Themes\Default\TaskRenderer`. The theme registry uses the concrete class name, so a Task subclass needs this mapping. |
| `Support\Logger::write(string, ?string): void` | Checks the full socket write, retaining the inherited `prefix()` encoding and all public logger methods. |

`FilesystemStressTask` directly uses protected `Task::$pid`, `$socket` and
`$identifier`, and public `$limit`, `$interval`, `$count`, `$finished`.
It calls inherited `recalculateMaxStableMessages()`, `receiveMessages()`,
`resetTerminal()` and `renderStatically()`, plus Prompt's
`capturePreviousNewLines()`, `hideCursor()`, `render()`, `terminal()` and `output()`.
The inherited renderer and reset use the existing log/stable-message buffers,
sub-label, previous frame and `keepSummary` state; none are reimplemented here.
The logger subclass relies on its protected `$socket` and `prefix()` method.

IPC is the library's newline-delimited `{identifier}_{type}:{content}` protocol
(`label`, `sublabel`, `success`, `warning`, `error`, `partial`, `commitpartial`,
`reset`), with untyped lines for logs. Success sends
`{identifier}_reset:{originalAsync ? 1 : 0}` and retains the library's two-frame
wait. `receiveMessages()` invokes the inherited terminal reset/completion logic.
Failure resets in the owner with `success: false` and its saved SIGINT handler.
This is not a new acknowledged-delivery protocol: renderer suspension or a very
slow terminal may still delay/drop a final frame. The PTY checks establish the
observed one-summary-per-completed-task behaviour, not an arbitrary-load guarantee.

The owner records/restores async-signal mode and its original signal mask. It
never replaces its SIGTERM handler; the failure reset reinstalls the saved SIGINT
handler. The renderer inherits blocked SIGINT/SIGTERM and restores default SIGHUP
termination only in its own process. Its loop cannot return into caller code:
a throwable leaving it triggers self-SIGKILL in `finally`, without PHP shutdown.
The library's success reset may restore default SIGINT in the renderer; that is
OS termination too, not PHP's `exit()` path. Workers and the observer remain
owned by the original process. Cancellation handlers must set a flag rather than
throw/reenter teardown, and callbacks must poll it; blocking work is not preempted.

In the owner's `finally`, `stopRenderer()` copies the PID and sets the inherited
property to zero (so `Task::__destruct()` cannot later signal a recycled PID),
closes its socket endpoint, sends SIGHUP, and waits for that exact child with
`pcntl_waitpid()`, retrying EINTR. Checkpoint B bounds this with nonblocking polls:
one second after SIGHUP, then SIGKILL and one further second to confirm reaping.
Failure to confirm reaping is an exception; it never waits indefinitely. SIGKILL
is accepted only when requested by that escalation, not for unexpected renderer death. A checked IPC
write failure or an unexpected wait status (including renderer SIGKILL) fails the
task; a callback error is preserved as the previous exception if cleanup also
fails. Detection is at the next logger write/task exit, not a separate continuous
renderer-health monitor. The current expected status set is SIGHUP/SIGINT/SIGTERM;
an externally injected one of those signals can be indistinguishable from normal
termination. No automatic worker restart or fixture disposal follows renderer death:
the owner unwinds, stops/reaps the pool, then disposes resources only on confirmation.

These are version-specific assumptions, not a stable public Prompts extension API.
`composer.json` pins **`laravel/prompts: 0.3.26`**, and the lock pins reference
`2d1e9bf6bab8d3f383c254db7f2b4ea8816d55e0`. The contract test verifies that
version/reference and SHA-256 of `Task.php`, `Prompt.php`, `Support/Logger.php`
and `Themes/Default/TaskRenderer.php`; an upgrade must review/update those guards
and rerun real PTY cancellation, renderer-death and resource-ownership tests.
The spike assumes Linux/POSIX, `pcntl`, `posix`, Unix socket pairs and Python 3 for
PTY tests; it does not implement Prompts' missing-extension fallback on Windows.

### Preferable upstream change

The smallest useful upstream API is an opt-in Task mode to preserve the caller's
signal handlers (for example `manageSignals: false` on `task()`/`Task`), together
with a library-owned renderer lifecycle that blocks terminal signals across fork,
never exits through PHP shutdown in the child, and explicitly terminates/reaps it
in `finally`. Child-only reset must not restore the owner's handler policy.
Checked logger writes should report renderer loss. With those guarantees the
local `run()` and logger overrides could disappear while keeping the exact UX.
A handler option alone is insufficient: inherited child destructors and unreaped
renderers would remain. This PR does not depend on an upstream contribution.

### Framework choice and maintenance boundary

Inspected the installed Tempest process documentation, `GenericProcessExecutor`,
`InvokedSystemProcess`, `Pool` and `InvokedProcessPool`. The Operations pool uses
those facilities, retaining individual handles so partial startup can be unwound.
It does not introduce another raw `proc_open` worker supervisor.

Tempest's process API does not provide an extension hook into Prompts' in-memory
renderer fork. The installed `Task` likewise provides no hook to replace its
SIGINT/fork ownership policy. Reusing `parent::run()` failed a real PTY test.
The small presentation-side lifecycle override is the concrete exception; using
Tempest prompts instead would discard the approved UX. Raw PDO/advisory locks in
the test remain independent observations and fault controls in an identity-checked
disposable database, not new application persistence.

**Review this adapter before building the vertical slice.** It depends on protected
Prompts lifecycle methods. Revisit it whenever Prompts changes, or replace it with
an upstream lifecycle hook/fix once equivalent cancellation and reaping guarantees
are available. A pool-owner PID check alone does not stop inherited library
destructors. SIGKILL of the owner, machine failure and fatal resource exhaustion
still cannot promise cleanup; this checkpoint does not claim otherwise.

## Validation and retained evidence

Fresh-lock publication validation: **18 tests / 239 assertions passed**, including
the original **14 lifecycle/process tests / 223 assertions** and four version/source
contract cases. They cover:

| Scenario | Workers | Terminal | Observed result |
| --- | ---: | --- | --- |
| Successive real Jobs and empty queues | 1 | 80 columns | Same worker; completed Jobs; confirmed cleanup |
| Successive real Jobs and empty queues | 4 | 40 columns | Same four ready consumers; confirmed cleanup |
| Partial startup | requested 4, acquired 2 | 80 columns | Failed; acquired workers reaped before disposal |
| Real terminal Ctrl+C during active handler | 4 | 40 columns | Failed/cancelled; confirmed cleanup |
| Process-group SIGTERM during active handler | 4 | 80 columns | Failed/cancelled; confirmed cleanup |
| SIGKILL of the executing worker | 1 | 80 columns | Failed; remaining resources cleaned |
| Callback exception during active handler | 4 | 80 columns | Failed; confirmed cleanup |
| Renderer SIGKILL during active handler | 4 | 80 columns | Failed; observer/worker ownership preserved |
| Unattended JSON | 4 | No terminal | Same execution; parseable undecorated JSON |

Process-level tests additionally exercise an actual launch exception, forced
termination after ignored SIGTERM, invalid count rejection, owner-PID enforcement
and continued cleanup attempts after an individual stop fails.

**24 focused Operations regressions / 391 assertions passed**: departmental
delivery, fencing/concurrency, Jobs and admission atomicity. The first regression
run exposed the existing PHP 8.5 collector deprecations. The clean rerun used the
existing private `tmp/filesystem-stress-php-ini` override, preserving normal ini
directories; no global Lerd capture setting changed.

All publication tests above were rerun in a disposable HEAD-plus-checkpoint source
export with a fresh `composer install` (135 packages). Its minimal lock adds Prompts
only: **one install, zero updates/removals**, with every existing package record
unchanged. Earlier broad, uncommitted Composer updates (including Mago 1.59.0,
Pest 5.3.5 and PHPUnit 13.4.5) are unnecessary and excluded. Validation here uses
locked Mago **1.57.0**, Pest **5.3.2** and PHPUnit **13.4.2**. The original working
Composer files and command rewrite remain byte-for-byte preserved; the minimal
Composer blobs are staged directly without replacing those files on disk.

Changed-file formatting/lint and scoped analysis of the five application files
pass. `composer validate --no-check-publish` passes with existing development-ref
warnings. **`composer qa` was run and is not green**: formatting/lint ran, then
Mago 1.57.0 analysis stopped on the two pre-existing preview `str_repeat` errors;
the full parallel suite was therefore not reached. Its autoformat/autofix changed
only the disposable export's preview; those changes are not copied back or staged.
Full round-trip QA remains required before final review.

### Classification of the nine previously reported Mago errors

Controls use the **same Mago 1.59.0 and installed vendor declarations** to avoid
confusing source changes with analyzer/dependency changes: (1) HEAD source,
(2) HEAD plus the earlier uncommitted command rewrite, (3) HEAD plus Checkpoint A
without that rewrite. Counts are respectively **5, 9, 5 errors**. The error sets
in controls 1 and 3 are identical: **zero Checkpoint A errors**. Six help-level
findings and two warnings also remain identical. Line numbers below are 1-based
in the unformatted source (the JSON span lines are zero-based).

| Classification | File and line | Diagnostics |
| --- | --- | --- |
| Pre-existing in HEAD | `app/Integration/AcquisitionCoordinator.php:92` | Two `mixed-array-index` errors indexing state counts with `mixed`. |
| Pre-existing in HEAD | `app/RecordsAndArchives/PreservationTransaction.php:651` | One `mixed-array-index` error indexing retained-object pins with `mixed`. |
| Pre-existing in HEAD | `app/Development/FilesystemStressPreviewCommand.php:633–634` | Two `possibly-invalid-argument` errors: `str_repeat()` counts may be negative. |
| Earlier uncommitted rewrite, excluded | `app/Development/FilesystemStressCommand.php:38` | One `possibly-false-argument`: `getcwd()` may return false for `text()`'s default. |
| Earlier uncommitted rewrite, excluded | `app/Development/FilesystemStressCommand.php:64` | `template-constraint-violation` and `possibly-invalid-argument` for `progress()` steps of `int|string`. |
| Earlier uncommitted rewrite, excluded | `app/Development/FilesystemStressCommand.php:65` | One `mixed-method-access` on `label()`. |
| Checkpoint A | — | None. |

These are left unresolved rather than widening this publication into cleanup.

JSON evidence and raw PTY captures are retained locally in
`tmp/worker-pool-checkpoint-a/`. Reports include code/lock hashes, PHP/Prompts
versions, actual worker/renderer/backend identities, completed Job receipts,
cleanup ordering and elapsed durations. These are uncontrolled lifecycle timings,
not performance comparisons or sampled active-handler maxima.
Each retained run has a unique suffix, and PTY signal controls require the current
owner PID before acting; stale evidence cannot trigger an early cancellation.
`SHA256SUMS` indexes retained artifacts. `initial-unsafe-task-cancellation.json`
separately records the original failure and later targeted cleanup, explicitly
transcribed from tool results rather than attributed to the corrected probe.
Its original index SHA-256 is
`b5f7c8b8b53f5c1181d44339ab5ae1d55a61031427d5370c39d480669534a1a4`.

Fresh-lock evidence is separate, under
`tmp/checkpoint-a-publish/review-tree/tmp/lifecycle-evidence/`; validation logs and
the three `*-control-analysis.json` outputs are in `tmp/checkpoint-a-publish/`.
`PUBLICATION-SHA256SUMS` there indexes the logs/control results and new JSON/PTY
captures (PTY, not browser). Its SHA-256 is
`8b68da7dbbc522ab9ae6e31a67da869123506c3185c0e135212e0eea1f402559`.
No generated recording, environment file, fixture administrator config or scratch
export is part of the commit.

Rerun through the site's Lerd execution environment:

```sh
STASHD_WORKER_POOL_EVIDENCE_DIR="$PWD/tmp/worker-pool-checkpoint-a" \
  vendor/bin/pest tests/Unit/OperationsWorkerPoolTest.php \
  tests/Unit/FilesystemStressTaskContractTest.php \
  tests/Acceptance/Integration/OperationsWorkerPoolLifecycleTest.php
```

Use the existing private PostgreSQL fixture setup described in
[`filesystem-stress-cli.md`](filesystem-stress-cli.md), including the ignored
`tmp/transaction-projection-admin.json`; do not point these tests at a real Vault.
The destination must already exist. Without the environment variable, tests use
private disposable report directories. The subprocess probe is test support and
uses the parent test's PHPUnit configuration for the existing assertion-based
fixture witnesses; it is not a new end-user command.

## Approved next boundaries, not implemented here

- Checkpoint B removes the outstanding-acquisition ceiling entirely, with **no
  replacement limit or configuration**. Pagination bounds memory, not the number
  of durable Assignments. Admission may outrun consumers and grow queues. Tests
  must cover more than 100 outstanding Assignments and concurrent consumption.
- Checkpoint B integrates the first real preservation slice and demonstrates
  100/1 and 100/4, including overlapping actual handler execution. Four ready
  workers in this lifecycle spike are not that demonstration.
- Checkpoint C completes all nine real tasks and the independent round trip,
  demonstrates 1,000/4 and runs final QA. No 10,000-file mandatory CI run is added.
- Broadcast remains serial and destination-locked throughout #96. The next
  dedicated architecture milestone is [parallel Broadcast #97](https://github.com/Lost-and-Fonds/stashd/issues/97),
  with its own design and acceptance-first contracts. Add measured publication,
  retry, contention and inode-stability findings there after the complete experiment.

Implementation stops at Checkpoint A for architectural review.

Checkpoint A was approved at `cf5f552`. The bounded renderer shutdown above and
`OperationsWorkerStartupException` are its requested follow-up corrections:
startup cleanup failure retains the original startup cause as `getPrevious()` and
the separate cleanup exception as `cleanupFailure`. Their focused tests include
ignored SIGHUP, a SIGSTOPped renderer, and simultaneous startup/stop failures.
See the [preservation experiment](filesystem-preservation-experiment.md) for Checkpoint B.
