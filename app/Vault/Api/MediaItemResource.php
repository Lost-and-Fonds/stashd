<?php

declare(strict_types=1);

namespace App\Vault\Api;

use App\Http\Api\ApiJson;
use App\Support\DurationSeconds;
use App\Vault\MediaItemRecord;

final readonly class MediaItemResource
{
    public function __construct(
        private MediaItemRecord $item,
    ) {}

    public static function fromRecord(MediaItemRecord $item): self
    {
        return new self($item);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ApiJson::encode([
            'id' => (string) $this->item->id,
            'providerKey' => $this->item->providerKey,
            'providerItemId' => $this->item->providerItemId,
            'canonicalUri' => $this->item->canonicalUri,
            'title' => $this->item->title,
            'description' => $this->item->description,
            'state' => $this->item->state->value,
            'upstreamState' => $this->item->upstreamState->value,
            'contentType' => $this->item->contentType,
            'creatorName' => $this->item->creatorName,
            'durationSeconds' => DurationSeconds::toSeconds($this->item->durationSeconds),
            'publishedAt' => $this->item->publishedAt,
            'thumbnailUri' => $this->item->thumbnailUri,
            'lastSeenUpstreamAt' => $this->item->lastSeenUpstreamAt,
            'createdAt' => $this->item->createdAt,
            'updatedAt' => $this->item->updatedAt,
        ]);
    }
}
