# Claude Code entrypoint for Stashd

Read `AGENTS.md` first. It is the canonical repo instruction file.

Use `.claude/rules/` for topic/path-specific constraints and `.claude/skills/` for reusable workflows. Do not load long docs unless the task needs them.

## Always remember

- Stashd is preservation infrastructure: `Stash → Vault → Broadcasts`.
- Vault is canonical; Broadcasts are generated and disposable.
- PHP `^8.5`, Tempest `^3.0`, FrankenPHP, PostgreSQL, Pest, Pint, Docker-first.
- Use existing feature-first `app/` patterns before inventing new structure.
- Keep diffs small and tests honest.
- Be token-efficient: targeted search, relevant files only, no broad repo scans unless requested.
- Never leak raw tokens/secrets in logs, activities, command/job metadata, public APIs, or feed XML.
- Podcast feed and episode URLs use path tokens, never query tokens.

## Startup rhythm

For non-trivial work:

```text
1. Read AGENTS.md.
2. Check git status.
3. Inspect local patterns in the relevant app/ feature folder.
4. State a narrow plan.
5. Edit only the necessary files.
6. Run focused checks, then broader checks if warranted.
```

Use skills when relevant:

```text
/tiny-stashd-task
/narrow-debug
/stashd-slice-plan
/security-token-review
/podcast-route-slice
/broadcast-format-work
/provider-strategy-work
/docker-smoke-triage
/codebase-rescue-audit
/stashd-compact-checkpoint
/preship-e2e-smoke
```
