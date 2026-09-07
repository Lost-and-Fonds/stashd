<?php

declare(strict_types=1);

namespace App\Broadcasts\Api;

use App\Broadcasts\BroadcastItemRecord;
use App\Http\Api\ApiJson;
use App\Vault\ItemRecord;

final readonly class BroadcastItemResource
{
    public function __construct(
        private BroadcastItemRecord $item,
        private ?ItemRecord $relatedItem = null,
    ) {}

    public static function fromRecord(BroadcastItemRecord $item, ?ItemRecord $relatedItem = null): self
    {
        return new self($item, $relatedItem);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ApiJson::encode([
            'id' => (string) $this->item->id,
            'broadcastId' => (string) $this->item->broadcastId,
            'stashItemId' => (string) $this->item->stashItemId,
            'itemId' => (string) $this->item->itemId,
            'state' => $this->item->state->value,
            'publishedPath' => $this->item->publishedPath,
            'publishedUri' => $this->item->publishedUri,
            'lastPublishedAt' => $this->item->lastPublishedAt,
            'lastVerifiedAt' => $this->item->lastVerifiedAt,
            'lastError' => $this->item->lastError,
            'createdAt' => $this->item->createdAt,
            'updatedAt' => $this->item->updatedAt,
            'item' => $this->relatedItem === null ? null : [
                'title' => $this->relatedItem->title,
            ],
        ]);
    }
}
