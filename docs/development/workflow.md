# Development workflow

Keep changes in the repository that owns the behavior. Trace the relevant
production path and its boundaries before editing.

Use the repository's available lint, static-analysis, and build commands where
applicable. Stashd currently has no automated test suites or legacy test
harnesses.

Provider protocols belong to provider packages. Core owns generic lifecycle,
Vault authority, persistence, and runtime integration.
