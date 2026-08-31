# Local development

Stashd is Docker-first and PostgreSQL-only.

The repository is also configured for Lerd, the local Podman-based PHP
environment. Use the Lerd project/runtime tooling for PHP, Composer, workers,
services, and diagnostics rather than assuming a host PHP installation. Check
the site's current configuration before changing services or workers.

Common project checks are:

```bash
composer lint
composer analyse
composer test
composer test:docker-smoke
```

Use the focused test command appropriate to the change before running the full
suite. Fresh container builds are required for Docker/runtime changes; do not
reuse stale images when validating plugin or runtime registration.

## Sibling plugin checkouts

The production dependency graph stays on released Composer packages. For an
explicit local SDK/provider development session, add temporary global path
repositories from `core/`:

```bash
composer config repositories.local-api \
  '{"type":"path","url":"../plugin-api","options":{"symlink":true,"versions":{"stashd/plugin-api":"0.1.0"}}}'
composer config repositories.local-sdk \
  '{"type":"path","url":"../plugin-sdk","options":{"symlink":true,"versions":{"stashd/plugin-sdk":"0.1.0"}}}'
composer config repositories.local-jellyfin \
  '{"type":"path","url":"../plugins/jellyfin","options":{"symlink":true,"versions":{"stashd/jellyfin":"0.1.2"}}}'
composer config repositories.local-plex \
  '{"type":"path","url":"../plugins/plex","options":{"symlink":true,"versions":{"stashd/plex":"0.1.2"}}}'
composer update stashd/plugin-sdk stashd/jellyfin stashd/plex --with-all-dependencies
```

Do not commit the resulting `composer.json` or lockfile changes. Remove the
overrides when the session ends with `composer config --unset
repositories.local-<name>`; normal installs then use the released packages
again.
