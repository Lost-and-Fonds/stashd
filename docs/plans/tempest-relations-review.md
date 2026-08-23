# Tempest Relations Review

> **Status (2026-07-03): resolved** by the Tempest-native records slice (`cb5d4b1`, PR #3) — see
> the "Tempest relations audit" entry in `docs/TODO.md`. Relations were proven on PostgreSQL and
> declared on stash/stash-item (`tests/Feature/TempestRelationsTest.php`); replacing the
> ID-driven repository FK-list methods was deliberately rejected. Kept for the original
> evaluation criteria.

This document captures planning notes for evaluating Tempest database relations in stashd.

This document is subordinate to the project-wide Tempest-native default in `AGENTS.md`: use Tempest-native facilities by default when they fit the domain, and keep custom code where stashd has a real domain, security, storage, or product requirement.

The goal is not to adopt relations everywhere by default. The goal is to decide where Tempest relation attributes, eager loading, and relation-scoped queries fit the domain better than the current explicit repository loading pattern.

## Context

stashd currently uses feature-first records plus explicit repositories and services. Foreign keys are stored as scalar columns and related records are loaded manually.

Tempest provides relation attributes such as `BelongsTo`, `HasMany`, `HasOne`, `BelongsToMany`, and through variants. These may simplify common read paths, but relation use must preserve stashd's security, serialization, job, and storage boundaries.

## Initial Principles

- Do not use relations as an excuse to auto-serialize record graphs into API responses.
- Keep public API output behind explicit Resource DTOs.
- Prefer Tempest relations, `with(...)`, and relation-scoped queries where they cleanly express structural data access and reduce repetitive lookup code.
- Use explicit repository/service loading when authorization, token safety, storage verification, or missing-record handling is part of the behavior.
- Treat N+1 query reduction as a first-class reason to consider relations, especially where Tempest can eager-load with `with(...)` or scope relation queries through `$record->query('relation')`.
- Avoid eager-loading broad graphs by default.
- Avoid relation changes in the same slice as record renaming or stronger-type conversions unless the work naturally depends on it.

## Candidate Areas

- `StashInputRecord` belongs to `StashRecord`.
- `StashItemRecord` belongs to `StashRecord`, `MediaItemRecord`, and optionally `StashInputRecord`.
- `MediaItemSourceRecord` belongs to `MediaItemRecord` and optionally `StashInputRecord`.
- `AssetRecord` belongs to `MediaItemRecord`, `BroadcastRecord`, `BroadcastItemRecord`, and optionally another `AssetRecord`.
- `BroadcastRecord` belongs to `StashRecord`.
- `BroadcastItemRecord` belongs to `BroadcastRecord`, `StashItemRecord`, and `MediaItemRecord`.
- `BroadcastTriggerRecord` belongs to `BroadcastRecord`.
- `BroadcastTriggerRunRecord` belongs to `BroadcastTriggerRecord`.
- `JobRecord` optionally belongs to `CommandRecord`.
- `ApiTokenRecord` belongs to `UserRecord`.
- Secret-backed references, such as broadcast tokens and media-server tokens, need extra caution.

## Review Questions

- Which current repository methods only load direct relations by foreign key?
- Which controller/resource assembly paths issue repeated lookups that could become explicit eager-loaded relation queries?
- Can Tempest `with(...)` replace N+1-prone loops in stash detail, vault/detail, broadcast, podcast, or activity views?
- Can `$record->query('relation')` replace ad hoc child-list repository methods while preserving explicit constraints and ordering?
- Which relation candidates are read-heavy and low-risk?
- Which relation candidates are security-sensitive or should remain explicit?
- Does Tempest relation loading work cleanly with stashd's camelCase FK columns?
- Does relation loading work cleanly with custom prefixed string IDs and future typed ID value objects?
- How does relation loading interact with `#[Hidden]` fields?
- Can relations be loaded explicitly per query without changing default record behavior?
- Are there N+1 risks in controllers/resources that relations could reduce?
- Are there places where passing a loaded record is better than either an ID or a relation?

## Suggested First Investigation Prompt

```text
In /home/hazel/Projects/stashd, investigate whether Tempest database relations would simplify selected stashd record loading paths.

Read AGENTS.md first. Use lerd MCP for PHP/composer/vendor commands. Do not run host PHP/composer/vendor binaries.

Goal:
Produce an evidence-based recommendation for where Tempest relation attributes should or should not be adopted in stashd.

Do not implement broad relation changes yet. This is an audit/spike unless the requested task explicitly asks for implementation.

Investigate:
1. Tempest relation docs and vendor behavior for `BelongsTo`, `HasMany`, and explicit relation loading.
2. Tempest eager-loading with `with(...)` and whether it reduces query counts for current stashd read paths.
3. Tempest scoped relation queries through `$record->query('relation')`, including constraints, counts, updates, deletes, ordering, and limits.
4. Whether relations work with stashd's current camelCase foreign key columns.
5. Whether relations work with prefixed string primary keys and `Tempest\Database\PrimaryKey`.
6. How relations behave with `#[Hidden]` properties.
7. How relation loading affects query counts and API/resource serialization risks.

Audit candidate domains:
- Stashes and stash items.
- Broadcasts and broadcast items.
- Vault media items and assets.
- Jobs and commands.
- Auth users and API tokens.
- Secret-backed records only as a cautionary review, not as a first implementation target.

For each candidate relation, report:
- current explicit loading pattern,
- proposed Tempest relation shape,
- expected benefit,
- expected query-count impact,
- whether `with(...)`, `$record->query('relation')`, or explicit repository loading is the best fit,
- risks or hidden behavior,
- tests that would be required,
- whether to adopt now, defer, or avoid.

Keep recommendations pragmatic. Prefer explicit code where it protects security, storage drift handling, or command/job boundaries.
```
