# PHP SDK for Stashd plugins

The PHP SDK is the current author-facing implementation of the Stashd plugin
contract. It is intentionally **not** the canonical contract and should not be
used to impose PHP concepts on future SDKs.

Canonical semantics live in
[`Lost-and-Fonds/plugin-api`](https://github.com/Lost-and-Fonds/plugin-api).
The PHP package lives in
[`Lost-and-Fonds/stashd-php-sdk`](https://github.com/Lost-and-Fonds/stashd-php-sdk).

## What the SDK does

The SDK provides:

- PHP DTOs corresponding to contract values;
- Input and Broadcast lifecycle interfaces;
- language-native capability interfaces;
- mapping between PHP values and the transport-neutral wire representation;
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

The first-party YouTube plugin is the best complete Input reference.

## Broadcast interface

Current signature:

```php
interface BroadcastPlugin
{
    public function prepare(PublishRequest $request): Preparation;

    public function publish(PublishRequest $request): Publication;

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

The `PluginContext` arguments on `finalize` and `operation` are PHP SDK
conveniences. WIT's Broadcast interface takes only the request values; host
capabilities are imported separately. The SDK combines those concepts into an
ergonomic PHP object.

Likewise, capability-backed staging/helper/progress objects can be attached to
request DTOs by the SDK mapper for `prepare` and `publish`. Do not copy the
exact PHP injection strategy into another SDK unless it suits that language.

## `PluginContext`

Current PHP context exposes:

```php
final readonly class PluginContext
{
    public function __construct(
        public Logger $logger,
        public ProgressReporter $progress,
        public HttpClient $http,
        public ?StagingArea $staging,
        public ?HelperRunner $helpers,
    ) {}
}
```

The concrete runtime versions of these objects are RPC proxies back to the host.
They are not direct handles to host services.

Use them rather than Guzzle/cURL/process/filesystem work that attempts to bypass
the plugin boundary.

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
    "stashd/php-sdk": "^0.2"
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

Use the currently compatible SDK release rather than copying the example
constraint literally. First-party plugin repositories are the best source for
the current Composer/CI boilerplate.

`composer.lock` is required by the current OCI builder; commit it.

## Minimal Broadcast implementation

The SDK repository contains an intentionally tiny Broadcast example. In current
API terms its shape is:

```php
namespace Example;

use Stashd\PluginSdk as Sdk;

final class ExampleBroadcast implements Sdk\BroadcastPlugin
{
    public function prepare(Sdk\PublishRequest $request): Sdk\Preparation
    {
        return new Sdk\Preparation();
    }

    public function publish(Sdk\PublishRequest $request): Sdk\Publication
    {
        // Real plugins should write/stage a meaningful artifact.
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

## Option values and DTOs

The SDK wraps the WIT option-value variant in PHP types. Prefer SDK conversion
helpers/value objects rather than passing loose arrays inside plugin business
logic. Loose arrays belong at the wire/fixture boundary.

The same rule applies to `DiscoveredItem`, `ResolvedInput`, `PublishRequest`,
`Publication`, `StagedArtifact`, and the other DTOs: construct contract values
explicitly so tests catch drift.

## Capabilities in PHP

### HTTP

Use `$context->http` (or capability-bearing request objects supplied by the SDK)
to make authorised provider requests. The runtime turns those calls into RPC
requests and Core enforces the manifest's grants/credentials.

### Staging

Use the SDK `StagingArea`; never assume `/staging` path access is the public API
just because the sandbox happens to mount it there. The capability is the
contract and can enforce safe relative references.

### Helpers

Use `HelperRunner`/staging helper methods. Helpers must be declared and pinned
in the package; plugin code should not discover arbitrary host executables.

### Log/progress

Use the SDK interfaces so messages are associated with the invocation/job.
Never include credentials.

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
The plugin server initiates a `hello` request before normal dispatch.

The transport's JSON value mapping follows the generated compatibility report
from `plugin-api`:

- scalars → JSON scalars;
- records → objects;
- lists → arrays;
- enums → strings;
- variants → `{"tag": "...", "value": ...}` when payload-bearing;
- results → exactly one of `{"ok": ...}` or `{"error": ...}`;
- resource references remain opaque and invocation-scoped.

Do not make RPC v1 the semantic authority. If transport and WIT disagree, fix
the transport/mapper or deliberately version the contract.

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

When the PHP SDK changes contract mapping, verify it against `plugin-api`, not
just against first-party plugins.

## Current rough edges to remember

These are implementation facts, not design guidance:

- Production execution currently launches PHP explicitly; another SDK requires
  a corresponding host runtime path.
- Input exception-to-error mapping is presently heuristic.
- Broadcast uncaught exceptions currently collapse to a generic plugin failure.
- The SDK is independently versioned from `api_version`.
- The SDK's `PluginContext` is an ergonomic façade, not an ABI object.

Agents should not “standardise” those quirks by copying them into the WIT.
