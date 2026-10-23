# Embedded Mercure: requested next configuration

The selected PHP runtime is FrankenPHP **classic**. The requested next transport
is its built-in Mercure 1.0.3 module. The module is installed, but Lerd 1.37.0
cannot currently configure it for the existing Tempest site through either a
project Caddyfile or a personal FrankenPHP framework overlay.

The working standalone proof remains available while this limitation is fixed.
No embedded hub has been enabled, and no generated files or installed binaries
have been patched. The standalone 0.24.2 proof must not be presented as proof of
the embedded 1.0.3 authentication or restart behavior.

## Reproducer against the installed Lerd

```sh
python3 dev/mercure-spike/reproduce-lerd-overlay.py
```

Run from this Core checkout on classic mode, with no active Jobs. The script
refuses an existing personal Tempest overlay. It temporarily installs a supported
personal overlay using `lerd framework add`, requests the harmless
`--no-compress` command-line marker, and re-applies classic mode. It records:

```json
{
  "requested_entrypoint": ["frankenphp", "php-server", "-l", ":8000", "-r", "public", "--no-compress"],
  "actual_entrypoint": ["frankenphp", "php-server", "-l", ":8000", "-r", "public"],
  "overlay_applied": false
}
```

The script then removes only its own temporary overlay and re-applies the normal
classic configuration. Each runtime re-application recreates configured workers.
The report is `tmp/mercure-spike/lerd-overlay-evidence.json`.

The marker avoids enabling an incompletely configured hub. `--mercure` lives in
the same ignored entrypoint block, so changing the marker to that flag would not
solve the configuration problem.

Installed source, commit `14f05e85cbe4f0dd4437a53fd291177a7ef6605f`:

- [`FrankenPHPQuadletSpec`](https://github.com/lerd-env/lerd/blob/14f05e85cbe4f0dd4437a53fd291177a7ef6605f/internal/config/frankenphp_reload.go)
  resolves `GetFrameworkForDir` for the site.
- [`mergeUserOverlay`](https://github.com/lerd-env/lerd/blob/14f05e85cbe4f0dd4437a53fd291177a7ef6605f/internal/config/framework.go)
  merges workers/setup/logs/nginx/requires but ignores `overlay.FrankenPHP`.
  `SaveFramework` also strips that field when saving a Laravel overlay.
- The generic entrypoint is `php-server -l :8000 -r public`, which does not load
  an ordinary project Caddyfile and does not enable Mercure without its flag.

The earlier report's claim that a personal framework overlay already supported
this entrypoint override was incorrect. The observed command and resolver source
above establish the actual limitation.

## Explicit, isolated upstream contribution plan

Prepare a separate Lerd contribution against the installed revision or current
upstream, in an isolated checkout. Do not replace this machine's installed binary
or patch its generated quadlets, image or store cache.

1. Extend trusted personal framework overlays to honor the existing
   `frankenphp` block, with documented full-block replacement semantics. Preserve
   the field through `SaveFramework` for built-in Laravel overlays too. Omitted
   blocks must preserve all existing runtime behavior.
2. Add regression tests through `Site.FrankenPHPQuadletSpec` with sandboxed store
   and personal configuration directories. Prove the test fails on 1.37.0 and
   passes with the fix. Cover regular entrypoint/env, omitted overrides, explicit
   worker settings, and saving/loading a Laravel overlay.
3. Keep this contribution generic: no Stashd paths, JWT secrets or Mercure-specific
   runtime flags in Lerd. Publish it as its own upstream PR when authorized.
4. After a supported Lerd update includes the correction, install an explicit
   personal Tempest overlay that uses a project's Caddyfile when present and the
   existing classic command otherwise. This keeps other Tempest sites on their
   current command unless they explicitly supply that file.

A project-scoped Caddyfile option is an alternative upstream design if Lerd's
maintainers prefer it. Do not clone the Tempest framework into a private Stashd
framework merely to bypass the missing override.

## Smallest subsequent Core implementation

- Add a classic-only Caddyfile on port 8000, with no worker block, no anonymous
  access, no debugger/playground/subscription administration, and Caddy admin off.
- Supply fresh local publisher/subscriber verification keys through an ignored,
  restricted file or supported secret provider; never commit them.
- Convert the restricted fixture to Mercure 1.x access tokens: `typ: at+jwt`,
  issuer, pinned public audience, expiry and exact-topic `authorization_details`.
  Use the secure, HttpOnly subscriber cookie and keep all sensitive updates private.
- Route `/.well-known/mercure` through nginx directly to `lerd-fp-core:8000`, with
  buffering disabled and explicit timeouts. CLI/background publishers use that
  HTTP endpoint; PHP never owns browser subscriber streams.
- Re-run the existing public-boundary proof against the actual module: CLI
  publication, two browsers, rejection cases, replay, slow reader and HTTPS
  delivery. For an embedded hub, PHP restart also restarts Mercure: prove native
  reconnect and authoritative-state reconciliation rather than claiming that
  subscriptions survive unchanged. Test bounded graceful draining on 1.0.3.
- Remove the standalone service only after that replacement proof passes. Retain
  the tested FPM fallback; its hub availability must be chosen independently.

This plan does not add a second bus, durable event store or Operations UI.
