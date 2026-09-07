# Agent playbook: build a Stashd plugin

This page is written for coding agents, including Luna. It is deliberately
operational: follow it before searching Core for patterns or inventing new
abstractions.

## Mission

Implement provider/format behaviour as an external plugin while preserving
Stashd's architecture:

```text
Stash → Vault → Broadcasts
```

The Vault is canonical. Plugins supply integration behaviour; Core owns the
archive, persistence, fixity, filesystem authority, orchestration, jobs, and
generic capabilities.

## Current compatibility baseline

For new plugin work, assume:

```text
canonical contract   stashd:plugin@0.2.0
manifest             api_version: "0.2"
current PHP SDK       stashd/php-sdk:^0.3
production runtime   php
transport            RPC v1
```

Core also accepts legacy `api_version: "0.1"` manifests during migration. That
is compatibility support, not the target for new work.

First-party YouTube and Podcast plugins have moved to contract 0.2 / PHP SDK
0.3. Prefer them over older fixture code when signatures differ.

## Read these in this order

Before coding, read:

1. this page;
2. [`docs/plugins/authoring.md`](authoring.md);
3. [`docs/plugins/manifest.md`](manifest.md);
4. the relevant canonical WIT:
   - Input: <https://github.com/Lost-and-Fonds/plugin-api/blob/main/wit/input.wit>
   - Broadcast: <https://github.com/Lost-and-Fonds/plugin-api/blob/main/wit/broadcast.wit>
5. the SDK guide for your language. Today:
   [`docs/plugins/php-sdk.md`](php-sdk.md);
6. the nearest first-party plugin repository;
7. for SDK/wire work, the shared fixtures in
   `plugin-api/tests/contract/fixtures/`.

For current PHP work, prefer:

- Input → <https://github.com/Lost-and-Fonds/youtube>
- file-producing Broadcast → <https://github.com/Lost-and-Fonds/podcast>
- server/Connection Broadcast → Jellyfin or Plex.

Do not begin by grepping all of Core. The point of the plugin boundary is that
most plugin work should not require understanding the application internals.

## Authority rules

When sources disagree, use this precedence:

```text
plugin-api WIT
    > deliberate Core runtime/manifest compatibility behaviour
    > language SDK binding
    > first-party plugin example
    > prose/comments/legacy fixture code
```

After a deliberate reconciliation has landed, WIT is canonical again. If you
find a real mismatch, do not automatically assume WIT is ancient or PHP is
wrong: inventory the mismatch, determine intended language-neutral semantics,
and update the boundary coherently.

`reference/wasmtime/` and legacy 0.1 fixture compatibility are historical
evidence only. Do not resurrect them as current authoring patterns.

## Critical assumptions you must NOT make

1. **Do not assume PHP is the plugin contract.** PHP is currently the only SDK
   and runtime supported by production Core. WIT is language-neutral.
2. **Do not start new work on API 0.1.** Core accepts it only for migration.
3. **Do not invent an Enrichment/Backup/etc. interface.** Only Input and
   Broadcast are executable contract worlds today.
4. **Do not add provider parsing to Core.** Provider JSON/XML/HTML belongs in
   the plugin.
5. **Do not give plugins direct DB or Vault access.** Use contract values and
   host capabilities.
6. **Do not use arbitrary network access when host HTTP grants can express the
   requirement.** Contract 0.2 already has generic method/header/body HTTP.
7. **Do not download executable helpers at runtime.** Declare and checksum-pin
   helpers in the package lock.
8. **Do not treat a manifest field as supported merely because the JSON schema
   permits additional properties.** Verify Core consumes it generically.
9. **Do not make the plugin a Core Composer dependency.** Plugins are separately
   packaged/versioned OCI artifacts.
10. **Do not make Broadcast files canonical.** They are rebuildable views over
    Vault assets.
11. **Do not attach host capabilities to contract request DTOs.** PHP SDK 0.3
    exposes invocation capabilities through `PluginContext` instead.
12. **Do not infer plugin errors from exception message text.** Use typed SDK
    failures.
13. **Do not silently coerce malformed wire/DTO data.** Current SDK decoding is
    intentionally strict.
14. **Do not change WIT solely to accommodate a convenience in one SDK.** Change
    WIT when the language-neutral semantics genuinely need to evolve, and then
    update Core/SDKs/tests/docs together.

## Contract 0.2 facts agents should know

