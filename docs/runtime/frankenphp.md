# FrankenPHP runtime

Stashd uses [FrankenPHP](https://frankenphp.dev/) (classic, non-worker mode) as the HTTP application server in Docker and production-like environments. PHP-FPM is not required for the default deployment.

## Why classic mode

FrankenPHP's worker mode (persistent PHP processes across requests, like RoadRunner) needs first-party Tempest support that doesn't exist yet ([tempestphp/tempest-framework#2172](https://github.com/tempestphp/tempest-framework/pull/2172) is still a draft foundation PR, with the actual worker application slated for a separate follow-up PR). Classic mode needs none of that: `public/index.php` already boots Tempest for traditional one-request-per-process SAPI, and FrankenPHP (via Caddy) serves it directly — no custom bridge, no per-request state-reset glue.

This also means Stashd previously ran a hand-written RoadRunner↔Tempest adapter (`TempestPsr7Bridge` and friends). That adapter is gone: it existed only because RoadRunner kept workers alive across requests and needed manual request-state resets between them. Classic mode has no such worker to reset.

## The one thing classic mode needs that FPM/worker setups don't

Every request uses the PostgreSQL connection configured by `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD`. There is no file-database pragma or per-request database compatibility middleware.

## Local development

```bash
# Traditional SAPI (single request, useful for quick debugging)
php -S localhost:8474 -t public public/index.php

# Production-like FrankenPHP/Caddy server (requires the `frankenphp` binary
# on PATH; ships in the Docker image, install separately for bare-metal use)
frankenphp run --config docker/Caddyfile
```

## Docker

`docker/Caddyfile` configures Caddy's `php_server` directive: serves anything under `public/` as a static file first (built assets, favicon), falls through to `public/index.php` otherwise. No explicit forbid list is needed — the document root is scoped to `public/`, which never contains `.env`, `.htaccess`, or any PHP file besides `index.php`.

The container entrypoint runs `stashd:boot`, then supervisord starts:

- FrankenPHP (`stashd serve`)
- Job poll loops, one per lane (`stashd worker interactive|discovery|bulk`) —
  interactive jobs (preflight/add-input) never queue behind downloads or
  channel backfills; `stashd worker` with no lane processes everything
  (local dev)
- Scheduler loop (`stashd scheduler`)

See `docker/supervisord.conf.template` and `docker/entrypoint.sh`.

## Generator response bodies

Tempest's stock `GenericResponseSender` only knows how to stream a `Generator` body when it's wrapped in `EventStream` (SSE framing) — any other Generator body (e.g. `PodcastEpisodeController`'s bounded-chunk file reads, used so a hundreds-of-MB episode is never buffered whole in memory) falls through to `echo $body`, which throws, corrupting the response after headers are already sent. `App\Http\Routing\StreamingResponseSender` decorates the framework's `ResponseSender` (via Tempest's `#[Decorates]` mechanism) to stream those chunks directly instead.

## Real-time updates

Replaced the RoadRunner-era polling SSE endpoint with FrankenPHP's embedded Mercure hub (`docker/Caddyfile`'s `mercure` block). `App\System\Event\MercurePublisher` publishes the same five event types over HTTP to `/.well-known/mercure` (from both web requests and out-of-process worker/scheduler CLI roles, which can't use the `mercure_publish()` function). Job events carry a safe job summary (never the job payload); activity events carry the activity resource. Subscribers need a JWT: `GET /api/v1/events/subscription` (behind `RequireAuthMiddleware`) mints one via `AuthService::issueMercureSubscriberToken()` and sets it as the `mercureAuthorization` cookie, scoped to `/.well-known/mercure`. The frontend uses one shared `EventSource`, patches live jobs/activity in memory, and does one authoritative page resync after a reconnect or when a tab becomes visible. It polls only in browsers without `EventSource` support.

## Request diagnostics

Every `/api/v1/` response includes `Server-Timing: app;dur=…` and an `X-Stashd-Request-Id` correlation header. Browser developer tools therefore show both total request time and time spent inside the PHP application. The frontend also writes a console diagnostic for requests taking at least 500 ms, server errors, and requests that fail before any response arrives.

The application log records API requests taking at least 500 ms and all API server failures. Entries contain the request ID, HTTP method, path without its query string, status, and application duration. Search the application log for the request ID shown in a failed-response message or browser console entry; raw request bodies, query strings, and tokenized public routes are deliberately excluded.

## Podcast media

`PodcastEpisodeController` authorizes the tokenized request and returns an internal redirect. Caddy serves the selected Vault asset directly, including range requests, so episode bytes do not pass through PHP.
