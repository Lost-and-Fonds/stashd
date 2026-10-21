# Mercure integration spike

Recommendation: **PHP-FPM with a standalone Mercure hub**. The live delivery path
works independently of the PHP HTTP server. Keep FrankenPHP classic as a separate
runtime experiment; there is no measured reason here to adopt it for speed.

This directory is an explicitly installed development fixture, outside Tempest
application discovery. It uses synthetic messages only. It adds no application
publisher, Job schema, event bus, durable operational store, or browser SSE loop
in PHP. The PHP publisher exits after the hub acknowledges the update. Mercure
owns subscriber connections.

## Observed environment, 21 October 2026

| Component | Observed |
| --- | --- |
| Core | `dev` at `9045241` before this spike |
| Tempest | installed and locked v3.19.2 |
| PHP HTTP | FPM 8.5.11 |
| Lerd | 1.37.0, commit `14f05e85cbe4f0dd4437a53fd291177a7ef6605f` |
| Site | `https://core.test`, trusted local HTTPS; nginx 1.31.7 |
| Database | running `postgis/postgis:16-3.5-alpine` on the Lerd network |
| Existing workers | async command monitor active; scheduler inactive |
| Installed FrankenPHP base | `dunglas/frankenphp:php8.5-alpine`; FrankenPHP 1.13.0, Caddy 2.11.7 |
| Embedded modules | `http.handlers.mercure`, `.bolt`, `.local`, and `admin.api.mercure_health`, all v1.0.3 |
| Selected hub | standalone Mercure 0.24.2, Caddy 2.11.3; multi-platform image digest pinned in `Containerfile` |

`site list` initially reported the command monitor stopped; `worker list` and
systemd showed it active. Use worker inventory/systemd for lifecycle checks.
Lerd's status also reported its watcher inactive. Existing nginx, FPM, database,
CLI execution and service management worked without changing that state.

## Architectures evaluated

| Choice | Benefits | Costs / findings |
| --- | --- | --- |
| FrankenPHP classic + embedded Mercure | Module already installed; one application-server container | Hub follows PHP restart; Lerd's Tempest entrypoint does not enable Mercure; custom runtime configuration and 1.0 JWT integration needed |
| FrankenPHP classic + standalone Mercure | Independent hub lifecycle; same publisher/subscriber path | Additional PHP runtime change with no demonstrated delivery benefit |
| FPM + standalone Mercure | Demonstrated with current app; independent restart; normal OCI deployment | One small service, separate health/log/config management |

Standalone does not require a separate hostname or a published host port. nginx
terminates HTTPS and routes exactly `/.well-known/mercure` to port 80 of
`lerd-stashd-mercure-spike` on the existing `lerd` network. FPM and background CLI
processes can publish to that container directly. nginx's supported server-scope
override owns the browser route. PHP never proxies subscriber streams.

