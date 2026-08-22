<?php

declare(strict_types=1);

namespace Stashd\PluginSdk;

final class OptionValue
{
    private function __construct(public readonly string $kind, public readonly bool|int|string $value)
    {
    }

    public static function boolean(bool $value): self
    {
        return new self('boolean', $value);
    }
    public static function number(int $value): self
    {
        return new self('number', $value);
    }
    public static function text(string $value): self
    {
        return new self('text', $value);
    }

    /** @return array{tag: string, value: bool|int|string} */
    public function toWire(): array
    {
        return ['tag' => $this->kind, 'value' => $this->value];
    }
}

final readonly class Setting
{
    public function __construct(public string $key, public OptionValue $value)
    {
    }
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
    ) {
    }
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
    ) {
    }
}

final readonly class Source
{
    /** @param list<Setting> $settings */
    public function __construct(public string $reference, public array $settings = [])
    {
    }
}

final readonly class PublishRequest
{
    /**
     * @param list<Setting> $settings
     * @param list<Source> $sources
     * @param list<Item> $items
     */
    public function __construct(public string $reference, public array $settings = [], public array $sources = [], public array $items = [])
    {
    }
}

final readonly class Artifact
{
    public function __construct(public string $reference, public ?string $mediaType = null, public int $sizeBytes = 0)
    {
    }
}

final readonly class PublishedFile
{
    public function __construct(public string $itemId, public string $sourceReference, public string $relativePath)
    {
    }
}

final readonly class Publication
{
    /**
     * @param list<PublishedFile> $files
     * @param list<Setting> $publishedMetadata
     */
    public function __construct(public Artifact $artifact, public array $files = [], public array $publishedMetadata = [])
    {
    }
}

final readonly class FinalizationRequest
{
    public function __construct(public PublishRequest $request, public Publication $publication)
    {
    }
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
    ) {
    }
}

final readonly class Preparation
{
    /** @param list<DerivedArtifact> $artifacts */
    public function __construct(public array $artifacts = [])
    {
    }
}
