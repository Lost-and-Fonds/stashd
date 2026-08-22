<?php

declare(strict_types=1);

namespace Stashd\PluginSdk;

use RuntimeException;

final class OptionValue
{
    private function __construct(public readonly string $kind, public readonly bool|int|string $value) {}

    public static function boolean(bool $value): self { return new self('boolean', $value); }
    public static function number(int $value): self { return new self('number', $value); }
    public static function text(string $value): self { return new self('text', $value); }

    public function toWire(): array { return ['tag' => $this->kind, 'value' => $this->value]; }
}

final readonly class Setting
{
    public function __construct(public string $key, public OptionValue $value) {}
}

final readonly class ItemResource
{
    public function __construct(
        public string $reference,
        public string $kind,
        public ?string $derivationKey = null,
        public ?string $url = null,
        public ?string $mediaType = null,
        public int $sizeBytes = 0,
    ) {}
}

final readonly class Item
{
    /** @param list<ItemResource> $resources */
    public function __construct(
        public string $id,
        public string $title,
        public array $resources = [],
        public ?string $sourceReference = null,
        public ?string $description = null,
        public ?string $publishedAt = null,
        public ?int $durationSeconds = null,
    ) {}
}

final readonly class Source
{
    /** @param list<Setting> $settings */
    public function __construct(public string $reference, public array $settings = []) {}
}

final readonly class PublishRequest
{
    /** @param list<Setting> $settings @param list<Source> $sources @param list<Item> $items */
    public function __construct(public string $reference, public array $settings = [], public array $sources = [], public array $items = []) {}
}

final readonly class Artifact
{
    public function __construct(public string $reference, public ?string $mediaType = null, public int $sizeBytes = 0) {}
}

final readonly class PublishedFile
{
    public function __construct(public string $itemId, public string $sourceReference, public string $relativePath) {}
}

final readonly class Publication
{
    /** @param list<PublishedFile> $files @param list<Setting> $publishedMetadata */
    public function __construct(public Artifact $artifact, public array $files = [], public array $publishedMetadata = []) {}
}

final readonly class FinalizationRequest
{
    public function __construct(public PublishRequest $request, public Publication $publication) {}
}

final readonly class DerivedArtifact
{
    public function __construct(
        public string $itemId,
        public string $reference,
        public string $derivedFromReference,
        public string $derivationKey,
        public string $kind,
        public ?string $mediaType = null,
        public int $sizeBytes = 0,
    ) {}
}

final readonly class Preparation
{
    /** @param list<DerivedArtifact> $artifacts */
    public function __construct(public array $artifacts = []) {}
}

enum PluginErrorCode: string
{
    case Unsupported = 'unsupported';
    case NotFound = 'not-found';
    case Authentication = 'authentication';
    case RateLimited = 'rate-limited';
    case Unavailable = 'unavailable';
    case InvalidData = 'invalid-data';
    case Failed = 'failed';
}

final readonly class PluginError
{
    public function __construct(public string $message, public bool $retryable) {}
}

final readonly class PluginFailure
{
    public function __construct(public PluginErrorCode $code, public PluginError $error) {}
}

final class PluginFailureException extends RuntimeException
{
    public function __construct(public readonly PluginFailure $failure)
    {
        parent::__construct($failure->error->message);
    }
}

final class InvalidPluginResultException extends RuntimeException {}
final class CapabilityUnavailableException extends RuntimeException {}

final readonly class Choice
{
    public function __construct(public string $value, public string $label) {}
}

final readonly class OperationRequest
{
    /** @param list<Setting> $settings @param list<Setting> $payload */
    public function __construct(public string $name, public array $settings = [], public array $payload = []) {}
}

final readonly class OperationResult
{
    /** @param list<Choice> $choices @param list<Setting> $values */
    public function __construct(public array $choices = [], public array $values = []) {}
}

interface BroadcastPlugin
{
    public function prepare(PublishRequest $request): Preparation;
    public function publish(PublishRequest $request): Publication;
    public function finalize(FinalizationRequest $request): Publication;
    public function operation(OperationRequest $request): OperationResult;
}

final readonly class ResolvedInput
{
    public function __construct(
        public string $id,
        public ?string $canonicalReference = null,
        public ?string $kind = null,
        public ?string $title = null,
        public ?string $artworkReference = null,
        public ?int $estimatedItemCount = null,
    ) {}
}

enum DiscoveryIntent: string { case Refresh = 'refresh'; case Complete = 'complete'; }
enum MediaKind: string { case Video = 'video'; case Audio = 'audio'; }

final readonly class InputOption
{
    public function __construct(public string $key, public OptionValue $value) {}
}

final readonly class AcquisitionOptions
{
    /** @param list<InputOption> $options */
    public function __construct(public MediaKind $mediaKind, public array $options = []) {}
}

final readonly class DiscoveredItem
{
    public function __construct(
        public string $id,
        public string $reference,
        public string $title,
        public ?string $description = null,
        public ?string $publishedAt = null,
        public ?string $artworkReference = null,
        public ?int $durationSeconds = null,
        public ?string $kind = null,
    ) {}
}

final readonly class StagedArtifact
{
    public function __construct(public string $reference, public string $mediaType, public int $sizeBytes = 0) {}
}

final readonly class AcquisitionResult
{
    /** @param list<StagedArtifact> $artifacts */
    public function __construct(public array $artifacts = []) {}
}

