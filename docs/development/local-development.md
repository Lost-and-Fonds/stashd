# Local development

Stashd is Docker-first and uses PostgreSQL as its normal database. SQLite is
the supported import/upgrade source, not the normal production database.

The repository is also configured for Lerd, the local Podman-based PHP
environment. Use the Lerd project/runtime tooling for PHP, Composer, workers,
services, and diagnostics rather than assuming a host PHP installation. Check
the site's current configuration before changing services or workers.

Common project checks are:

```bash
composer lint
composer test:static
composer test
composer test:docker-smoke
```

Use the focused test command appropriate to the change before running the full
suite. Fresh container builds are required for Docker/runtime changes; do not
reuse stale images when validating plugin or runtime registration.
