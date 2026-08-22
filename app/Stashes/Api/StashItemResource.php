<?php

declare(strict_types=1);

namespace App\Stashes\Api;

use App\Http\Api\ApiJson;
use App\Stashes\StashItemRecord;
use App\Support\DurationSeconds;
use App\Vault\MediaItemRecord;

final readonly class StashItemResource
{
    public function __construct(
        private StashItemRecord $item,
        private ?MediaItemRecord $mediaItem = null,
        private ?int $totalAssetSizeBytes = null,
        private ?string $downloadFailureReason = null,
    ) {}

    public static function fromRecord(
        StashItemRecord $item,
        ?MediaItemRecord $mediaItem = null,
        ?int $totalAssetSizeBytes = null,
        ?string $downloadFailureReason = null,
    ): self {
        return new self($item, $mediaItem, $totalAssetSizeBytes, $downloadFailureReason);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ApiJson::encode([
            'id' => (string) $this->item->id,
            'stashId' => (string) $this->item->stashId,
            'mediaItemId' => (string) $this->item->mediaItemId,
            'stashInputId' => $this->item->stashInputId === null ? null : (string) $this->item->stashInputId,
            'state' => $this->item->state->value,
            'position' => $this->item->position,
            'displayTitle' => $this->item->displayTitle,
            'displayDescription' => $this->item->displayDescription,
            'firstSeenAt' => $this->item->firstSeenAt,
            'lastSeenAt' => $this->item->lastSeenAt,
            'removedAt' => $this->item->removedAt,
            'removedReason' => $this->item->removedReason,
            'ignoredReason' => $this->item->ignoredReason,
            'createdAt' => $this->item->createdAt,
            'updatedAt' => $this->item->updatedAt,
            'mediaItem' => $this->mediaItem === null ? null : [
                'title' => $this->mediaItem->title,
                'state' => $this->mediaItem->state->value,
                'thumbnailUri' => $this->mediaItem->thumbnailUri,
                'durationSeconds' => DurationSeconds::toSeconds($this->mediaItem->durationSeconds),
                'contentType' => $this->mediaItem->contentType,
                'publishedAt' => $this->mediaItem->publishedAt,
                'failureReason' => $this->downloadFailureReason,
            ],
            'totalAssetSizeBytes' => $this->totalAssetSizeBytes,
        ]);
    }
}
