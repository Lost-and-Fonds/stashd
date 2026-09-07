# PHP SDK for Stashd plugins

The PHP SDK is the current author-facing implementation of the Stashd plugin
contract. It is intentionally **not** the canonical contract and should not be
used to impose PHP concepts on future SDKs.

Canonical semantics live in
[`Lost-and-Fonds/plugin-api`](https://github.com/Lost-and-Fonds/plugin-api).
The PHP package lives in
[`Lost-and-Fonds/stashd-php-sdk`](https://github.com/Lost-and-Fonds/stashd-php-sdk).

Current compatibility:

```text
contract    stashd:plugin@0.2.0
manifest    api_version: "0.2"
PHP SDK     0.3.x
runtime     php
RPC         v1
```

Core currently accepts legacy `api_version: "0.1"` manifests during migration,
but new PHP plugins should target contract 0.2 and SDK `^0.3`.

## What the SDK does

The SDK provides:

- PHP DTOs corresponding to contract values;
- Input and Broadcast lifecycle interfaces;
- language-native capability interfaces;
- typed contract failures;
- strict mapping between PHP values and the wire representation;
- the framed RPC bootstrap/server used by a PHP plugin process.

It deliberately does not own:

- process supervision;
- Bubblewrap policy;
- package installation/activation;
- Vault or database access;
- provider semantics;
- Core application orchestration.

The host mounts the SDK read-only at `/sdk` during an invocation.

## Input interface

Current signature:

```php
interface InputPlugin
{
    public function resolve(SourceDescriptor $source): ResolvedInput;

    /** @return list<DiscoveredItem> */
    public function discover(
        string $inputId,
        DiscoveryIntent $intent,
        array $options = [],
    ): array;

    public function acquire(
        DiscoveredItem $item,
        AcquisitionOptions $options,
    ): AcquisitionResult;
}
```

The Input server is created with a factory that receives a `PluginContext`:

```php
require_once '/sdk/bootstrap.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Stashd\PluginSdk\Runtime\InputPluginServer;

(new InputPluginServer(
    static fn($context): ExampleInput => new ExampleInput($context),
))->run();
```

The server performs the RPC handshake, builds host capability proxies, creates
the plugin, maps wire values to DTOs, and dispatches `resolve`, `discover`, and
`acquire`.

This constructor/factory context is a PHP binding for the Input world's imported
host capabilities. It is not a WIT lifecycle parameter.

The first-party YouTube plugin is the best complete Input reference.

## Broadcast interface

PHP SDK 0.3 passes an invocation-scoped `PluginContext` to **every** Broadcast
lifecycle method:

```php
interface BroadcastPlugin
{
    public function prepare(
        PublishRequest $request,
        PluginContext $context,
    ): Preparation;

    public function publish(
        PublishRequest $request,
        PluginContext $context,
    ): Publication;

    public function finalize(
        FinalizationRequest $request,
        PluginContext $context,
    ): Publication;

    public function operation(
        OperationRequest $request,
        PluginContext $context,
    ): OperationResult;
}
```

A typical entrypoint is:

```php
require_once '/sdk/bootstrap.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Stashd\PluginSdk\Runtime\PluginServer;

(new PluginServer(new ExampleBroadcast()))->run();
```

WIT's Broadcast lifecycle functions still take only their contract request
values. The `broadcast-world` imports host capabilities separately. PHP's
`PluginContext` combines those imported capabilities into an ergonomic object
passed beside each request.

That distinction matters for future SDKs: reproduce the capability semantics,
not necessarily the PHP method shape.

`PublishRequest` is contract data only. Staging, helpers, progress, logging, and
HTTP are no longer smuggled into request DTOs.

## `PluginContext`

Current PHP context exposes:

```php
final readonly class PluginContext
{
    public function __construct(
        public Logger $logger = new NullLogger(),
        public ProgressReporter $progress = new NullProgressReporter(),
        public HttpClient $http = new UnavailableHttpClient(),
        public ?StagingArea $staging = null,
        public ?HelperRunner $helpers = null,
    ) {}
}
```

The concrete runtime versions of these objects are RPC proxies back to the host.
They are not direct handles to host services.

Use them rather than Guzzle/cURL/process/filesystem work that attempts to bypass
the plugin boundary.

The defaults are useful in focused tests, but production capability availability
is still determined by Core and the invocation/manifest grants.

## Repository skeleton

For a new PHP plugin, begin with:

```text
example/
├── AGENTS.md
├── README.md
├── composer.json
├── composer.lock
├── phpstan.neon
├── src/
│   └── ExampleInput.php
├── stashd-plugin/
│   ├── helpers.lock.json
│   ├── plugin.json
│   └── plugin.php
└── tests/
    ├── Pest.php
    └── Feature/
        └── ContractTest.php
```

For a Broadcast substitute an appropriate implementation class.

A minimal `composer.json` follows the first-party shape:

```json
{
  "name": "stashd/example",
  "description": "Example plugin for Stashd",
  "type": "stashd-plugin",
  "license": "MIT",
  "require": {
    "php": "^8.5",
    "stashd/php-sdk": "^0.3"
  },
  "autoload": {
    "psr-4": {
      "Example\\": "src/"
    }
  },
  "extra": {
    "stashd-plugin": {
      "manifest": "stashd-plugin/plugin.json",
      "entrypoint": "stashd-plugin/plugin.php"
    }
  }
}
```

Use the currently compatible SDK release rather than copying an old lockfile or
release SHA. First-party plugin repositories are the best source for current
Composer/CI boilerplate.

`composer.lock` is required by the current OCI builder; commit it.

## Minimal Broadcast implementation

The SDK repository contains an intentionally tiny Broadcast example. In current
API terms its shape is:

```php
namespace Example;

use Stashd\PluginSdk as Sdk;

final class ExampleBroadcast implements Sdk\BroadcastPlugin
{
    public function prepare(
        Sdk\PublishRequest $request,
        Sdk\PluginContext $context,
    ): Sdk\Preparation {
        return new Sdk\Preparation();
    }

    public function publish(
        Sdk\PublishRequest $request,
        Sdk\PluginContext $context,
    ): Sdk\Publication {
        // Real plugins should use $context->staging to create a useful artifact.
        return new Sdk\Publication(new Sdk\Artifact(''));
    }

    public function finalize(
        Sdk\FinalizationRequest $request,
        Sdk\PluginContext $context,
    ): Sdk\Publication {
        return $request->publication;
    }

    public function operation(
        Sdk\OperationRequest $request,
        Sdk\PluginContext $context,
    ): Sdk\OperationResult {
        return new Sdk\OperationResult();
    }
}
```

Use this only to understand required methods. The first-party Podcast plugin is
a better behavioural example.

## Typed plugin failures

Contract 0.2 defines the same plugin failure categories for Input and Broadcast:

```text
unsupported
not-found
authentication
rate-limited
unavailable
invalid-data
failed
```

The SDK models them with `PluginErrorCode`, `PluginError`, `PluginFailure`, and
`PluginFailureException`.

An intentional provider failure can be raised explicitly:

```php
use Stashd\PluginSdk\PluginError;
use Stashd\PluginSdk\PluginErrorCode;
use Stashd\PluginSdk\PluginFailure;
use Stashd\PluginSdk\PluginFailureException;

throw new PluginFailureException(
    new PluginFailure(
        PluginErrorCode::RateLimited,
        new PluginError('Remote quota exceeded.', retryable: true),
    ),
);
```

The runtime serializes that as the contract's `{tag,value}` error variant while
preserving retryability.

Ordinary uncategorized exceptions become `failed` with `retryable: false`.
Capability-unavailable exceptions become retryable `unavailable` failures.
Do not encode failure categories into exception message text; the old heuristic
mapping is gone.

Core still accepts legacy flat error objects for migration compatibility with
0.1 plugins, but current SDKs should emit typed failures.

## Option values and DTOs

The SDK wraps WIT option-value variants in PHP types. Prefer SDK conversion
helpers/value objects rather than passing loose arrays inside plugin business
logic. Loose arrays belong at the wire/fixture boundary.

PHP SDK 0.3 decodes contract values strictly. It rejects malformed required
fields, lists, option variants, nullable fields with the wrong type, and invalid
integer representations instead of silently coercing them.

For example, a numeric option value must arrive as an integer; the SDK does not
turn the string `"7"` into `7` for you.

The same rule applies to `DiscoveredItem`, `ResolvedInput`, `PublishRequest`,
`Publication`, `StagedArtifact`, and the other DTOs: construct contract values
explicitly so tests catch drift.

## Capabilities in PHP

### HTTP

The PHP interface is currently:

```php
interface HttpClient
{
    /** @param array<string, string> $headers */
    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        ?string $credential = null,
    ): HttpResponse;
}
```

Use `$context->http` to make authorised provider requests. The runtime turns
those calls into the language-neutral HTTP capability request and Core enforces
the manifest's grants/credentials.

Contract 0.2 exposes generic methods, request headers/body, and response headers.
Use that generic surface rather than adding provider-specific networking to Core.

### Staging

Use the SDK `StagingArea`; never assume `/staging` path access is the public API
just because the sandbox happens to mount it there. The capability is the
contract and can enforce safe relative references.

### Helpers

Use `HelperRunner`/staging helper methods. Helpers must be declared and pinned
in the package; plugin code should not discover arbitrary host executables.

### Log/progress

Use the SDK interfaces so messages are associated with the invocation/job.
Progress supports an optional fraction. Never include credentials in either
logs or progress messages.

## RPC transport (for SDK maintainers)

Ordinary PHP plugin authors should never need to implement framing. Future SDK
authors may.

Production RPC v1 frames are:

```text
4-byte unsigned big-endian JSON byte length
UTF-8 JSON object payload
```

Core currently caps a single frame at 268,435,456 bytes. Messages carry
`protocol`, `id`, `kind`, `method`, and `params`/`result`/`error` as appropriate.

The plugin server initiates a `hello` request advertising `min: 1, max: 1`. The
SDK validates that Core replies to the same request ID with a response selecting
protocol range 1 before dispatch continues.

The transport's JSON value mapping follows the compatibility rules from
`plugin-api`:

- scalars → JSON scalars;
- records → objects;
- lists → arrays;
- enums → strings;
- variants → `{"tag": "...", "value": ...}` when payload-bearing;
- results → exactly one of `{"ok": ...}` or `{"error": ...}`;
- resource references remain opaque and invocation-scoped.

Inline WIT byte values use the host's current JSON string representation in RPC
capability payloads. Chunks returned by `resource.read` use base64.

Do not make RPC v1 the semantic authority. If transport and WIT disagree, fix
the transport/mapper or deliberately version/reconcile the contract.

## Conformance fixtures

`plugin-api/tests/contract/fixtures/` contains language-neutral JSON fixtures for
representative Input/Broadcast lifecycle messages, capability traffic, and typed
retryable failures.

Use these when changing SDK mapping. A future SDK should be able to replay the
same fixtures without depending on PHP classes.

## Tests and verification

Run the PHP SDK's own checks with:

```bash
composer test
```

A plugin repository should normally expose its own:

```bash
composer test
composer test:static
composer lint
```

(or whatever scripts that repository actually defines).

Contract-focused tests should construct DTOs/capability fakes and exercise the
provider behaviour directly. Keep the majority of plugin tests independent of
a running Stashd Core; add packaged/integration smoke tests for the boundary,
not for every provider branch.

When the PHP SDK changes contract mapping, verify it against `plugin-api` and
the shared conformance fixtures, not just against first-party plugins.

## Current implementation facts to remember

These are implementation facts, not design guidance:

- Production execution currently launches PHP explicitly; another SDK requires
  a corresponding host runtime path.
- The SDK is independently versioned from `api_version`.
- `PluginContext` is a PHP ergonomic façade over invocation-scoped host
  capabilities, not an ABI object.
- Input receives that context through its factory; Broadcast receives it on all
  four lifecycle methods.
- Typed errors and strict DTO decoding are part of the current SDK surface; do
  not reintroduce message heuristics or permissive coercion for convenience.

Agents should not “standardise” PHP ergonomics by copying them into WIT. Future
SDKs should implement the language-neutral semantics first and choose their own
idiomatic binding second.