interface InputPlugin
{
    public function resolve(string $source): ResolvedInput;
    /** @param list<InputOption> $options @return list<DiscoveredItem> */
    public function discover(string $inputId, DiscoveryIntent $intent, array $options = []): array;
    public function acquire(DiscoveredItem $item, AcquisitionOptions $options): AcquisitionResult;
}

interface Logger
{
    public function info(string $message): void;
    public function error(string $message): void;
}

interface ProgressReporter
{
    public function report(string $stage): void;
}

interface ReadableResource
{
    public function read(int $maximumBytes = 65536): string;
    public function isEof(): bool;
    public function close(): void;
}

final readonly class HttpResponse
{
    /** @param array<string, string> $headers */
    public function __construct(
        public int $status,
        public array $headers = [],
        public ?string $inlineBody = null,
        public ?ReadableResource $resource = null,
    ) {}

    public function isInline(): bool { return $this->resource === null; }
    public function body(): string
    {
        if ($this->inlineBody !== null) {
            return $this->inlineBody;
        }
        throw new RuntimeException('response body is an opaque resource');
    }
}

interface HttpClient
{
    public function request(string $method, string $url, array $headers = [], ?string $body = null, ?string $credential = null): HttpResponse;
}

interface StagingArea
{
    public function write(string $relativePath, string $content, ?string $mediaType = null): StagedArtifact;
    public function stage(string $relativePath, ?string $mediaType = null): StagedArtifact;
}

final class UnavailableHttpClient implements HttpClient
{
    public function request(string $method, string $url, array $headers = [], ?string $body = null, ?string $credential = null): HttpResponse
    {
        throw new CapabilityUnavailableException('HTTP is a fixture-only capability in M4');
    }
}

final class NullLogger implements Logger
{
    public function info(string $message): void {}
    public function error(string $message): void {}
}

final class NullProgressReporter implements ProgressReporter
{
    public function report(string $stage): void {}
}

final readonly class PluginContext
{
    public function __construct(
        public Logger $logger = new NullLogger(),
        public ProgressReporter $progress = new NullProgressReporter(),
        public HttpClient $http = new UnavailableHttpClient(),
        public ?StagingArea $staging = null,
    ) {}
}

interface PluginEntrypoint
{
    public function register(PluginRegistry $registry): void;
}

final class PluginRegistry
{
    /** @var array<string, BroadcastPlugin> */
    private array $broadcasts = [];
    /** @var array<string, InputPlugin> */
    private array $inputs = [];

    public function broadcast(string $id, BroadcastPlugin $plugin): void { $this->broadcasts[$id] = $plugin; }
    public function input(string $id, InputPlugin $plugin): void { $this->inputs[$id] = $plugin; }
    public function broadcastPlugin(string $id): BroadcastPlugin { return $this->broadcasts[$id] ?? throw new RuntimeException("Unknown broadcast plugin: $id"); }
    public function inputPlugin(string $id): InputPlugin { return $this->inputs[$id] ?? throw new RuntimeException("Unknown input plugin: $id"); }
}

final class PluginBootstrap
{
    public static function load(PluginEntrypoint $entrypoint): PluginRegistry
    {
        $registry = new PluginRegistry();
        $entrypoint->register($registry);
        return $registry;
    }
}

final class PluginInvoker
{
    public static function publish(callable $handler, PublishRequest $request): Publication
    {
        $result = $handler($request);
        if (!$result instanceof Publication) {
            throw new InvalidPluginResultException('publish returned an invalid result');
        }
        return $result;
    }
}

final class WireMapper
{
    public static function publishRequest(PublishRequest $request): array
    {
        return [
            'reference' => $request->reference,
            'settings' => array_map([self::class, 'setting'], $request->settings),
            'sources' => array_map([self::class, 'source'], $request->sources),
            'items' => array_map([self::class, 'item'], $request->items),
        ];
    }

    public static function publication(Publication $publication): array
    {
        return [
            'artifact' => ['reference' => $publication->artifact->reference, 'media-type' => $publication->artifact->mediaType, 'size-bytes' => $publication->artifact->sizeBytes],
            'files' => array_map(static fn (PublishedFile $file): array => ['item-id' => $file->itemId, 'source-reference' => $file->sourceReference, 'relative-path' => $file->relativePath], $publication->files),
            'published-metadata' => array_map([self::class, 'setting'], $publication->publishedMetadata),
        ];
    }

    public static function pluginFailure(PluginFailure $failure): array
    {
        return ['tag' => $failure->code->value, 'value' => ['message' => $failure->error->message, 'retryable' => $failure->error->retryable]];
    }

    private static function setting(Setting $setting): array { return ['key' => $setting->key, 'value' => $setting->value->toWire()]; }
    private static function source(Source $source): array { return ['reference' => $source->reference, 'settings' => array_map([self::class, 'setting'], $source->settings)]; }
    private static function item(Item $item): array
    {
        return [
            'id' => $item->id,
            'source-reference' => $item->sourceReference,
            'title' => $item->title,
            'description' => $item->description,
            'published-at' => $item->publishedAt,
            'duration-seconds' => $item->durationSeconds,
            'resources' => array_map(static fn (ItemResource $resource): array => [
                'reference' => $resource->reference, 'kind' => $resource->kind, 'derivation-key' => $resource->derivationKey,
                'url' => $resource->url, 'media-type' => $resource->mediaType, 'size-bytes' => $resource->sizeBytes,
            ], $item->resources),
        ];
    }
}
