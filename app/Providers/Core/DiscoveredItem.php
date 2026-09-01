<?php

declare(strict_types=1);

namespace App\Providers\Core;

use App\Providers\StashdUri;
use Tempest\DateTime\DateTime;

final readonly class DiscoveredItem
{
    /**
     * @param  array<string, mixed>|null  $rawMetadata
     */
    public function __construct(
        public string $providerItemId,
        public StashdUri $canonicalUri,
        public string $title,
        public ?string $description = null,
        public ?int $durationSeconds = null,
        public ?DateTime $publishedAt = null,
        public ?StashdUri $thumbnailUri = null,
        public ?array $rawMetadata = null,
        public ?string $contentType = null,
        public ?int $sizeBytes = null,
        public bool $sizeEstimated = false,
        public ?string $upstreamState = null,
    ) {}

    /** @return array<string, mixed> */
    public static function toArray(self $item): array
    {
        $payload = [
            'provider_item_id' => $item->providerItemId,
            'canonical_uri' => $item->canonicalUri->toString(),
            'title' => $item->title,
            'description' => $item->description,
            'duration_seconds' => $item->durationSeconds,
            'published_at' => $item->publishedAt?->toRfc3339(useZ: true),
            'thumbnail_uri' => $item->thumbnailUri?->toString(),
            'content_type' => $item->contentType,
            'size_bytes' => $item->sizeBytes,
            'size_estimated' => $item->sizeEstimated,
            'upstream_state' => $item->upstreamState,
        ];

        if ($item->rawMetadata !== null) {
            $payload['raw_metadata'] = $item->rawMetadata;
        }

        return $payload;
    }

    /**
     * @param  list<self>  $items
     * @return list<array<string, mixed>>
     */
    public static function manyToArray(array $items): array
    {
        return array_map(self::toArray(...), $items);
    }
}