### Host capabilities

Input and Broadcast worlds import host capabilities for the invocation.
Capabilities include HTTP, staging/helpers, progress, and logging.

In PHP:

- Input receives `PluginContext` through the `InputPluginServer` factory;
- Broadcast receives `PluginContext` on **prepare, publish, finalize, and
  operation**.

That PHP presentation is not the WIT ABI. A future SDK may expose the same
imports differently.

### Generic HTTP

The language-neutral request includes method, URL, optional logical credential,
headers, and body. Responses include status, headers, and body.

Do not invent Core/provider-specific networking to work around an old GET-only
mental model.

### Typed plugin errors

Input and Broadcast share:

```text
unsupported
not-found
authentication
rate-limited
unavailable
invalid-data
failed
```

Each has `message` and `retryable`.

PHP plugin code should intentionally surface contract failures with
`PluginFailureException` / `PluginFailure` / `PluginErrorCode` / `PluginError`.
Ordinary exceptions become non-retryable `failed`.

Core temporarily accepts old flat errors for legacy plugins, but current SDK
output is the typed WIT-style `{tag,value}` form.

### Strict DTOs

Wrong types, malformed variants, invalid lists, and missing required fields are
errors. Do not “helpfully” turn numeric strings into integers or skip malformed
values.

### RPC v1

Framing remains:

```text
4-byte unsigned big-endian JSON byte length
UTF-8 JSON object payload
```

The plugin starts with a hello/version-range handshake. Inline WIT byte values
use the host's current JSON string representation in capability payloads;
`resource.read` chunks use base64.

## Fast path for a new plugin

### Step 1 — Classify it

Write one sentence answering:

```text
This plugin is an Input/Broadcast because it ________.
```

If neither fits, stop implementation and raise a contract-design question. Do
not invent a third interface inside a provider repository.

### Step 2 — Write the provider boundary before code

Record:

- accepted source/reference forms;
- stable provider IDs;
- cheap versus expensive discovery paths (Input);
- media/resources produced or consumed;
- required credentials;
- required network endpoints and HTTP methods;
- helper tools and why each is needed;
- typed provider failures and retryability;
- what must remain opaque to Core.

This usually prevents the worst architectural mistakes before they exist.

### Step 3 — Copy repository scaffolding, not business logic

Use the nearest first-party repo for CI, Composer metadata, test bootstrap,
`stashd-plugin/` layout, OCI publishing workflow, and style configuration.

For a current PHP plugin, expect at least:

```text
composer.json
composer.lock
src/
stashd-plugin/plugin.json
stashd-plugin/plugin.php
stashd-plugin/helpers.lock.json
tests/
```

For current new PHP work, the dependency line should normally be compatible
with:

```json
"stashd/php-sdk": "^0.3"
```

Do not copy stale lockfile SHAs or package versions.

### Step 4 — Write `plugin.json` first

Declare only authority/features the plugin actually requires. In particular:

- stable `id` and package version;
- `runtime: "php"` and `api_version: "0.2"` today;
- Input `kind`/source fields/prefixes or Broadcast `broadcast_key`/options;
- credentials;
- narrow HTTP grants;
- helper executables/network requirement;
- supported architectures/requirements when applicable.

Writing the manifest first forces capability/security decisions to be visible
instead of emerging accidentally from code.

### Step 5 — Implement the lifecycle thinly

For Input, implement in order:

```text
resolve
→ discover(refresh)
→ discover(complete), if meaningfully different
→ acquire
```

For Broadcast:

```text
prepare(request, context)
→ publish(request, context)
→ finalize(request, context)
→ operation(request, context), only for explicit auxiliary actions
```

The `context` notation above is PHP SDK 0.3 syntax. WIT models the underlying
capabilities as imported host resources/functions.

Keep provider client/parsing code behind small plugin-owned collaborators when
it makes tests clearer. Do not create abstractions solely because Core has a
similarly named class.

### Step 6 — Add capability fakes and contract tests

Test the plugin as a plugin, not as a hidden Core module. Cover:

- canonical IDs/references;
- mapping from provider payloads to DTOs;
- relevant options/settings;
- typed errors and retryability;
- denied/missing capabilities;
- generic HTTP method/header/body handling where used;
- exact helper arguments and expected staged artifacts/publication;
- refresh/complete distinction or publication phases;
- malformed DTOs at boundaries where you own mapping code.

