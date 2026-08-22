# Follow-up task prompts — 2026-07-15

These tasks follow the broadcast type characterization, request diagnostics, and stash-slug removal.
They are intentionally independent so separate agents can take them without sharing a worktree or relying
on unstated decisions.

## Established decisions and evidence

- The routine browser path preserves a selected `jellyfin` type in both preview and creation. Keep the new
  Playwright regression test; do not rewrite type selection without a new reproducible failure.
- Reuse `MediaItemState::DownloadPending` as the queued state and present it as **Queued**. Do not add a
  second persisted state with the same meaning.
- Stash slugs are removed. Broadcast slugs remain because generated output naming still uses them.
- API responses now expose `Server-Timing` and `X-Stashd-Request-Id`; slow/failing API calls produce safe
  correlated diagnostics in both the browser console and application log.

## Shared rules

- Read `AGENTS.md` and the relevant canonical development/architecture docs
  before working.
- Use the Lerd/custom-container workflow from `AGENTS.md`; do not run host PHP tooling.
- Preserve the `Stash -> Vault -> Broadcasts` boundary. Vault assets are canonical; broadcasts are
  disposable generated views.
- Use `StateTransitionService` for state changes and commands/jobs for long-running work.
- Keep public errors and logs secret-safe. Never log request bodies, query strings, tokens, or internal
  filesystem paths.
- Start with focused tests, then run `composer lint`, `npm run typecheck` when TypeScript changes, and
  `composer test:parallel` before handoff.

## Suggested order

| Task | Effort | Priority | Can run independently | Outcome |
|---|---:|---:|---|---|
| [T1](T1-podcast-episode-duration.md) | Small | High | Yes | Podcast XML includes known episode duration |
| [T2](T2-retry-queued-feedback.md) | Medium | High | Yes | Retry actions visibly queue work and report errors |
| [T3](T3-delete-broadcasts.md) | Medium-large | High | Yes | Broadcasts can be safely deleted without touching Vault assets |
| [T4](T4-runtime-mercure-diagnosis.md) | Investigation | High operationally | Yes | Reproduce and isolate intermittent server/restart failures |

T4 should start early in a separate lane because its findings may explain the reported sluggishness, but it
must not absorb unrelated UI performance changes. T1 is the smallest product fix; T3 has the largest safety
surface.
