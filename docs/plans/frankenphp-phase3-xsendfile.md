# FrankenPHP Migration — Phase 3: X-Accel-Redirect podcast episode serving

Phases 1–2 (classic mode, Mercure) shipped in PR #17. This is Phase 3 from
`docs/plans/frankenphp-migration.md`'s original scoped plan (that doc itself
was never committed — phase work landed directly instead — but its Phase 3
section is the source of truth for intent; this doc fills in current-repo
specifics).

## Goal

Replace `PodcastEpisodeController`'s hand-rolled Range/streaming logic with
`X-Accel-Redirect` to Caddy's `file_server`. Caddy handles Range/HEAD/If-Range
natively; PHP does auth + header selection only. This is where the historical
OOM-crash code path (`docs/plans` memory: `GeneratorFileStream`/streamed
1 MiB chunks) is deleted for podcast episodes specifically — the RoadRunner
version is already gone (Phase 1), but the app-level byte-range machinery
that used to feed it is still here and still hand-rolled.

## Current state (verified against post-pull main)

- `app/Broadcasts/PodcastEpisodeController.php`: does token auth + asset
  selection, then parses `Range` itself (`PodcastEpisodeByteRange`) and
  streams via a private `streamFile()` generator in 1 MiB chunks.
- `app/Broadcasts/Podcasts/PodcastEpisodeByteRange.php`: single-range RFC 7233
  parser (start-end, open-ended, suffix). Full delete target.
- `docker/Caddyfile`: has `php_server` + `mercure` block, no X-Accel wiring
  yet.
- `STASHD_MEDIA_PATH` — container path `/media` (`docker-compose.yml`,
  `docker-compose.dev.yml`), resolved into `StashdConfig::mediaPath` via
  `app/Config/stashd.config.php:20`; `vaultPath()` is `{mediaPath}/vault`.
  The Caddyfile's `intercept` root must match `vaultPath()` exactly.
- Tests: `tests/Feature/Phase5CPodcastEpisodeRouteTest.php` (859 lines) —
  large chunk is Range-header cases (mid/full/suffix/open/beyond-EOF/
  malformed/multi-range/bad-token). `tests/docker/smoke.sh` fetches an
  episode and checks body bytes + `Content-Length` + `Accept-Ranges` (no
  Range request in smoke today — Phase 3 adds one per the original plan).

## Work

Per FrankenPHP's own docs (`docs/x-sendfile.md` upstream), `X-Accel-Mapping`/
`X-Sendfile-Type` request headers are only consumed by Symfony HttpFoundation
to translate an absolute path into a relative one — Tempest isn't
HttpFoundation-based, so the controller computes the Vault-relative path
itself and neither header is needed.

1. **`docker/Caddyfile`**: add an `intercept` block matching response header
   `X-Accel-Redirect`, rooted at `{$STASHD_MEDIA_PATH:-media}/vault` (not the
   whole media path — least privilege, matching the controller's Vault-only
   contract), that rewrites the request to the header value, forces `GET`,
   strips `X-Accel-Redirect` before the response reaches the client, then
   runs `file_server`.
2. **`PodcastEpisodeController::episode()`**: after selection + path/
   readability checks (unchanged — token auth and non-revealing 404s stay
   exactly as they are), return a bodyless `Ok` with `Content-Type` and an
   `X-Accel-Redirect` header set to the asset path made relative to
   `StashdConfig::vaultPath()`, with each path segment `rawurlencode`d.
   Reject (non-revealing 404) if the asset path is ever outside the Vault
   root — defense in depth, since every selected asset should already live
   there. Delete `streamFile()`, `PodcastEpisodeByteRange` usage, HEAD
   special-casing (Caddy's forced-GET + the outer HTTP layer's own HEAD
   truncation cover it), `RANGE_READ_CHUNK_BYTES`, and the unused `Request`
   parameter (nothing in the method needs it anymore).
3. **Delete** `app/Broadcasts/Podcasts/PodcastEpisodeByteRange.php` — no
   dedicated test file existed for it.
4. **Rewrite `Phase5CPodcastEpisodeRouteTest.php`**: keep every auth/token
   test as-is (they only assert status codes). Change the two "valid token"
   tests to assert `X-Accel-Redirect`/`Content-Type` and a null body instead
   of streamed bytes. Delete the 8 Range-behavior tests outright — Range
   parsing no longer exists in PHP for this route, so there's nothing left
   in-process to test; that behavior is proven by `tests/docker/smoke.sh`
   hitting the real container instead.
5. **`tests/docker/smoke.sh`**: no changes needed. It already does a real
   `curl -H "Range: bytes=0-3"` against the running container (port 18474)
   and asserts `206` + `Content-Range` — since it exercises actual Caddy,
   not PHP, this becomes the correctness proof for `file_server`'s Range
   handling post-migration for free.
6. ~~Local dev without Caddy in front~~ — **not needed**: `lerd site list`
   shows the `stashd` site as `custom_container: true` proxying to the real
   Docker container on `:8474`, i.e. local dev already runs the same
   Caddyfile/FrankenPHP image. No separate fpm+nginx path exists for this
   app, so no fallback to build.

## Non-goals / unchanged

- Token auth, non-revealing-404 behavior, path-token-not-query-token rule:
  untouched (see `docs/development/security.md`).
- `PodcastEpisodeUrlBuilder`, feed XML generation: untouched.
- Video broadcasts / other media kinds using the same controller: same
  treatment, no kind-specific branching needed.

## Verification

- `composer test:static`, `composer test:feature -- --filter PodcastEpisode`.
- `composer test:docker-smoke` (or `:no-build` while iterating) — must show
  the new 206 Range assertion passing through actual Caddy, not PHP.
- Manual: play/seek a podcast episode in a real client against a Docker
  build.