Prefer fixture provider responses over live external API calls in routine tests.

### Step 7 — Verify packaging assumptions

Check:

- `composer.lock` exists for current PHP OCI builds;
- helper lock exists even if empty;
- every helper is pinned for every advertised build platform;
- SHA-256 values are correct;
- no `.env`, key, token, downloaded mutable credential, or local fixture secret
  enters the package;
- entrypoint is package-relative and works with `/sdk/bootstrap.php`.

### Step 8 — Run the repository's verification ladder

Use the plugin repo's existing scripts. For first-party PHP plugins that usually
means focused tests first, then the complete plugin suite, static analysis, and
lint/format checks.

If Core changes are genuinely required, obey Core `AGENTS.md`: use `./bin/test`
for backend tests and start with the narrowest relevant test. Do not bypass the
wrapper.

If the WIT/contract changes, run:

```bash
plugin-api/tests/contract/run.sh
```

and the SDK's conformance/tests. If wire mapping changes, replay/update the
language-neutral fixtures in `plugin-api/tests/contract/fixtures/`.

### Step 9 — Build/install smoke

For first-party release quality, verify the OCI artifact can be materialised for
advertised platforms and installed by a compatible Core. A plugin that passes
unit tests but cannot survive manifest validation/sandbox execution is not done.

## Decision table: where should this code go?

| Behaviour | Location |
|---|---|
| Parse provider API response | Plugin |
| Know provider URL formats | Plugin |
| Decide YouTube Shorts semantics | YouTube plugin |
| Generate RSS/iTunes/Podcasting 2.0 markup | Podcast plugin |
| Store canonical asset record | Core |
| Choose/finalise Vault path | Core |
| Compute/record preservation fixity | Core |
| Encrypt/store secret | Core |
| Declare which credential/network prefix an API request may use | Plugin manifest |
| Inject credential into an authorised request | Host capability |
| Execute declared helper in sandbox | Host capability/runtime |
| Map WIT values to PHP objects | PHP SDK |
| Define lifecycle/value/error/capability semantics | `plugin-api` WIT |
| Define a new provider-specific setting | Plugin manifest/code |
| Define a new generic plugin capability | Contract + host + SDK(s) |

## When Core changes are justified

A provider plugin may expose a genuine missing generic capability. Before
editing Core, be able to state:

1. what the plugin cannot express through current WIT/capabilities;
2. why the need is generic rather than provider-specific;
3. how another plausible plugin could use the same capability;
4. which contract, host runtime, SDK, tests, fixtures, and docs must change
   together.

If the argument contains a provider name in the proposed Core class/interface,
that is a strong warning sign.

## When the contract changes

A contract change is cross-repository work. Treat it as such.

Do not start with “WIT wins” or “PHP wins”. Start with a mismatch inventory:

```text
WIT says:
SDK says:
Core does:
first-party plugins need:
recommended language-neutral behaviour:
compatibility impact:
```

Then update/verify, as applicable:

- `Lost-and-Fonds/plugin-api` WIT;
- generated schema/compatibility outputs;
- cross-language contract fixtures;
- Core host/runtime dispatch and capability broker;
- every SDK implementation (currently PHP; more may exist later);
- first-party plugin conformance;
- these docs.

A WIT update is correct when the real architecture has intentionally evolved and
the change is language-neutral. An SDK/Core fix is correct when the drift was an
implementation accident or language-specific convenience.

Do not leave the system in a state where prose claims one contract while the SDK
and host quietly speak another.

## Debugging order

When an installed plugin fails, diagnose from the boundary inward:

1. Does `plugin.json` validate for the current `api_version`, runtime,
   architecture, PHP constraint/extensions, and safe entrypoint?
2. Is the expected version active rather than merely installed?
3. Does the process complete the RPC hello/version handshake?
4. Is the requested lifecycle method valid for its world?
5. Did Core grant the required HTTP/helper/staging capability for that
   operation?
6. Is a URL outside the declared prefix or a credential unavailable/rejected?
7. Did the helper exist for the active platform and pass checksum materialising?
8. Did the plugin return a contract-valid DTO/result or typed error?
9. Did strict DTO decoding reject malformed provider/plugin data?
10. Only then debug provider-specific parsing/logic.

This order avoids spending an hour debugging an API parser when Bubblewrap
never launched the entrypoint. A small mercy to one's future self.

## Review checklist for agents

