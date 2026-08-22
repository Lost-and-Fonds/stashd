# Native Plugin Runtime Implementation Roadmap

This roadmap is intentionally incremental. Wasmtime remains the production
runtime throughout the migration. No milestone removes it until native parity,
rollback, and operational evidence are complete.

## M0 — Freeze the design and compatibility inventory

**Goal:** turn the existing WIT and spike evidence into a machine-checkable
compatibility target.

**Scope:** inventory current Input/Broadcast exports, map WIT types to proposed
RPC envelopes, document capability grants and limits, and record the `/proc`
caveat.

**Explicit exclusions:** no WIT edits, native runner, SDK, plugin port, or
installer.

**Tests:** static manifest/WIT inventory; review against the bubblewrap and
Jellyfin spike reports.

**Success:** every current lifecycle and capability has an owner and a proposed
wire representation; unresolved items are only the seven bounded questions in
the design document.

**Hard stop:** stop and resolve a semantic gap before coding if an existing
plugin behavior cannot be described without provider-specific core concepts.

## M1 — Native runner and sandbox skeleton

**Goal:** launch a trivial native executable/PHP entrypoint with the proven
bubblewrap policy.

**Scope:** runner process, package root selection, read-only package mount,
per-job staging, private tmp, clear environment, namespace flags, parent death,
and basic timeout cleanup.

**Explicit exclusions:** RPC semantics, HTTP, credentials, SDK, installation,
and production integration.

**Tests:** Linux amd64 container test; no Vault/app/data/env leakage; plugin
package immutability; staging/tmp writes; direct network denial; no privileged
container or runtime socket.

**Success:** a clean image runs the example process in the same policy as the
spike, and failures clean staging.

**Hard stop:** reject native runtime if this requires `--privileged`,
`CAP_SYS_ADMIN`, a host socket/daemon, or broad seccomp weakening.

## M2 — RPC v1 and protocol conformance fixture

**Goal:** make a boring, bidirectional, language-neutral framed transport.

**Scope:** hello/version negotiation, length limits, request IDs,
request/response, notifications, EOF/malformed-frame/crash errors, stderr
separation, progress/log events, and cancellation.

**Explicit exclusions:** real provider lifecycle, large media, credentials,
installer, and generated SDKs.

**Tests:** PHP and tiny non-PHP fixture clients; frame fuzz/limit cases;
timeout/kill; protocol-version mismatch; diagnostics cannot corrupt frames.

**Success:** two small clients can complete a request and a host capability call
without knowing PHP-specific details.

**Hard stop:** do not add a second transport or gRPC to compensate for an
unclear envelope; fix the protocol model first.

## M3 — WIT schema/codegen bridge

**Goal:** prove WIT can remain canonical while native RPC uses generated types.

**Scope:** extract/generate records, variants, options, results/errors and
serialization validation for the currently existing contract; add a protocol
compatibility report.

**Explicit exclusions:** WIT redesign, full SDK ergonomics, provider behavior,
and support for every target language.

**Tests:** round trips for all current values; unknown optional/required fields;
golden messages; comparison with existing Component shapes.

**Success:** generated data is transport-neutral and no generated type refers
to Wasmtime-only runtime objects.

**Hard stop:** if a concrete semantic gap appears, document the smallest WIT
proposal and pause before implementing a workaround in RPC.

## M4 — PHP SDK and example plugin

**Goal:** make native plugin code ordinary PHP.

**Scope:** `stashd/plugin-sdk`, bootstrap, `PluginContext`, lifecycle DTOs,
HTTP/staging/log/progress interfaces as stubs or safe test services, SDK error
mapping, and a provider-neutral example plugin.

**Explicit exclusions:** Tempest dependency, production plugin ports, arbitrary
PHP extensions, package marketplace, and a public SDK release process.

**Tests:** SDK unit tests, example conformance, stack traces, malformed plugin
result handling, and same-policy edit–run development workflow.

**Success:** example plugin author never handles framing, FDs, bubblewrap, or
the service container.

**Hard stop:** do not add convenience abstractions until the example requires
them; keep generated types behind the SDK.

## M5 — Generic broker capabilities

**Goal:** implement the security-sensitive host services needed by real
plugins.

**Scope:** approved HTTP, credential-use injection without raw secret return,
small inline responses, large response/resource-to-staging path, selected
read-only asset handles, staging output descriptors, logs/progress, and
package-local helpers.

**Explicit exclusions:** arbitrary shell, raw secret reads, direct networking,
database APIs, plugin-to-plugin calls, and generic media/transcode DSLs.

**Tests:** allow/deny origins, redirects, protected headers, credential
redaction, large response cleanup, Vault canary, path traversal, helper
containment, failed hashing/promotion, and cancellation.

**Success:** the same example plugin can use a brokered resource and return a
descriptor that core can validate without receiving a Vault path.

**Hard stop:** no capability ships if its authority cannot be bounded to one
invocation and audited.

## M6 — Package validation and local linking

**Goal:** make package lifecycle safe before porting a real provider.

**Scope:** manifest validation, runtime/architecture/API checks, immutable
version directories, checksum verification, active-version switch, rollback,
disable/remove behavior, and development `plugin link` using the same sandbox.

