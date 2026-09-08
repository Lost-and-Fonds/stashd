<?php

declare(strict_types=1);

namespace App\Stashes\Api;

use App\Http\Api\ApiJson;
use App\Stashes\StashItemRecord;
use App\Support\DurationSeconds;
use App\Vault\ItemRecord;

final readonly class StashItemResource
{
    public function __construct(
        private StashItemRecord $item,
        private ?ItemRecord $relatedItem = null,
        private ?int $totalAssetSizeBytes = null,
        private ?string $downloadFailureReason = null,
    ) {}

    public static function fromRecord(
        StashItemRecord $item,
        ?ItemRecord $relatedItem = null,
        ?int $totalAssetSizeBytes = null,
        ?string $downloadFailureReason = null,
    ): self {
        return new self($item, $relatedItem, $totalAssetSizeBytes, $downloadFailureReason);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ApiJson::encode([
            'id' => (string) $this->item->id,
            'stashId' => (string) $this->item->stashId,
            'itemId' => (string) $this->item->itemId,
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
            'item' => $this->relatedItem === null ? null : [
                'title' => $this->relatedItem->title,
                'state' => $this->relatedItem->state->value,
                'thumbnailUri' => $this->relatedItem->thumbnailUri,
                'durationSeconds' => DurationSeconds::toSeconds($this->relatedItem->duration),
                'contentType' => $this->relatedItem->contentType,
                'publishedAt' => $this->relatedItem->publishedAt,
                'failureReason' => $this->downloadFailureReason,
                'upstreamState' => $this->relatedItem->upstreamState->value,
                'sizeBytes' => $this->relatedItem->sizeBytes,
                'sizeEstimated' => $this->relatedItem->sizeEstimated,
            ],
            'totalAssetSizeBytes' => $this->totalAssetSizeBytes,
        ]);
    }
}
