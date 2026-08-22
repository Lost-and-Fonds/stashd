# Development workflow

Read the root [`AGENTS.md`](../../AGENTS.md) first, then the relevant
architecture and domain documentation. Treat the current task as the scope:
do not continue into adjacent roadmap work without an explicit request.

Before editing:

1. Check `git status --short` and leave unrelated changes untouched.
2. Trace the existing implementation and its tests before choosing an
   extension point.
3. State a short plan, affected files, and checks for non-trivial work.

During editing, prefer existing project patterns, explicit names, and small
reviewable diffs. Do not add abstractions for hypothetical providers or move
secrets into ordinary settings, logs, URLs, or job payloads.

After editing, run the narrowest relevant test first, then the broader suite
required by the affected boundary. Use `git diff --check`, review the diff,
and report what was actually verified. Commit related changes together and do
not mix independent feature work with cleanup.

Provider behavior and protocol fixtures belong to provider packages. Core
tests should use provider-neutral fixtures and assert generic lifecycle,
capability, persistence, and security behavior.
