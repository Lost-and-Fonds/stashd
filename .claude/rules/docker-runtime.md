---
paths:
  - "Dockerfile"
  - "docker-compose.yml"
  - "docker/**/*"
  - "scripts/**/*"
  - "tests/docker/**/*"
  - ".env.example"
---

# Docker and runtime rules

Stashd is Docker-first homelab infrastructure.

Default user promise:

```text
copy → paste → docker compose up → works
```

## Runtime expectations

- Default port: `8474`.
- No Nginx/PHP-FPM required in the default deployment.
- FrankenPHP (classic mode) serves HTTP; `stashd` console command supports worker/scheduler/serve runtime roles.
- PostgreSQL runs as a compose sidecar on the internal network, with no published ports.
- The PostgreSQL volume, app key/secrets, storage roots, and migrations must survive restart.
- Error messages should be clear and actionable for homelab users.

## Docker smoke is a release gate

Runtime-related changes should consider:

```bash
composer test:docker-smoke:no-build
composer test:docker-smoke
```

Changes touching the database or the entrypoint should also run the SQLite ->
PostgreSQL upgrade gate:

```bash
composer test:docker-upgrade:no-build
composer test:docker-upgrade
```

Docker smoke should cover boot, FrankenPHP, Tempest, PostgreSQL migration, storage roots, health, setup/auth, worker/scheduler, fake provider/download, fake broadcast, SSE, restart persistence, and clean shutdown.

## Avoid

- Requiring any service beyond PostgreSQL. Compose ships it, so the copy-paste
  promise still holds; do not add Redis or RabbitMQ to the required deployment.
- Treating SQLite as a runtime target. It is only the source
  `stashd:import-sqlite` reads when upgrading an older install.
- Hiding permission problems.
- Assuming root runtime.
- Adding Kubernetes-first complexity.
- Breaking PUID/PGID-style homelab expectations without a deliberate decision.
