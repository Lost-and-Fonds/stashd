# Native Plugin Runtime Design

Status: design proposal, 2026-08-22. This document does not change the
production Wasmtime runtime or the WIT files. It turns the two bubblewrap
spikes into a bounded implementation plan.

Related evidence:

- `b3c393a` — completed external Broadcast architecture.
- `853ada0` — bubblewrap native-process sandbox spike.
- `317d994` — native PHP Jellyfin lifecycle comparison.

## Decision and scope

Stashd should add a native-plugin runtime alongside the existing Wasmtime
runtime. Native plugins run as ordinary language processes inside bubblewrap;
they communicate with a trusted Stashd runner through private inherited IPC.
WIT remains the semantic, language-neutral public contract. The wire protocol
is an implementation of that contract, not a replacement for it.

The first supported native language is PHP because Stashd already ships and
operates PHP. Other languages may later be self-contained executables or use a
deliberately supported runtime adapter. Stashd does not promise that Python,
Node, Swift, or another interpreter is installed.

This is a Linux-native execution feature. On non-Linux systems, Stashd should
continue to support the existing Wasmtime path or report that native plugins
are unavailable; it should not grow a cross-platform imitation of bubblewrap.

The threat model is buggy, careless, or moderately malicious plugin code. The
design does not claim protection from kernel vulnerabilities, namespace escape
research, or side channels.

## Layering

```text
WIT semantics and versioned contract
          |
generated schema/types + handwritten SDK ergonomics
          |
framed native RPC
          |
trusted runner and capability broker
          |
bubblewrap namespace and process supervision
          |
native plugin process
```

These layers must remain distinct:

- WIT describes records, operations, errors, resources, and lifecycle
  semantics. WIT is not a Wasmtime-only API.
- RPC transports invocations, host capability calls, results, events, and
  resource references. It is not the public semantic model.
- bubblewrap limits the process view and authority. It is not a plugin API.
- PHP core owns authoritative records, secrets, promotion, provenance, jobs,
  and UI/API interpretation.

The plugin sees a normal SDK, not bubblewrap arguments, file descriptors,
framing, the application service container, or Stashd database objects.

## WIT review without editing WIT

The current contract has survived YouTube, Podcast, Jellyfin, and Plex. It
should be treated as a semantic baseline, but native support should expose a
small compatibility review before declaring a long-lived API freeze.

| Concept | Recommendation | Reason |
|---|---|---|
| Plugin metadata and declared capabilities | KEEP AS-IS semantically | Static manifest metadata is needed before invocation; WIT should describe runtime capabilities, not catalog prose. |
| Input/Broadcast lifecycle | KEEP AS-IS | `prepare`, `publish`, `finalize`, and opaque operations match the proven provider lifecycles. |
| Settings, sources, items, resources | KEEP AS-IS | Opaque source identity and plugin-defined settings are the correct boundary. |
| Dynamic choices and operation results | KEEP AS-IS | Generic `{value,label}` choices avoid provider models in core. |
| HTTP methods, headers, body, response | KEEP WITH MINOR GENERALIZATION | Small bodies fit records; large responses need an opaque staged resource rather than a giant byte list. |
| Credential references | KEEP WITH MINOR GENERALIZATION | A credential name/reference must remain opaque; raw secret reads should be exceptional. |
| Published files and derived artifacts | KEEP AS-IS semantically | Core remains responsible for validation, materialization, fixity, and promotion. |
| Staging resources | KEEP WITH MINOR GENERALIZATION | Preserve the resource abstraction, but define file/stream handles for large output. |
| Errors | KEEP AS-IS | Typed plugin errors plus retryability map naturally to SDK exceptions and RPC envelopes. |
| Progress and logging | KEEP AS-IS | They are host events, not UI markup or provider semantics. |
| Helper execution | KEEP AS-IS semantically | Package-local declared helpers fit both Wasm and native hosts. |
| Resource handles | REDESIGN BEFORE FREEZE | Component resource syntax cannot be copied literally into every language; preserve opaque lifetime and authority semantics in SDK/RPC types. |
| Component resource imports/exports | COMPONENT-ABI-SPECIFIC | Native SDKs should expose services such as `HttpClient` and `StagingArea`, while generated bindings preserve the semantic contract. |

No WIT edit is part of this design. The first implementation milestone should
produce a compatibility/schema report and only then propose narrowly scoped
contract changes if a real native case cannot be represented.

## Native RPC v1

### Recommendation

