<?php

declare(strict_types=1);

namespace App\Broadcasts\Api;

use App\Broadcasts\BroadcastRecord;
use App\Http\Api\ApiJson;

final readonly class BroadcastResource
{
    public function __construct(
        private BroadcastRecord $broadcast,
        private ?string $publishedUrl = null,
    ) {
    }

    public static function fromRecord(BroadcastRecord $broadcast, ?string $publishedUrl = null): BroadcastResource
    {
        return new self($broadcast, $publishedUrl);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $payload = [
            'id' => (string) $this->broadcast->id,
            'stashId' => (string) $this->broadcast->stashId,
            'type' => $this->broadcast->type,
            'name' => $this->broadcast->name,
            'slug' => $this->broadcast->slug,
            'state' => $this->broadcast->state->value,
            'settings' => $this->broadcast->settings,
            'lastPlannedAt' => $this->broadcast->lastPlannedAt,
            'lastBuiltAt' => $this->broadcast->lastBuiltAt,
            'lastVerifiedAt' => $this->broadcast->lastVerifiedAt,
            'lastError' => $this->broadcast->lastError,
            'createdAt' => $this->broadcast->createdAt,
            'updatedAt' => $this->broadcast->updatedAt,
        ];

        if ($this->publishedUrl !== null) {
            $payload['publishedUrl'] = $this->publishedUrl;
        }

        return ApiJson::encode($payload);
    }

}
