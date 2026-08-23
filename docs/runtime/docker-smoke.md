# Docker Smoke Test

The Docker smoke test builds the Stashd image, starts a disposable container, and exercises the core runtime path:

- container boot and `/health`
- PostgreSQL migrations
- storage root creation
- supervisord-managed FrankenPHP, worker, and scheduler
- authenticated system health
- fake-provider preflight and create-from-preflight
- fake download into the Vault
- filesystem broadcast rebuild/verify
- `jellyfin_series` rebuild with `tvshow.nfo`
- `podcast` rebuild, public `GET /b/{broadcastToken}/feed.xml`, public episode media route (full fetch + a `Range` request → `206`), and a non-revealing `404` for an unknown item token
- auto-generated `SIGNING_KEY` persisted under `/data/.env` (verified unchanged across both restart and full container recreation), and the operator-supplied `SIGNING_KEY` override path

Run the full build-and-smoke path from the repo root:

```bash
composer test:docker-smoke
```

After the image has built once, reuse it for faster local iterations:

```bash
STASHD_SMOKE_SKIP_BUILD=1 composer test:docker-smoke
```

or:

```bash
composer test:docker-smoke:no-build
```

Some local PHP wrappers cannot see Docker or Podman. In that case, run the script directly:

```bash
tests/docker/smoke.sh
STASHD_SMOKE_SKIP_BUILD=1 tests/docker/smoke.sh
```

The image uses PHP 8.5. The `uri` extension is bundled and already loaded in `php:8.5-cli-bookworm`, so the Dockerfile verifies it with `php -m` instead of compiling it with `docker-php-ext-install`. The Composer `ext-uri` requirement remains in place.