Use a single bidirectional Unix-socketpair or inherited FD channel with a
4-byte big-endian length prefix followed by one UTF-8 JSON envelope. Keep
stdout for ordinary process output only if the runner redirects it; use a
separate stderr pipe for plugin diagnostics. The preferred production shape
is an inherited FD (for example FD 3) so the plugin has no socket path to
discover and no network capability.

Length-prefixed JSON is preferable to NDJSON for v1 because arbitrary log
lines cannot corrupt framing and envelopes may contain escaped newlines. JSON
keeps development inspection easy. CBOR can be introduced behind the same
framing later if measurements prove JSON overhead material; it should not be a
second semantic protocol.

An envelope has this conceptual shape:

```json
{
  "protocol": 1,
  "id": "42",
  "kind": "request",
  "method": "broadcast.publish",
  "params": {}
}
```

Responses contain `result` or a typed `error`; notifications contain no
request ID. IDs are invocation-local and never database IDs. The same channel
is bidirectional: the runner sends lifecycle requests, while the plugin sends
host capability requests. The SDK hides this re-entrancy.

Required v1 message kinds:

- `hello` / `hello-result` with protocol and contract version ranges;
- request/response with IDs;
- capability requests such as `http.request`, `staging.read`,
  `staging.write`, and `credential.use`;
- `log`, `progress`, and cancellation notifications;
- lifecycle results and typed failures.

The runner rejects unknown required protocol versions before running provider
code. Unknown optional fields are ignored; unknown required methods fail
cleanly. EOF, malformed frames, an over-limit frame, and a crashed process
become generic unavailable/plugin-crashed failures, never partial success.

### Bodies, resources, and large files

JSON byte arrays are limited to small control payloads. An HTTP response has an
explicit mode:

- `inline` for bounded JSON/text bodies;
- `resource` for a host-owned stream or staging file with an opaque handle,
  byte count, media type, and expiry tied to the invocation.

The SDK should offer `HttpResponse::body()` only for inline responses and
`HttpResponse::saveToStaging($target)` / a stream reader for resource
responses. The plugin never receives a Vault path. The host can stream the
response directly into job staging, avoiding a JSON copy of a video or large
podcast. Read/write operations are bounded and the host deletes handles when
the invocation ends.

The same resource model applies to selected read-only Vault assets and staged
plugin output. The initial production implementation may choose a host-created
read-only file inside the sandbox for a resource handle, provided the path is
opaque to the semantic API and the mount is invocation-scoped. A chunked RPC
fallback is useful for runtimes that cannot consume a file, but it must remain
bounded and never expose the Vault root.

### Timeout and cancellation

Every request has a host deadline. Plugin cancellation is first cooperative;
the runner then terminates the process group after a short grace period. A
parent death kills the bubblewrap process through `--die-with-parent` and the
runner's process-group supervision. The host owns the final decision to stop.

## PHP SDK v1 design

Recommended independent package: `stashd/plugin-sdk` with namespace
`Stashd\\Plugin`. It targets PHP 8.5, has no Tempest dependency, does not use
Stashd's service container, and can be released independently of the app.

The public surface should remain small:

```php
interface Plugin
{
    public function metadata(): PluginMetadata;
}

interface BroadcastPlugin extends Plugin
{
    public function prepare(PrepareRequest $request): Preparation;
    public function publish(PublishRequest $request): Publication;
    public function finalize(FinalizeRequest $request): Finalization;
    public function operation(OperationRequest $request): OperationResult;
}
```

Input interfaces should be separate and should reuse generic values rather
than pretending Inputs and Broadcasts are identical. The SDK should provide
DTOs for settings, sources, items, resources, preparations, derived artifacts,
publications, published files, operations, choices, errors, and opaque state.

`PluginContext` exposes only capability services:

- `HttpClient` for approved brokered requests;
- `CredentialReference` as an opaque name, never a secret value;
- `StagingArea` and invocation-scoped resource handles;
- `Logger` with redaction-safe structured fields;
- `ProgressReporter`;
- cancellation/deadline observation.

The entrypoint is a package file such as `plugin.php` that returns or registers
a plugin object through a tiny SDK bootstrap. The runner owns loading,
handshake, invocation, and shutdown. Plugin code should look like ordinary PHP
business code: `discoverLibraries()`, `publish()`, and `finalize()` should not
mention bubblewrap, FD numbers, or JSON framing.

SDK exceptions map to the semantic plugin-error variants and carry a retryable
flag, stable code, and redacted message. The SDK must reject attempts to
serialize secrets, host paths, or arbitrary resources into plugin results.

