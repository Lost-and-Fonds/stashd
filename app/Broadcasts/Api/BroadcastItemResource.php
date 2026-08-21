<?php

declare(strict_types=1);

namespace App\Broadcasts\Api;

use App\Broadcasts\BroadcastItemRecord;
use App\Http\Api\ApiJson;
use App\Vault\MediaItemRecord;

final readonly class BroadcastItemResource
{
    public function __construct(
        private BroadcastItemRecord $item,
        private ?MediaItemRecord $mediaItem = null,
    ) {
    }

    public static function fromRecord(BroadcastItemRecord $item, ?MediaItemRecord $mediaItem = null): self
    {
        return new self($item, $mediaItem);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ApiJson::encode([
            'id' => (string) $this->item->id,
            'broadcastId' => (string) $this->item->broadcastId,
            'stashItemId' => (string) $this->item->stashItemId,
            'mediaItemId' => (string) $this->item->mediaItemId,
            'state' => $this->item->state->value,
            'publishedPath' => $this->item->publishedPath,
            'publishedUri' => $this->item->publishedUri,
            'lastPublishedAt' => $this->item->lastPublishedAt,
            'lastVerifiedAt' => $this->item->lastVerifiedAt,
            'lastError' => $this->item->lastError,
            'createdAt' => $this->item->createdAt,
            'updatedAt' => $this->item->updatedAt,
            'mediaItem' => $this->mediaItem === null ? null : [
                'title' => $this->mediaItem->title,
            ],
        ]);
    }
}