The pinned hub is the version offered by Lerd's store, not the embedded module's
version. **0.24.2 uses `mercure.publish` / `mercure.subscribe` JWT claims and the
`mercureAuthorization` cookie. Embedded 1.0.3 uses issuer/audience-bound access
tokens and `authorization_details`; do not reuse this fixture's tokens there.**
Before adoption, select and validate one supported hub/protocol version deliberately.
The current [authorization guide](https://mercure.rocks/docs/concepts/authorization)
describes 1.x; inspect tagged source when reproducing 0.x behavior.

## Local setup

Run from the Core checkout. These commands assume this machine's site name,
Podman network and PHP runtime. Inspect `lerd site list` first on another machine.
No global runtime switch is required.

```sh
podman build -t localhost/stashd-mercure-spike:local dev/mercure-spike
python3 dev/mercure-spike/prepare.py
lerd service add tmp/mercure-spike/service.json
lerd service start stashd-mercure-spike
```

`prepare.py` refuses existing credentials or an existing public fixture. It
creates random independent publisher/subscriber HMAC keys, an unrelated fixture
password, and a tiny ignored `public/mercure-spike.php` entrypoint. Nothing is
installed in the normal app until this explicit step. Local files have mode 0600
under `tmp/mercure-spike`; the directory is 0700. Never commit these files.

Use Lerd's site nginx editor (server scope), or MCP `site` action `nginx_write`,
site `core`, with the contents of [`nginx.conf`](nginx.conf). It is a supported
override, not an edit to generated nginx files. Save a pre-existing override and
merge this exact location into it; this machine had no previous override.
Lerd tests and reloads nginx when saving. No sleep is needed.

Required hub variables, generated in the local service definition:

- `MERCURE_PUBLISHER_JWT_KEY`: random signing/verification key for publications.
- `MERCURE_SUBSCRIBER_JWT_KEY`: separate random signing/verification key for subscriptions.

The fixture also reads `topic`, `hub_url`, `public_url`, and `password` from the
ignored credentials file. It does not add keys to Stashd's `.env`. This is not a
production secret manager: Lerd copies service environment into its service YAML
and quadlet. The generated quadlet is 0644; this machine's home directory is
0700. Local users with access to those files or Podman can read keys. Teardown
must remove both Lerd's service definition and the source credentials.

### Manual demonstration

Open <https://core.test/mercure-spike.php>. Obtain the test password locally:

```sh
python3 -c 'import json; print(json.load(open("tmp/mercure-spike/credentials.json"))["password"])'
```

Submit it through the HTTPS password form in two tabs. It sets a five minute
host-only `Secure; HttpOnly; SameSite=Strict` subscription cookie, restricted to
`/.well-known/mercure`. Reload and sign in again when it expires.

Publish from a **separate PHP CLI process**:

```sh
lerd php dev/mercure-spike/fixture.php publish 'Hello from CLI'
```

Both tabs display the same synthetic message and update ID immediately, while
their network requests stay open as `text/event-stream`. Click Disconnect and
Reconnect to try a new connection. New EventSource instances need an explicit
last-event cursor for replay; native reconnection retains its own cursor.
The automatic proof tests explicit replay separately.

The restricted fixture password is only a local demonstration gate. It provides
no Stashd identity, permissions or production login. Do not expose the fixture
with LAN sharing or a tunnel.

### Repeatable live verification

Use an isolated Chromium profile and a recent Node with built-in WebSocket:

```sh
chromium --headless --disable-gpu --remote-debugging-port=9339 \
  --user-data-dir=/tmp/stashd-mercure-chromium about:blank
# In another shell, from Core:
./bin/test.sh --mercure-spike
./bin/test.sh tests/Acceptance/RecordsAndArchives/VaultBrowserTest.php
```

The live verifier requires the real hub, real trusted HTTPS and real browser;
it has no mocked application internals. **It runs `lerd restart` and restarts the
test hub. Run it with no unrelated Core commands/tests executing.** The PHP
restart also restarts the active async command monitor. The load probe is a
bounded synthetic test, not a capacity benchmark. Reports are ignored files in
`tmp/mercure-spike`. Stop the isolated Chromium process when finished.

## Verification results

The live runs and focused lint results below are development evidence, not a
production readiness assertion.

- Separate PHP CLI publication acknowledged with HTTP 200 and the exact update
  ID; browser EventSource received that ID on two simultaneous subscriptions.
- `text/event-stream` arrived while streams remained open, without waiting for
  disconnect. One observed CLI-start-to-browser receipt was 309 ms, including
  starting Lerd/PHP. This is not pure transport latency or a benchmark.
- Anonymous publish/subscribe, expired JWT, invalid signature, subscriber-as-
  publisher, and publishing outside an exact topic were rejected with 401.
- A valid subscriber token for another topic received no private update. Removing
  `private=on` from the publisher made that assertion fail with the leaked
  synthetic message. Restoring it passed the complete proof.
- No wildcard CORS is configured; an untrusted-origin OPTIONS response was 405
  without `Access-Control-Allow-Origin`. Both subscription API and debugger
  routes return 404 through the public proxy. Caddy administration is disabled.
- Cookie attributes were inspected through Chromium. No JWT is stored in page
  JavaScript, URL parameters or local storage. The password is submitted by POST.
- Disconnect plus explicit `lastEventID` replay retrieved a retained update.
  Native EventSource reconnected after hub restart and received a later update.
- PHP runtime restart left hub subscriptions alive; a fresh PHP CLI publisher
  still reached both subscribers. Hub restart exited with code 0 after its five second grace period and
  browser retry. Logs show a graceful-shutdown timeout and subscriber cleanup
  errors against the closed transport. This is a restart/recovery proof, not
  proof of clean draining; see the upstream reproducer below.
- Subscribers remained usable beyond 60 seconds. Hub heartbeat is 5 s; maximum
  connection duration is 120 s. nginx read timeout is 180 s, write/send timeouts
  are 10 s; hub dispatch timeout is 2 s. These are development settings, not
  tuned production guarantees.
- Ordinary Vault overview requests returned 200 while subscriptions remained
  open. Four initial browser request lenses had no issues; browser capture
  reported no JavaScript exceptions or requested error/network events.
- The final canonical live run passed **18 checks**, with no browser exceptions.
- Nine clients, including one client whose curl output was never consumed,
  received a burst of 96 synthetic 64 KiB publications. All eight draining
  clients received over 6.3 MB, including the final update. A normal Vault
  request returned 200 in 0.124 s afterward. The probe ran about 34 s. It does
  not prove an actual slow-client timeout, bounded memory under sustained
  overload, or a production subscriber limit.
- Hub memory was about 11 MB initially, 14–18 MB with browser streams, and
  30–36 MB after bounded bursts. `podman stats` CPU snapshots were around 0.1–1%
  during these probes. They are snapshots with container accounting, not
  sustained CPU measurements or an FPM/FrankenPHP performance comparison.
- Existing Vault HTTP tests: **9 passed, 148 assertions**. They include historical
  binary hashes, HEAD/resource cleanup and a 64 MiB download under a 32 MiB PHP
  memory limit. A separate real Lerd HTTPS download of the existing synthetic
  Asset v1 also returned 54 bytes with the expected SHA-512
  `4043c87b84cc8e896fa1f19af33df91cb3a943e1e1aaaf9ae0a0ddac37b1ede2091b4eda24bbf5f3e7087cd1c05b6dfc6ec073cf9a72bfb95c4fb76155e0bfea`.
  The tests run real disposable HTTP processes; they do not establish
  the untested FrankenPHP behavior.
- `composer qa` stopped at the existing `no-unsafe-finally` lint error in
  `ObjectWriteLockTest.php:86`. It made no unrelated changes. Focused fixture
  lint and PHP syntax checks passed. The aggregate repository QA is not green.

## FrankenPHP classic: inspected, not adopted

The current site remains FPM. A temporary switch was requested separately
because the task explicitly reserves runtime changes for approval. Until that
approval arrives, **classic serving, PostgreSQL/Vault access under classic,
streaming under classic, and rollback from classic remain untested**. There is
no claim that an installed FrankenPHP binary establishes application mode.

Exact commands, from Core, once approved:

```sh
lerd runtime                    # inspect first
lerd runtime frankenphp --no-worker
# inspect actual container command, workers, HTTP, downloads and database
lerd runtime fpm
```

Lerd writes runtime selection to `.lerd.yaml`; do not commit the experimental
selection. `.lerd.local.yaml` keys override the committed file, and Lerd refuses
to overwrite a key owned there. Do not assume a CLI switch changes that override.

The installed Tempest framework definition has no FrankenPHP adapter. Lerd's
actual generic classic launch is `frankenphp php-server -l :8000 -r public`,
in a per-site `lerd-fp-core` container on `lerd`, with the Core checkout mounted
at its absolute host path. nginx changes from FastCGI to HTTP proxying. Lerd
builds a derived image with its standard PHP extension set, not just the bare
upstream image. The generic command generates its Caddy JSON configuration
internally; it does not read a project Caddyfile or pass `--mercure` or `--worker`.
Core's entrypoint calls `HttpApplication::boot(...)->run()` and exits per request.
Classic mode should therefore use a fresh Tempest boot, but still needs runtime
proof after approval. Inspect the actual command/config and use a repeated
request probe for fresh container/static state; do not infer it from process IDs.

Lerd **stops and recreates running background workers on both runtime switches**,
repointing them between FPM and the per-site container. Expect a queue-worker
interruption and changed PID. Record running worker names before switching and
verify their health afterward. Do not switch during active Jobs.

For embedded Mercure, the installed `php-server` exposes `--mercure`, with
publisher/subscriber JWT keys and trusted issuer/resource identifier variables.
A custom Caddyfile is possible through Lerd's supported **framework overlay**
entrypoint (`frankenphp run --config ... --adapter caddyfile`) and environment,
without patching generated files. There is no project-level Caddy configuration
field in this installed Lerd revision. A global Tempest overlay affects more
than Core, so it was not changed. Embedded hub restart would follow the PHP
container; separate CLI publication would still need the HTTP hub endpoint.

The Octane requirement is specific to Lerd's worker-mode switch. Installed
source checks for `laravel/octane` only when worker mode is requested. No Octane,
worker mode or Tempest persistent lifecycle changes are part of this spike.

## Durable state and adoption requirements

Stashd's persisted state and operational history remain authoritative. When an
actual workflow is implemented, commit its state transition first and then
publish an invalidation/update carrying a stable transition ID. Clients should
apply IDs/revisions idempotently, tolerate duplicates and reordered delivery,
and fetch authorized current state/history at initial connection and reconnect.
Failure to publish must not roll back or erase already committed archival truth.
If crash-safe notification delivery later becomes a requirement, decide how to
retry from authoritative state at that time; this spike adds no outbox or bus.

Mercure IDs and retained replay reduce gaps but do not replace history. This
fixture's Bolt transport has a 10 MiB history capacity on a service data volume.
History is finite, can be evicted, and disappears if that volume is removed.
Reconnect must reconcile even when replay succeeds. There is no exactly-once
or unlimited replay promise.

Before adoption:

1. Choose a supported hub/protocol version and a maintained JWT library, with
   real identity/topic authorization and short-lived credentials. For 1.x,
   validate issuer, audience and standard access-token claims explicitly.
2. Define one real persisted transition and its authorized state/history read
   path. Test commit ordering, failure after commit, deduplication and reconnect
   reconciliation through that real workflow.
3. Add managed secret distribution/rotation. Separate publishing and subscribing
   authority; consider asymmetric signing so the hub only holds public keys.
   Test rotation overlap/revocation and expiry/renewal with active streams.
4. Always mark sensitive updates private at the publishing boundary. A hub with
   anonymous disabled still sends public updates to authenticated clients whose
   private-topic authorization does not match; the mutation proof demonstrates
   this. Do not let individual callers choose whether operational data is private.
5. Test production proxy timeouts, long sessions, sustained slow clients,
   connection limits, memory, graceful rollout and recovery under real load.
6. Complete the separately approved classic/FPM comparison before changing the
   default PHP runtime. No speed benefit has been established here.

Suggested future `AGENTS.md` additions belong with the first real integration:
persist before publication; live notifications are idempotent hints; authorize
private topics and protect signing keys; the hub owns SSE streams; test actual
CLI→hub→HTTPS→browser and reconnect reconciliation. No permanent app-wide policy
is added for this synthetic fixture.

## Upstream findings and reproducibility

Lerd source inspected at the exact installed commit:
[`runtime.go`](https://github.com/lerd-env/lerd/blob/14f05e85cbe4f0dd4437a53fd291177a7ef6605f/internal/cli/runtime.go),
[`framework.go`](https://github.com/lerd-env/lerd/blob/14f05e85cbe4f0dd4437a53fd291177a7ef6605f/internal/config/framework.go),
[`frankenphp.go`](https://github.com/lerd-env/lerd/blob/14f05e85cbe4f0dd4437a53fd291177a7ef6605f/internal/podman/frankenphp.go).
No installed binary, vendor source or upstream checkout was modified.

- **Insecure Mercure preset defaults:** `service preset` discovery offers
  `mercure:v0.24.2` with a known shared key, `anonymous`, `cors_origins *`, and
  `demo`. Read the downloaded `service-presets/mercure.yaml` to reproduce;
  starting that preset was rejected by automatic review and nothing was
  installed by that call. Proposed store correction: generate unique keys,
  private-by-default configuration, and explicit opt-in to a demo profile.
  The committed Containerfile supplies secure static config at image build time
  and uses normal Lerd custom-service registration, without a generated-file patch.
- **SSE timing false positive:** run the browser proof with dumps enabled, then
  `diag optimize_route` for Core. Observed `GET /.well-known/mercure` p95
  **66,786 ms**, 710.5× the site's 94 ms median, caused by healthy long-lived
  streams. Proposed Lerd correction: track SSE connection duration separately
  from ordinary route response latency (using response content type), rather
  than asking applications to shorten healthy subscriptions.
- **Mercure 0.24.2 shutdown cleanup:** with an active authorized SSE subscriber,
  send SIGTERM to the hub. With the committed Caddyfile it reaches its 5 s grace
  deadline, logs `server graceful shutdown 5s timeout` and
  `Failed to remove subscriber on shutdown` / `read/write on closed Transport`,
  then exits 0. The direct reproducer independently confirmed the grace timeout
  and exit 0 twice, with one and two subscribers; it did **not** reproduce the
  intermittent closed-transport cleanup error seen in Lerd-managed restarts.
  Run `python3 dev/mercure-spike/reproduce-shutdown.py` after setup. It uses a
  disposable container and random loopback HTTP port, bypassing nginx and Lerd
  lifecycle code. Its client token is minted by the existing PHP fixture.
  Do not treat recovered browser delivery as proof of a clean graceful drain.
  Before production, evaluate a current hub with an explicit drain deadline or
  submit a focused upstream shutdown-ordering fix after independent reproduction.
- **Caddy configuration scope:** generic classic mode ignores a project
  Caddyfile; custom entrypoint/env are framework-scoped. If embedded Mercure
  becomes desirable, propose a project-scoped Caddyfile/entrypoint option with
  path validation and lifecycle tests. Standalone deployment does not depend on it.
- **Custom-service config:** installed Lerd strips legacy `files:` from user
  service YAML and disallows multiline environment values. Service tuning is
  currently limited to database/cache engines. A small standard OCI image with
  a baked Caddyfile uses supported paths. No custom event infrastructure is needed.

FrankenPHP 1.13.0 embedded settings were checked in its tagged
[`php-server`](https://github.com/php/frankenphp/blob/v1.13.0/caddy/php-server.go)
and [`Mercure adapter`](https://github.com/php/frankenphp/blob/v1.13.0/caddy/mercure.go).
Mercure 0.24.2's tagged
[`publisher`](https://github.com/dunglas/mercure/blob/v0.24.2/publish.go)
explains the observed HTTP 200 acknowledgement. No Tempest defect was established
by this spike. The existing Vault sender's resource ownership remains unchanged.

## Logs, troubleshooting and teardown

```sh
podman logs --tail 100 lerd-stashd-mercure-spike
podman stats --no-stream lerd-stashd-mercure-spike
curl -i 'https://core.test/.well-known/mercure?topic=https://core.test/spike/synthetic'
# Expected 401 without a subscriber credential.
lerd service status stashd-mercure-spike
lerd runtime
```

Use MCP `logs sources` before `logs fetch` for nginx, FPM and worker sources;
then check browser network/console and each PHP response's `X-Lerd-Rid` using
`request lenses`. No PHP request ID is expected on the direct hub route.
A 502 usually means hub/DNS/upstream trouble; 401 means missing, expired or
incorrect credentials; a 200 stream with no private messages can mean mismatched
topics. Check cookie path, host, HTTPS, expiry, and claim format. Confirm
`proxy_buffering off` remains in the generated vhost's supported include.
Avoid logging Authorization, Cookie or credential JSON.

To remove the experiment, restore the prior nginx server override through Lerd
(on this machine: MCP `site nginx_reset`, site `core`, scope `server`), then:

```sh
rm public/mercure-spike.php
lerd service stop stashd-mercure-spike
lerd service remove stashd-mercure-spike --purge
rm -r tmp/mercure-spike
# Only if a classic-mode experiment was separately approved and run:
lerd runtime fpm
```

`--purge` renames the synthetic hub data directory aside rather than destroying
it. Remove that specifically named backup later if desired. The image can be
removed with `podman rmi localhost/stashd-mercure-spike:local` after the service
is gone. Stop the isolated browser and delete its temporary profile. For fresh
keys, tear down, prepare a new run and register/start again; old tokens then
fail verification. A zero-downtime production rotation was not tested.

Committed: fixture source, Caddyfile, Containerfile, nginx example, opt-in
verification scripts and this report. Machine-specific: credentials, public
entrypoint, service registration/data, nginx override, browser profile, and any
experimental runtime selection. No mandatory runtime or `.env` changes belong
in this PR.