## Package and manifest

An installable release is an immutable archive, for example:

```text
plugin-release.tar.gz
  plugin.json
  plugin.php
  src/
  vendor/
  helpers/
  assets/
```

The manifest contains provider-neutral metadata:

```json
{
  "id": "example",
  "name": "Example",
  "version": "1.0.0",
  "api": {"min": "1.0", "max": "1.x"},
  "runtime": {"kind": "php", "entrypoint": "plugin.php", "php": ">=8.5"},
  "architectures": ["amd64", "arm64"],
  "capabilities": {"http": [], "staging": true},
  "credentials": [],
  "helpers": [],
  "settings": [],
  "operations": []
}
```

The exact schema is intentionally a later implementation artifact. Runtime
kind is an extensible value/object, not a permanently closed enum. PHP package
dependencies are bundled in `vendor/`; the package is read-only at execution.
Required PHP extensions are declared and checked before activation.

Host storage is conceptually:

```text
/data/plugins/packages/<id>/<version>/   immutable unpacked package
/data/plugins/active/<id>                 active version record/pointer
/data/plugins/downloads/                  verified temporary archives
/data/plugins/staging/                    install-time temporary files
/data/plugins/state/                      core-owned opaque plugin state only
/data/jobs/<job>/                         invocation staging
```

Plugin package directories are never plugin-writable. Durable authoritative
plugin configuration and state remain in Stashd's database; a plugin may
return bounded opaque continuation/state data, but cannot create tables or
write a hidden database.

## Installation, trust, update, and development

V1 installation accepts an HTTPS GitHub Release artifact URL or an equivalent
immutable release URL. A repository is a source/development location, not an
installable moving branch. Stashd downloads to a temporary location, checks
archive size and SHA-256, validates the manifest, checks API/runtime/architecture
and extension compatibility, unpacks into a new version directory, runs a
static/conformance validation, then atomically switches the active version.
Git is not required in the production image and there is no central registry
in v1.

Exact version pins and SHA-256 checksums are mandatory for v1. Signatures are a
later trust improvement: official releases may add them, but a full publisher
PKI or reputation marketplace is explicitly out of scope. The UI must display
source URL, version, checksum, requested capabilities, credentials, and
runtime before enabling a package.

Updates never overwrite an active directory. The prior version is retained
until the new version passes validation and a smoke invocation. Rollback is an
atomic pointer switch; failed activation leaves the old version active.
Disable prevents new invocations while allowing existing jobs to finish or be
cancelled. Remove deletes only installed package files after references are
resolved; authoritative Stashd records remain unavailable rather than being
silently destroyed.

Development mode may provide `stashd plugin link /path/to/plugin`. It points an
active development record at a read-only package directory, uses the same
bubblewrap policy, and clearly marks the package as linked. It does not silently
replace a production install and can be unlinked atomically. A normal edit →
run/test loop should not require repacking on every change.

## Bubblewrap and capabilities

The proposed production policy follows the successful spikes:

```text
--die-with-parent --new-session
--unshare-user --unshare-pid --unshare-ipc --unshare-uts --unshare-net
--clearenv
```

The visible filesystem is deliberately constructed:

- required runtime directories read-only;
- `/plugin` read-only;
- per-invocation `/staging` read/write;
- private `/tmp` read/write;
- minimal `/etc` and `/dev`;
- private/empty `/home` and `/run`;
- no Vault, database, application/data directories, host home, or runtime
  sockets;
- no direct network interface or route.

The tested rootless Podman environment could not mount a fresh `/proc`. The
default native policy should therefore omit `/proc`; plugins requiring procfs
are unsupported in v1. Do not weaken the outer container or add a `proc`
permission just for compatibility. This is a documented deployment caveat,
not permission to expose the host proc filesystem.

Capabilities are invocation-scoped grants, not provider-specific booleans:

| Capability | Manifest asks for | Host enforces |
|---|---|---|
| HTTP | origins/connection references and limits | destination, method, headers, redirect, size, timeout, no raw sockets |
| Credential use | opaque credential name and allowed placement | selected credential, destination, protected-header injection, redaction |
| Asset read | selected resource references | read-only bytes/metadata for explicitly granted assets |
| Staging | output/workspace requirement | per-job path, quota, cleanup, promotion descriptors |
| Helper | package-local helper names | declared executable, safe args, same sandbox, no shell |
| Logs/progress | event capability | redacted structured events and rate limits |

The runner logs capability denials without secrets. The plugin cannot expand a
grant by passing an absolute path, changing a host name, or naming another
plugin's helper.

