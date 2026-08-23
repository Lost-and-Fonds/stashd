# Local development

Stashd is Docker-first and PostgreSQL-only.

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

## Sibling plugin checkouts

The production dependency graph stays on released Composer packages. For an
explicit local SDK/provider development session, add temporary global path
repositories from `core/`:

```bash
composer config --global repositories.lost-and-fonds-api \
  '{"type":"path","url":"../plugin-api","options":{"symlink":true}}'
composer config --global repositories.lost-and-fonds-sdk \
  '{"type":"path","url":"../plugin-sdk","options":{"symlink":true}}'
composer config --global repositories.lost-and-fonds-jellyfin \
  '{"type":"path","url":"../plugins/jellyfin","options":{"symlink":true}}'
composer config --global repositories.lost-and-fonds-plex \
  '{"type":"path","url":"../plugins/plex","options":{"symlink":true}}'
composer update stashd/plugin-sdk stashd/jellyfin stashd/plex --with-all-dependencies
```

Do not commit the resulting lockfile changes. Remove the global overrides when
the session ends with `composer config --global --unset repositories.<name>`;
normal installs then use the released packages again.
