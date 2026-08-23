# T4 — Diagnose intermittent connectivity and Mercure restart failures

## Observed evidence

During the 2026-07-15 migration/verification run:

- The Lerd console path operated on the checkout database, while the active custom container served the
  persistent PostgreSQL database.
- Applying the stash-slug migration to the active database initially hit `database table is locked`.
- After stopping and restarting supervised processes, FrankenPHP repeatedly failed with Mercure Bolt
  `invalid transport: timeout`.
- A full Lerd site/container restart recovered all processes.

These may contribute to the reported intermittent “Couldn't connect to server” behavior, but causality has
not yet been established.

## Scope

This is a diagnosis-first task. Reproduce and characterize before changing code or configuration.

- Capture Lerd site/runtime, worker, and log state before and after:
  - normal requests;
  - process-only FrankenPHP stop/start;
  - full site/container restart;
  - a migration while application processes are active.
- Use `X-Stashd-Request-Id`, `Server-Timing`, browser timing warnings, application logs, and Lerd logs to
  distinguish browser/network, reverse proxy, application, database, and Mercure failures.
- Inspect the relevant `.lerd.yaml`, container/supervisor configuration, `docker/Caddyfile`, Mercure Bolt data
  path, and shutdown/startup lifecycle.
- Confirm which database each supported command path targets. Establish one safe documented command for
  migrations against the active custom-container database.
- Produce a compact reproduction matrix with timestamps, request IDs, expected/actual behavior, and the
  smallest credible root cause.
- If the root cause and fix are local, bounded, and supported by the evidence, implement it with a regression
  check. If it belongs to Lerd or Mercure upstream, stop at a handoff-quality report and local runbook.

## Constraints

- Do not delete or recreate the active database, Mercure data, or user media.
- Do not disable realtime or replace Mercure as a workaround.
- Do not bundle speculative UI optimizations or broad server rewrites.
- Redact cookies, tokens, request payloads, and internal secrets from reports.

## Acceptance criteria

- The process-only restart outcome is repeatable or the report demonstrates why it cannot be reproduced.
- The report identifies the active database path and the safe migration procedure without ambiguity.
- At least one correlated request traces browser duration, server duration, response status, and relevant
  server log entries.
- Any implemented fix survives repeated process and full-site restarts and leaves all supervised processes
  healthy.
- Relevant focused checks and Docker/runtime smoke pass, or every unrun/blocked check is explicitly recorded.

## Findings — 2026-07-15

| Scenario | Result | Evidence |
| --- | --- | --- |
| Full Lerd site restart | Recovered cleanly | FrankenPHP, scheduler, and all three job workers entered `RUNNING` within 10 seconds; no Mercure Bolt error recurred. |
| Normal proxied API request | 403 in 349 ms; no app diagnostic headers | Lerd nginx logged `connect() failed` and `send() to syslog failed` for its missing `lerd-access.sock` on the same request. |
| Lerd watcher | Repeats every minute against an unrelated worktree | It runs dependency installation, then reports `unable to open database file` and missing Lerd `npm`; this is separate from the active application container. |

### Root cause and ownership

The active Stashd custom container is healthy. The reproducible faults are in Lerd's host services:

- nginx is configured to write every access record to `unix:/home/hazel/.local/share/lerd/run/lerd-access.sock`, but that socket is absent. This emits an alert and a failed send for every proxied request.
- the Lerd watcher repeatedly installs dependencies in a stale worktree; that
  environment has neither its expected npm binary nor the active Stashd
  database path.

Neither fault proves a Mercure failure. The earlier `invalid transport: timeout` did not recur after the full site restart, so no Stashd/Mercure code change is justified from this evidence.

### Active database and safe migration command

The active custom container uses its mounted `.env` to select the PostgreSQL database. The checkout/host CLI may target a different database.

Run migrations only inside the active container:

```sh
podman exec lerd-custom-stashd php tempest stashd:boot
```

Do not run a host/standalone PHP migration command against this site until Lerd makes its database target explicit.

### Handoff

Repair the missing Lerd access-log socket and stop/fix the runaway worktree watcher in Lerd. Then repeat one authenticated browser request and compare browser duration, `Server-Timing`, `X-Stashd-Request-Id`, application log, and nginx access log. No application change was made by this diagnosis.