## HTTP and credentials

The plugin requests an HTTP operation; it does not open a socket. The host
checks an exact scheme/host/port or a Connection-derived endpoint, resolves
DNS under host policy, enforces response and timeout limits, and performs the
request. Redirects are disabled by default; if enabled later, each target is
re-authorized. Plugin headers cannot override host-protected credential
headers. Cookies, raw sockets, listening sockets, and unrestricted URL fetches
are not v1 capabilities.

Small JSON/text responses may be inline. Large responses stream to a
host-managed resource or staging file and are consumed through an opaque SDK
handle. This avoids buffering media in RPC JSON.

Credentials remain encrypted in Stashd. A plugin references a manifest-declared
credential by opaque name. The host injects a header, bearer, basic-auth, or
query value only when the selected placement and destination are allowed. The
plugin normally never receives the raw value. Placement shape belongs to the
manifest/plugin request; the host enforces the security policy and redacts
logs. Raw secret reads require a separate explicit capability and are not part
of v1's normal path.

## Staging, Vault, and helpers

The authoritative flow is:

```text
explicit read capability or generic item reference
        ↓
plugin reads/derives into invocation staging
        ↓
plugin returns descriptor and provenance references
        ↓
core validates bytes, path, media type, size, and ownership
        ↓
core hashes, records provenance/fixity, and promotes if appropriate
```

Plugins never receive a Vault root or authoritative path. A selected Asset is
exposed as an invocation-scoped opaque read handle, implemented initially as a
read-only sandbox resource or bounded stream. Staged output is deleted on
failure, crash, timeout, and cancellation; successful promotion is still a
core decision.

Helpers are package-local declarations such as `helpers/ffmpeg`. The host
resolves the name relative to the immutable active package, rejects traversal
and absolute paths, and launches the helper under the same bubblewrap policy,
environment, network denial, staging grant, and timeout. Helpers are trusted
installed software but cannot write directly to Vault.

## Lifecycle and supervision

Input invocations retain the established separation:

```text
resolve/recognise (optional)
  → validate/discover
  → return candidates and opaque continuation state
  → describe or perform acquisition into staging
  → core validates and promotes
```

Broadcast invocations retain:

```text
prepare
  → derived staged artifacts
publish
  → publication descriptors
core materializes/verifies authoritative outputs
finalize
  → post-materialization external side effects
```

Opaque operations are separate user/system entry points. Progress and logs are
events, not authoritative state changes. PHP persists job state and opaque
plugin continuation data.

The runner starts one supervised process per invocation for v1. It owns:

- package/runtime selection and environment construction;
- process group and parent-death handling;
- soft deadline/cancellation followed by hard kill;
- frame and body size limits;
- stdout/stderr capture and redaction;
- crash/EOF/malformed-protocol classification;
- staging cleanup;
- bounded concurrent invocation count.

Basic host deadlines are v1. Memory, CPU, and process-count limits should use
the outer container and the simplest available per-process controls first;
cgroup-based quotas are later hardening, not a second scheduler. A plugin
cannot veto cancellation or retain a process after its invocation ends.

## Discovery, UI, and versioning

Static metadata is read without launching code: ID, name, version, runtime,
API compatibility, capabilities, required extensions, settings schema,
credential declarations, operations, and requested permissions. Dynamic
choices and operation results require an invocation and are passed through as
generic values/choices. No plugin ships routes, HTML, Vue, JavaScript, or
navigation.

Keep separate version axes:

1. package version — provider release and rollback identity;
2. plugin API/WIT version — semantic compatibility;
3. SDK version — implementation convenience, constrained by API version.

The hello exchange negotiates protocol version. Stashd rejects incompatible
API ranges, unsupported architecture/runtime, missing PHP extensions, and
packages with capabilities the host cannot enforce. The plugin cannot declare
that an unsupported capability is optional after activation.

## WIT to SDK and other languages

WIT should feed a schema/binding generation step that produces records,
variants, optionals, result/error codecs, and protocol validation. Handwritten
SDK code should provide ergonomic contexts, HTTP methods, staging helpers,
exceptions, bootstrap, progress, and resource lifetime management. Plugin
authors should not work directly with generated wire structs unless debugging.

A future Python SDK can use the same generated records/codecs and a small
convenience layer. Go, Rust, Zig, or Swift plugins can be self-contained
executables using generated types or a tiny protocol library. The wire format
must not contain PHP serialization, PHP class names, or Tempest concepts.

