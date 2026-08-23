# Stashd repository split migration specification

## Status

Implementation-ready migration specification. Do not perform the migration without
an explicit follow-up task. Plugins are the production architecture;
Wasmtime/Wasm is historical reference material only.

## 1. Final ownership

| Repository | Owns | Does not own |
|---|---|---|
| `Lost-and-Fonds/stashd` | App, PostgreSQL, jobs, Vault, generic Inputs/Broadcasts, UI, Docker, plugin runtime, Composer discovery/activation, application integration tests | Provider protocols/packages |
| `Lost-and-Fonds/docs` | Curated user, admin, architecture, contributor, ecosystem documentation | Code-adjacent procedures and stale plans |
| `Lost-and-Fonds/plugin-api` | Normative language-neutral WIT/schema, manifest schema, fixtures, compatibility policy | SDK, runtime, providers |
| `Lost-and-Fonds/plugin-sdk` | PHP 8.5 API, DTOs, RPC bridge, capability interfaces, SDK tests/example | Core/provider implementation |
| `Lost-and-Fonds/jellyfin` | Jellyfin Broadcast package/tests | Core lifecycle/persistence |
| `Lost-and-Fonds/plex` | Plex Broadcast package/tests | Core lifecycle/persistence |
| `Lost-and-Fonds/podcast` | M10-ready package/documentation skeleton | A claimed native implementation |
| `Lost-and-Fonds/youtube` | M11-ready package/documentation skeleton | A claimed native implementation |

Create `Lost-and-Fonds/.github` only for the organization profile; it is not a
Stashd product repository.

## 2. Exact source disposition

### plugin-api

Move and normalize:

```text
plugin-api/wit/**
plugin-api/spike-wit/plugin.wit
packages/plugin-sdk/generated/wit-schema.json
spikes/native-plugin-runner/m3/extract_wit.py
spikes/native-plugin-runner/m3/test_m3.py
spikes/native-plugin-runner/m3/generated/compatibility-report.md
```

Result:

```text
plugin-api/
├── wit/
├── schema/wit-schema.json
├── fixtures/
├── tools/extract_wit.py
├── tests/
├── docs/{compatibility.md,versioning.md}
├── composer.json
├── README.md
└── AGENTS.md
```

Incorporate `spike-wit/plugin.wit` into `wit/` only if normative; otherwise
place it in core's Wasmtime reference. Never leave two active contract sources.

### plugin-sdk

