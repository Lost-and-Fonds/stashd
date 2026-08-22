<?php

declare(strict_types=1);

namespace Stashd\PluginSdk;

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
    ) {
    }
}

enum DiscoveryIntent: string
{
    case Refresh = 'refresh';
    case Complete = 'complete';
}
enum MediaKind: string
{
    case Video = 'video';
    case Audio = 'audio';
}

final readonly class InputOption
{
    public function __construct(public string $key, public OptionValue $value)
    {
    }
}

final readonly class AcquisitionOptions
{
    /** @param list<InputOption> $options */
    public function __construct(public MediaKind $mediaKind, public array $options = [])
    {
    }
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
    ) {
    }
}

final readonly class StagedArtifact
{
    public function __construct(public string $reference, public string $mediaType, public int $sizeBytes = 0)
    {
    }
}

final readonly class AcquisitionResult
{
    /** @param list<StagedArtifact> $artifacts */
    public function __construct(public array $artifacts = [])
    {
    }
}

interface InputPlugin
{
    public function resolve(string $source): ResolvedInput;
    /**
     * @param list<InputOption> $options
     * @return list<DiscoveredItem>
     */
    public function discover(string $inputId, DiscoveryIntent $intent, array $options = []): array;
    public function acquire(DiscoveredItem $item, AcquisitionOptions $options): AcquisitionResult;
}