The existing WIT concepts are capable of remaining canonical over RPC:
records, enums/variants, lists, options, results/errors, opaque resources,
host capability calls, and progress notifications all have direct mappings.
Resource lifetimes, large-body handles, and re-entrant callbacks need explicit
SDK conventions; they do not require a second semantic contract.

## Conformance and test ownership

The test layers should be:

1. core runner and bubblewrap isolation tests;
2. wire protocol framing/limits/crash/cancellation tests;
3. PHP SDK unit tests;
4. provider-neutral example-plugin conformance tests;
5. capability/security tests using example components;
6. plugin-owned semantic tests and fixtures;
7. a small cross-boundary installation smoke for each bundled plugin.

Core must test generic lifecycle, staging, credential isolation, HTTP grants,
opaque choices, source settings, publication safety, and failure ordering. It
must not assert Jellyfin endpoints, Plex XML, Podcast XML, FFmpeg profiles, or
provider naming. Removing a plugin should remove its semantic tests without
invalidating the core conformance suite.

Every SDK/runtime combination runs the same conformance cases: manifest and
hello, invocation, settings, operations, choices, HTTP denial/grant,
credential non-disclosure, staging, publication descriptors, errors,
progress/logging, timeout/crash cleanup, and selected-resource isolation.

## Maintenance and deployment assessment

Native execution adds ongoing responsibilities: bubblewrap policy, supported
PHP runtime/extensions, process supervision, RPC compatibility, package
verification, helper architecture builds, and Linux/container compatibility.
Wasmtime retains responsibilities for the Rust host, component toolchains,
WASI constraints, Wasmtime upgrades, and Component packaging. The native model
materially improves PHP authoring, stack traces, fixture testing, and the
edit–run loop; it does not eliminate host complexity. The native runner is
one-time infrastructure, not per-plugin boilerplate. Jellyfin's comparison
showed normal PHP provider code can remain small and recognizable.

The target deployment remains `docker compose up`: bubblewrap and the supported
PHP runtime are bundled in the image. No privileged mode, `CAP_SYS_ADMIN`,
Docker/Podman socket, host daemon, or host runtime installation is acceptable.
Unprivileged user namespaces and the tested `/proc` caveat must be documented.
amd64 and arm64 images/helpers require CI/package coverage; the plugin API and
PHP source are architecture-neutral.

## Security statement

The eventual documentation may say:

> Native plugins execute as processes inside a restricted Linux namespace with
> only explicitly exposed runtime and package files, private temporary
> storage, invocation staging, and brokered host capabilities. Plugins do not
> receive direct access to the Vault, Stashd database, application secrets, or
> unrestricted network access. This boundary is intended for buggy or
> moderately malicious plugins on a shared kernel; it is not a defense against
> kernel vulnerabilities or namespace-escape research.

## Explicit v1 non-goals

- plugin marketplace, reputation system, or central registry;
- automatic publisher PKI or mandatory signing infrastructure;
- arbitrary plugin frontend code, routes, pages, or navigation;
- runtime distribution for every language;
- native plugin execution on Windows/macOS;
- raw database access, arbitrary filesystem access, arbitrary shell, or
  plugin-to-plugin RPC;
- arbitrary durable plugin filesystems;
- distributed/remote plugin execution;
- gRPC, a workflow DSL, or a general event bus;
- hot reload guarantees for every language;
- kernel-exploit or microVM isolation;
- production migration of any existing plugin in this design task.

## Open questions that remain real

1. **Exact framing codec:** length-prefixed JSON is recommended; a clean
   benchmark and generated-code review should decide whether CBOR is worth
   adding before protocol v1 freeze.
2. **Large-resource API:** decide between host-created read-only files and
   chunked handles after testing PHP, a compiled executable, and crash cleanup.
3. **Credential placement:** define the minimum manifest/request vocabulary for
   header, bearer, basic, and query injection without allowing auth-header
   spoofing.
4. **`/proc` policy:** keep it absent by default; gather evidence from real
   plugin runtimes before considering any narrowly declared compatibility mode.
5. **Resource quotas:** use deadlines and outer-container limits first; decide
   later whether per-invocation cgroups are needed.
6. **Release signatures:** SHA-256 plus HTTPS is the v1 minimum; decide when
   official publisher signatures justify their operational cost.
7. **PHP extension policy:** decide whether a plugin may request an extension
   already in the Stashd image or whether extension-dependent packages are
   rejected until image variants exist.

These questions do not reopen the runtime decision. Each has a bounded test or
implementation milestone below.