Move `packages/plugin-sdk/**`. The schema moves to plugin-api; SDK consumes
`stashd/plugin-api` and must not own a second contract. Add exactly one
`examples/minimal-broadcast/` example. SDK must not import `App\`, core test
helpers, or core runtime classes.

### Jellyfin and Plex

Move to their respective repositories:

```text
plugins/jellyfin-native/{plugin.json,plugin.php,src/**,tests/run.php}
plugins/plex-native/{plugin.json,plugin.php,src/**,tests/run.php}
```

Do not move `plugins/jellyfin/**` or `plugins/plex/**`: those Rust/Wasm
implementations remain in core reference material.

### Podcast and YouTube

Do not move:

```text
plugins/podcast/**
plugins/youtube/**
```

Create fresh repositories with only `composer.json`, `README.md`,
`AGENTS.md`, and `LICENSE`. They are ordinary Composer libraries, not
`stashd-plugin` packages, and contain no manifest or executable entrypoint
until M10/M11 delivers an actual provider plugin.

### Remain in core

All tracked source remains in core except paths explicitly moved above or placed
under Wasmtime reference below. In particular:

```text
app/** bootstrap/** docker/** e2e/** public/** src/** tests/** ui-v2/**
scripts/**                         # except the listed Wasmtime spike scripts
packages/plugin-runtime/**
.github/**
Dockerfile docker-compose*.yml composer.* package*.json
PHPStan, PHPCS, PHP-CS-Fixer, Tempest, Vite, Playwright, and Lerd files
```

Keep `packages/plugin-runtime/**` as an internal core directory, not a
public package. Remove its nested `composer.json` once absorbed into core
metadata.

## 3. Wasmtime reference layout

Move retired Wasm material to this exact path:

```text
core/reference/wasmtime/
├── README.md
├── host/                         # plugin-host/**; includes Cargo.toml and src/
├── api/wit/                      # frozen historical WIT inputs
├── providers/
│   ├── example/                  # plugins/example/**
│   ├── jellyfin/                 # plugins/jellyfin/**
│   ├── plex/                     # plugins/plex/**
│   ├── podcast/                  # plugins/podcast/**
│   └── youtube/                  # plugins/youtube/**
├── spikes/native-plugin-runner/  # spikes/native-plugin-runner/**
├── scripts/
│   ├── plugin-spike.sh
│   ├── plugin-lifecycle-spike.sh
│   ├── podcast-broadcast-spike.sh
│   └── youtube-input-spike.sh
└── tests/
    ├── PluginJellyfinLifecycleTest.php
    └── PluginPlexLifecycleTest.php
```

Place the old root `Cargo.toml` and `Cargo.lock` there too, adjusting only
enough to make the reference tree self-describing. They must not stay at core
root.

The README must say:

> Historical/reference code only. PHP plugins are Stashd's production
> architecture. Do not update this directory to implement current behavior. It
> is excluded from Docker production builds, Composer autoloading, Cargo
> workspaces, plugin discovery, normal CI, and routine tests.

Production cleanup:

- Delete `WasmtimeBroadcastRuntime`.
- Remove `wasmtime` from production manifests and remove runtime-selection
  variables including `STASHD_BROADCAST_IMPLEMENTATIONS`.
- Replace native/Wasm parity tests with plugin-only lifecycle tests.
- Remove Wasm components, Rust stages, Cargo workspace commands, and Wasmtime
  host supervision from Docker and CI.
- Production discovery must not glob `plugins/*/plugin.json`; Composer is the
  only source.

## 4. Composer packages and dependency graph

Publish:

```text
stashd/plugin-api
stashd/plugin-sdk
stashd/jellyfin
stashd/plex
stashd/podcast
stashd/youtube
```

GitHub organization and Composer namespace are independent.

```text
plugin-api → plugin-sdk → Jellyfin/Plex/Podcast*/YouTube*
                             ↑
                   stashd core consumes released providers
```

Podcast/YouTube remain unrequired by core until production-native versions
exist.

```json
// plugin-api
{"name":"stashd/plugin-api","type":"library"}

// plugin-sdk
{
  "name":"stashd/plugin-sdk",
  "require":{"php":"^8.5","stashd/plugin-api":"^0.1"}
}

// jellyfin / plex
{
  "name":"stashd/jellyfin",
  "type":"stashd-plugin",
  "require":{"php":"^8.5","stashd/plugin-sdk":"^0.1"}
}

// core
{
  "require":{
    "stashd/plugin-sdk":"^0.1",
    "stashd/jellyfin":"^0.1",
    "stashd/plex":"^0.1"
  }
}
```

Use tagged releases through Packagist or a configured Composer repository.
Never commit production `path` repositories.

## 5. Package payload and discovery

Use one conventional payload:

```text
vendor/stashd/jellyfin/
├── composer.json
├── src/
├── tests/
└── stashd-plugin/
    ├── plugin.json
    └── plugin.php
```

```json
{
  "type":"stashd-plugin",
  "extra":{
    "stashd-plugin":{
      "manifest":"stashd-plugin/plugin.json",
      "entrypoint":"stashd-plugin/plugin.php"
    }
  }
}
```

Core enumerates Composer packages of type `stashd-plugin`, resolves install
paths, reads the declared payload, validates package-relative paths and the API
manifest, registers discovered packages, and uses persisted core activation and
configuration state to decide whether each runs.

Composer installs bytes. Core validates, activates, grants capabilities, starts
the process, and executes it. No archives, package-copying, Composer scripts, or
second installer. The runner mounts Composer's SDK read-only; providers never
receive core source, Vault paths, database access, or raw secrets.

## 6. Docker

Replace current Wasm/Rust/provider-copy stages with Node assets, Composer
dependencies, and a FrankenPHP core stage containing PHP/extensions, Bubblewrap,
core, and Composer-installed packages.

Remove Rust images, Rustup/Wasm targets, Cargo builds, the Wasm host, component
outputs, yt-dlp/Deno/Podcast FFmpeg provisioning, native-plugin source copies,
and all `STASHD_*PLUGIN_COMPONENT*` variables.

The production image installs locked Composer dependencies and initially
contains only Jellyfin and Plex. It discovers payloads under `vendor/`; it
does not copy provider source. Add Podcast/YouTube dependencies and helpers only
when their native packages require them.

## 7. Local workspace

```text
stashd/
├── core/
├── docs/
├── plugin-api/
├── plugin-sdk/
└── plugins/{jellyfin,plex,podcast,youtube}/
```

The parent is not a Git repository. Normal development uses releases. For
temporary cross-repository work only:

```bash
cd core
composer config --global repositories.lost-and-fonds-sdk \
  '{"type":"path","url":"../plugin-sdk","options":{"symlink":true}}'
composer config --global repositories.lost-and-fonds-jellyfin \
  '{"type":"path","url":"../plugins/jellyfin","options":{"symlink":true}}'
composer update stashd/plugin-sdk stashd/jellyfin --with-all-dependencies
```

Do not commit resulting local lockfile changes; remove global overrides after
the task.

## 8. Documentation classification

| Current material | Disposition |
|---|---|
| Engineering specs and architecture vision files | Rewrite once into docs product/architecture overview |
| Storage, Broadcast, Provider, media-server READMEs | Rewrite into docs user-guide/administration |
| Plugin runtime architecture | Rewrite for docs; keep implementation details in core |
| Plugin API v1 design | Distill into plugin-api reference; preserve native decision as ADR |
| Database/PHP standards, testing, security, local-development, workflow, runtime docs | Keep concise and code-adjacent in core |
| SDK README/generated-schema notes | Keep with SDK |
| Podcast README/provider-specific guidance | Move/rewrite with provider |
| Wasm plugin architecture/spike docs | Core reference only |
| YouTube parity design | Concise M11 reference/ADR only if durable; otherwise delete |
| `docs/plans/**`, TODOs, prompts, handoffs, implementation plans | Delete from split; Git preserves history |
| Browser-extension spec, duplicate branding plans/assets | Do not migrate unless separately adopted |

Initial docs repository:

```text
getting-started/
user-guide/
administration/
architecture/adr/
plugin-development/
reference/
contributing/
README.md
AGENTS.md
```

Use curated Markdown first; add a site generator only when navigation/search
actually needs it.

## 9. README requirements

- Core: purpose, “Stash → Vault → Broadcasts,” preservation-not-player framing,
  installation/development links, docs, and ecosystem links.
- API: normative status, compatibility/versioning, relationship to SDK/core.
- SDK: PHP 8.5, minimal example, testing, package structure, API relationship.
- Jellyfin/Plex: integration, Broadcast role, credentials/configuration,
  supported behavior, development/testing, Stashd relationship.
- Podcast/YouTube: intended role and explicit statement that no production
  provider plugin exists yet.
- Docs: scope, navigation, Markdown contribution workflow, no local server.
- Organization profile:

  ```text
  Lost & Fonds
  Open tools for preserving digital media, knowledge, and cultural material.

  fonds, n. A body or collection of records accumulated and preserved
  by a person, family, or institution in the course of its affairs.
  ```

Do not over-brand individual repositories.

## 10. Tests and CI

| Change | Default verification | Default CI |
|---|---|---|
| plugin-api | WIT/schema, fixtures, deterministic generated output | Contract job only |
| plugin-sdk | SDK tests plus API compatibility fixture matrix | PHP lint/static/tests |
| Jellyfin/Plex | Provider fixture tests plus SDK compatibility | PHP lint/static/provider tests |
| Podcast/YouTube skeleton | Metadata/README validation | Composer validation and Markdown/link check |
| Core | Unit/feature/PostgreSQL/plugin runtime; Docker only when boundary touched | Application quality, plugin-only integration, UI/Docker as needed |
| Docs | Markdown/internal links | Docs-only job |

Every repository gets a short `AGENTS.md` naming its canonical test command and
escalation boundary. Do not copy core instructions wholesale.

The deliberate ecosystem gate is in core, manual and release-candidate only. It
accepts exact API/SDK/Jellyfin/Plex versions, installs them, runs native
Jellyfin/Plex application lifecycle tests plus Docker smoke, and records the
tested version matrix. Provider PRs must not rebuild every provider, core image,
or historical artifact.

## 11. History extraction

1. Freeze and tag the source monorepo as `pre-repository-split`.
2. Mirror/transfer its history to `Lost-and-Fonds/stashd`, then make one
   focused split commit: extract active packages, fence Wasmtime, and remove
   production wiring.
3. Use local mirror copies and `git filter-repo`:

   ```bash
   git filter-repo --path plugin-api/ --path-rename plugin-api/:
   git filter-repo --path packages/plugin-sdk/ --path-rename packages/plugin-sdk/:
   git filter-repo --path plugins/jellyfin-native/ --path-rename plugins/jellyfin-native/:
   git filter-repo --path plugins/plex-native/ --path-rename plugins/plex-native/:
   ```

4. Add each repository's new scaffolding in ordinary commits.
5. Create Podcast, YouTube, and Docs fresh.

Do not carry old Jellyfin/Plex Wasm history into provider repositories. Preserve
the useful native history there; keep Wasm history in core reference.

## 12. Migration order, checkpoints, rollback

1. Create profile and empty targets; configure branch protection/package
   publishing. Rollback: delete empty repositories.
2. Tag and mirror monorepo; verify known-good M8/M9. Rollback: abandon mirrors.
3. Extract/publish API 0.1.0; validate fixtures. Rollback: do not consume it.
4. Extract/publish SDK 0.1.0 against API 0.1.0. Rollback: do not change core.
5. Extract/publish Jellyfin/Plex 0.1.0 and provider tests. Rollback: do not add
   core constraints.
6. Create Podcast/YouTube skeletons and docs. Rollback: remove skeletons.
7. Convert core discovery to Composer packages and pass plugin-only lifecycle
   tests. Checkpoint: core installs released Jellyfin/Plex only. Rollback:
   revert this core commit.
8. Remove production Wasmtime/Docker/Cargo/CI and move reference material.
   Checkpoint: `rg 'wasmtime|wasm32|STASHD_PLUGIN_COMPONENT'` finds only
   reference/historical docs. Rollback: revert this dedicated removal commit;
   do not recreate a permanent runtime switch.
9. Build a release candidate, run the ecosystem gate, and tag split releases.
   Rollback: retain the prior core image/version.

## 13. Completion criteria

The split is complete only when:

- all eight requested repositories exist under `Lost-and-Fonds`, with the
  organization profile README in `.github`;
- core has no production Wasmtime, Cargo workspace, Wasm Docker build,
  discovery, routine test, or runtime switch;
- retired material is fenced at `core/reference/wasmtime/` with the required
  README;
- Docker installs Jellyfin/Plex exclusively as locked Composer packages and
  copies no provider source;
- API, SDK, Jellyfin, and Plex packages are tagged, published, and installable;
- Jellyfin/Plex execute through native SDK/runtime from Composer-installed
  payloads;
- Podcast/YouTube claim no production-native implementation and are not core
  dependencies;
- providers do not depend on core source;
- every repository has README, concise AGENTS guidance, narrow test procedure,
  and focused CI;
- package tests, native lifecycle tests, Docker smoke, and the explicit
  ecosystem gate pass using released versions;
- no permanent path repository is committed;
- the sibling local workspace clones cleanly; and
- the original useful state is tagged and extraction provenance recorded.
