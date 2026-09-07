# Stashd plugins

Stashd plugins are separately packaged integrations that teach Stashd how to
interact with a source or publish a view of preserved material without teaching
Core the details of that provider or format.

If you are a coding agent building or changing a plugin, start with
[the agent playbook](plugins/agents.md). If you are a human plugin author, start
with [the authoring guide](plugins/authoring.md).

## The first rule: the contract is not PHP

The canonical plugin contract lives in
[`Lost-and-Fonds/plugin-api`](https://github.com/Lost-and-Fonds/plugin-api).
Its WIT definitions are the normative, language-neutral description of the
values, lifecycle methods, errors, and host capabilities that cross the plugin
boundary.

The PHP SDK in
[`Lost-and-Fonds/stashd-php-sdk`](https://github.com/Lost-and-Fonds/stashd-php-sdk)
is the **current SDK implementation**, not the definition of the plugin
architecture. It provides a pleasant PHP authoring API and adapts it to the
contract and the production RPC transport. Other SDKs can be added later and
may expose different language-native ergonomics while preserving the same
contract semantics.

There is an important current limitation: the production Stashd host currently
executes only `runtime: "php"` plugin packages. The manifest validator and
sandbox runner enforce that today. The architecture and contract are designed
so that other runtimes/SDKs can follow, but they are not yet executable merely
because the contract is language-neutral.

The current contract line is:

```text
plugin-api / WIT            stashd:plugin@0.2.0
        ↓
PHP SDK                     0.3.x authoring binding
        ↓
RPC v1 + host capabilities  production process boundary
        ↓
Stashd Core                 orchestration, Vault, persistence, fixity, jobs
```

Do not reverse that dependency. In particular, do not make the WIT contract
look like PHP simply because PHP happens to be the first SDK.

## What belongs where

Core owns the universal preservation machinery:

- authoritative records and identity;
- the Vault and filesystem authority;
- provenance, promotion, and fixity;
- scheduling and jobs;
- encrypted secrets and Connections;
- generic HTTP, helper, staging, logging, and progress capabilities;
- plugin package installation, activation, sandboxing, and supervision.

Plugins own integration-specific behaviour:

- provider URLs and identifiers;
- provider APIs and response formats;
- discovery semantics;
- provider-specific acquisition behaviour;
- destination layouts, feeds, metadata, and refresh protocols;
- declared helper programs such as `yt-dlp` or `ffmpeg` when the integration
  needs them.

A good test is: **would Core need to know this fact if the provider disappeared
tomorrow?** If not, it probably belongs in the plugin.

Plugins do not get unrestricted access to the Stashd database, Vault, host
filesystem, environment, credentials, or network. They run behind a capability
boundary and receive only the resources required for the current invocation.

## Plugin kinds available today

There are two executable contract worlds today.

### Input

An Input plugin turns a user-supplied source into preserved material. Its
language-neutral lifecycle is:

```text
resolve → discover → acquire
```

`resolve` validates/normalises a source, `discover` enumerates media items, and
`acquire` creates staged artifacts for Stashd to promote into the Vault.

Host HTTP, staging, helper, progress, and log capabilities are invocation-scoped
imports in the WIT. The PHP SDK exposes them to an Input through the
`PluginContext` passed to the plugin factory.

The first-party YouTube plugin is the best complete Input example:
[`Lost-and-Fonds/youtube`](https://github.com/Lost-and-Fonds/youtube).

### Broadcast

A Broadcast plugin turns Vault material into a disposable, rebuildable view or
publishes it to an external destination. Its language-neutral lifecycle is:

```text
prepare → publish → finalize
                  ↘ operation
```

All four lifecycle calls may use invocation-scoped host capabilities. WIT models
those as imported capabilities rather than request fields. PHP SDK 0.3 exposes
them consistently by passing a `PluginContext` to every Broadcast method.

The first-party Podcast plugin is a compact file-producing example:
[`Lost-and-Fonds/podcast`](https://github.com/Lost-and-Fonds/podcast).
Jellyfin and Plex are useful examples when an external service/Connection is
involved.

Planned ideas such as enrichment, backup, archive import/export, or physical
media may eventually introduce new plugin contracts or may fit an existing
contract. **Do not invent a new plugin kind because it appears in the project
wishlist.** Only Input and Broadcast are contract worlds today.

## Contract 0.2 at a glance

Contract `0.2` reconciles the WIT with the production runtime rather than
pretending earlier implementation drift did not happen. Important guarantees
for plugin authors are now explicit:

- generic HTTP requests support method, URL, optional credential, headers, and
  a request body, and responses include headers as well as status/body;
- Input and Broadcast expose the same seven typed plugin failure categories:
  `unsupported`, `not-found`, `authentication`, `rate-limited`, `unavailable`,
  `invalid-data`, and `failed`;
- every plugin failure carries a human-readable `message` and a `retryable`
  boolean;
- host capabilities are invocation-scoped and language-neutral;
- DTO decoding is strict: malformed required fields or variant values are
  rejected rather than silently coerced;
- RPC v1 remains the transport, including an explicit hello/version handshake;
- cross-language JSON conformance fixtures live in `plugin-api/tests/contract/fixtures`.

RPC v1 frames remain a four-byte unsigned big-endian length followed by a UTF-8
JSON object. Inline byte values use the host's current JSON string
representation in capability payloads. Chunks returned by `resource.read` are
base64-encoded.

## Documentation map

- [Authoring plugins](plugins/authoring.md) — architecture, lifecycles,
  capabilities, packaging, testing, and worked patterns.
- [Manifest reference](plugins/manifest.md) — `plugin.json`, helpers, credentials,
  grants, Input fields, and Broadcast fields.
- [PHP SDK](plugins/php-sdk.md) — the current PHP 0.3 binding, exact interfaces,
  typed failures, entrypoints, and repository skeleton.
- [Agent playbook](plugins/agents.md) — an operational path for Luna and other
  coding agents, including a copy-paste task template.
- [Plugin runtime boundary](architecture/plugin-runtime.md) — host/runtime design
  and sandbox ownership.
- [Plugin migration status](development/plugin-migration.md) — historical runtime
  work and current production status.

## Installation and package lifecycle

Production plugins are distributed as versioned OCI artifacts. Installation is
separate from configuration: a plugin can be installed without having its
credentials or Connections configured.

Install a trusted plugin reference with:

```bash
docker compose exec stashd php tempest stashd:plugin-install \
  ghcr.io/lost-and-fonds/youtube:<version>

docker compose exec stashd php tempest stashd:plugin-list
```

The installer resolves a platform-specific immutable digest, validates the
package and manifest, stores versions below `/data/plugins`, and atomically
activates the selected version. Installed versions are immutable; activation is
a symlink switch, so rollback is a package-lifecycle operation rather than a
mutation of an installed package.

Do not copy version numbers or digests from old documentation. Plugin versions
move independently of Core; use the plugin repository/release as the source for
the version you intend to install.

## Trust and security

Installing an OCI plugin is an explicit administrator trust decision. The
sandbox significantly limits what plugin code can reach, but it is not a claim
that arbitrary third-party code is harmless.

Secrets are host-owned. Plugins refer to declared credential names; the host
injects credential material only while servicing an authorised capability call.
Secrets must never be placed in plugin logs, generated URLs, job metadata,
public API responses, or package files.

Network access is capability-driven. A plugin should use host HTTP capabilities
with narrowly declared URL prefixes wherever possible. Helper processes may be
granted network access when explicitly declared; plugin code must not assume it
has general host networking.

## Compatibility status

The current contract package is `stashd:plugin@0.2.0`; new package manifests
should use `api_version: "0.2"` and current PHP plugins should target
`stashd/php-sdk:^0.3`.

Core currently accepts both `0.2` and legacy `0.1` manifests as an explicit
migration allowance. That is compatibility support, not a recommendation to
start new work on `0.1`. First-party YouTube and Podcast plugins have moved to
`0.2`.

The API is still evolving, so treat contract changes deliberately and run the
contract/conformance suites when changing a boundary. The WIT files, not PHP
DTOs, first-party plugin code, generated reports, or this prose, remain the
canonical language-neutral contract once a reconciliation has landed.