Before presenting the work as complete, report explicitly:

- plugin kind and why;
- contract/API/SDK versions targeted;
- files/classes added or changed;
- manifest capabilities/credentials/helpers introduced;
- typed failures introduced and retryability choices;
- whether Core, `plugin-api`, or SDK changes were required;
- tests/conformance fixtures run and results;
- OCI/package smoke result if performed;
- any unsupported provider behaviour left intentionally out;
- any contract/runtime ambiguity discovered.

Do not say “all tests pass” unless you actually ran them.

## Copy-paste task template for Luna

Use this as a starting prompt and replace the bracketed parts:

```text
Implement a new Stashd [Input/Broadcast] plugin for [PROVIDER/FORMAT].

Before coding, read in order:
1. docs/plugins/agents.md
2. docs/plugins/authoring.md
3. docs/plugins/manifest.md
4. the relevant WIT in https://github.com/Lost-and-Fonds/plugin-api
5. docs/plugins/php-sdk.md (for current PHP work)
6. the nearest first-party plugin repo ([youtube/podcast/jellyfin/plex])
7. plugin-api/tests/contract/fixtures if touching SDK/wire behavior

Current baseline:
- canonical contract: stashd:plugin@0.2.0
- new manifest api_version: "0.2"
- current PHP SDK: stashd/php-sdk:^0.3
- production runtime: php
- RPC transport: v1

Architecture rules:
- plugin-api/WIT is the canonical language-neutral contract after deliberate
  reconciliation;
- PHP is currently the only production SDK/runtime, but PHP APIs are not the
  contract;
- provider-specific protocols/parsing/semantics stay in the plugin;
- Core owns Vault, persistence, fixity, jobs, filesystem authority, secrets,
  orchestration, and generic capabilities;
- no direct DB/Vault/host filesystem access;
- use narrow host HTTP grants and host-managed credentials;
- contract 0.2 HTTP already supports generic methods, headers, and bodies;
- helpers must be declared and checksum-pinned, not downloaded at runtime;
- use typed plugin failures with explicit retryability;
- do not silently coerce malformed contract DTOs;
- for PHP Broadcasts, every lifecycle method receives PluginContext;
- do not invent a new plugin kind or lifecycle method.

Provider requirements:
[WHAT SOURCES/DESTINATION IT SUPPORTS]
[DISCOVERY/PUBLISHING BEHAVIOUR]
[CREDENTIALS]
[REQUIRED ENDPOINTS/METHODS]
[HELPERS]
[OPTIONS/SETTINGS]
[EXPECTED ERROR CATEGORIES]
[KNOWN EDGE CASES]

Work feature-first and keep Core changes out unless a genuinely generic missing
capability is demonstrated. If Core/contract/SDK changes become necessary,
first inventory the mismatch, then update the boundary, tests, fixtures, and
docs together.

Use the nearest first-party repo for scaffolding/CI/packaging patterns, but do
not copy provider-specific logic or stale dependency versions.

Testing:
- add focused contract/provider fixtures;
- cover canonical IDs, typed errors/retryability, capability failures, options,
  strict DTO behavior, and lifecycle phases;
- run the plugin repo's tests/static analysis/lint;
- if Core is changed, use Core's ./bin/test wrapper;
- if WIT is changed, run plugin-api contract tests and SDK conformance;
- if wire mapping changes, update/replay cross-language fixtures;
- verify OCI materialisation/install if release/package behaviour is touched.

At the end report:
1. behaviour implemented;
2. files changed;
3. manifest/capabilities/helpers;
4. verification actually run;
5. any remaining limitations or architecture debt.
```

## If you are implementing a new language SDK instead

Do not port PHP classes mechanically.

Start from WIT and the language-neutral fixtures. Implement:

1. language-native representations of contract records/enums/variants/results;
2. RPC v1 framing and validated hello/version handshake compatible with Core;
3. host capability proxies with invocation-scoped opaque resources;
4. generic HTTP method/header/body/response-header behavior;
5. lifecycle dispatch for Input and/or Broadcast;
6. typed error mapping with retryability;
7. strict DTO decoding rather than coercion;
8. the shared `plugin-api/tests/contract/fixtures/`;
9. a minimal plugin example;
10. host runtime/package support for the new `runtime` value.

Only after those semantics are covered should you decide what the language's
`PluginContext` equivalent looks like. It may not need one.