**Explicit exclusions:** central registry, automatic updates, marketplace,
publisher PKI, and plugin-owned durable storage.

**Tests:** corrupt archive, incompatible manifest, missing extension, failed
activation, atomic rollback, linked-package isolation, and absent-plugin boot.

**Success:** a failed install cannot replace the working version and a linked
package cannot write its source tree.

**Hard stop:** do not port providers while activation can silently lose the
last working package.

## M7 — Native conformance and operational smoke

**Goal:** prove the runtime independent of first-party provider semantics.

**Scope:** reusable conformance suite, example Input/Broadcast fixtures,
Docker/Podman smoke, PHP/PostgreSQL job integration, basic metrics/logging,
and amd64/arm64 packaging review.

**Explicit exclusions:** removing Wasmtime, production provider registration,
and UI redesign.

**Tests:** full sandbox/conformance matrix, crash/retry/rebuild, PostgreSQL,
fresh container lifecycle, and no-plugin core boot.

**Success:** core can run a generic external contribution with the same
authoritative staging/promotion rules as current Wasmtime plugins.

**Hard stop:** pause if native execution changes core authority or requires a
provider-specific exception.

## M8 — Port Jellyfin Broadcast

**Goal:** port the already-understood, low-risk real lifecycle first.

**Scope:** native Jellyfin package, generic connection/credential operation,
library choices, publication descriptors, core materialization, and POST
refresh finalization.

**Explicit exclusions:** changing Jellyfin semantics, changing WIT, deleting
the Component, or moving other providers.

**Tests:** plugin-owned fake Jellyfin protocol tests; core generic smoke;
SQLite/PostgreSQL lifecycle; auth failure; refresh ordering; rebuild; rollback
to Wasmtime.

**Success:** native and Wasmtime implementations are feature-equivalent for
the existing contract and can be selected per installation.

**Hard stop:** keep Wasmtime active if any parity failure cannot be isolated to
plugin-owned behavior.

## M9 — Port Plex Broadcast

**Goal:** prove a second remote protocol and XML/provider policy can remain
plugin-owned.

**Scope:** native Plex package, opaque operations, credentials, XML parsing,
publication layout, captions/NFO, and finalize refresh.

**Explicit exclusions:** shared media-server abstraction, frontend provider
branches, and Component removal.

**Tests:** plugin-owned Plex fixtures plus generic publication, failure,
rebuild, and PostgreSQL tests.

**Success:** Plex has no PHP protocol adapter and native/Wasmtime selection is
reversible.

**Hard stop:** do not generalize Plex and Jellyfin semantics into core to fix a
plugin test.

## M10 — Port Podcast Broadcast

**Goal:** prove derived artifacts and a plugin-local native helper.

**Scope:** native Podcast package, FFmpeg helper declaration, audio derivation,
staging/promotion/provenance/dedup, feed publication, and finalization.

**Explicit exclusions:** global FFmpeg, core media profiles, and a generic
transcode framework.

**Tests:** plugin-owned feed/FFmpeg tests; core derived-asset tests; helper
failure cleanup; real video-to-audio lifecycle; SQLite/PostgreSQL rebuild.

**Success:** native Podcast can use its package helper without changing core
knowledge or authority.

**Hard stop:** no helper exception may expose Vault or direct network.

## M11 — Port YouTube Input

**Goal:** prove the Input side, including acquisition complexity, after the
native Broadcast path is stable.

**Scope:** native YouTube discovery/acquisition contribution, approved HTTP and
credential use, optional package-local helper/runtime strategy, staged output,
opaque continuation state, progress/cancellation, and core promotion.

**Explicit exclusions:** promising Python/Node support, global yt-dlp/Deno
cleanup as a separate project, and changing Input semantics to fit Broadcasts.

**Tests:** plugin-owned provider tests; core discovery/acquisition conformance;
large-body staging; retry/cancel; PostgreSQL lifecycle; Docker smoke.

**Success:** native YouTube is reversible per input and no source/provider
semantics move into core.

**Hard stop:** if yt-dlp packaging requires a guaranteed interpreter/runtime,
design that runtime adapter separately rather than weakening the PHP-first
policy.

## M12 — Parity, deprecation, and later Wasmtime removal decision

**Goal:** decide whether native can become the default without a flag day.

**Scope:** long-running migration telemetry, full first-party conformance,
upgrade/rollback documentation, default selection policy, and an explicit
removal proposal for Wasmtime if evidence supports it.

**Explicit exclusions:** automatic deletion of the Wasmtime path in the same
change; new plugin ports; RSS Input.

**Tests:** full suite, upgrade from existing installations, all provider
rebuilds, absent/native/Wasmtime combinations, and fresh lerd/container runs.

**Success:** every plugin can be rolled back independently and native has no
known contract/security/deployment regression.

**Hard stop:** retain Wasmtime as a supported production fallback if parity or
deployment evidence is incomplete.

## When RSS Input resumes

RSS Input work should resume only after M7 is complete and M8 has passed the
first real native-provider parity gate. At that point the runtime has a tested
generic contract, PHP authoring path, package rollback, staging/capabilities,
and one real provider migration. RSS can then be designed against the stable
native contract without being used to discover basic runner behavior.

