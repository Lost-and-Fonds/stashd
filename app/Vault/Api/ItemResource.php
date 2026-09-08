<?php

declare(strict_types=1);

namespace App\Vault\Api;

use App\Http\Api\ApiJson;
use App\Fixity\PreservationHealthSummary;
use App\Support\DurationSeconds;
use App\Vault\ItemRecord;

final readonly class ItemResource
{
    public function __construct(
        private ItemRecord $item,
        private ?PreservationHealthSummary $preservation = null,
    ) {}

    public static function fromRecord(ItemRecord $item, ?PreservationHealthSummary $preservation = null): self
    {
        return new self($item, $preservation);
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
            'durationSeconds' => DurationSeconds::toSeconds($this->item->duration),
            'publishedAt' => $this->item->publishedAt,
            'thumbnailUri' => $this->item->thumbnailUri,
            'lastSeenUpstreamAt' => $this->item->lastSeenUpstreamAt,
            'createdAt' => $this->item->createdAt,
            'updatedAt' => $this->item->updatedAt,
            ...($this->preservation?->toArray() ?? []),
        ]);
    }
}
