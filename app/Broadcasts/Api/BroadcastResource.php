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

        // season_mapping is keyed by opaque stash_input_id strings, not DTO
        // field names — ApiJson::encode()'s snake/camel key transform must not
        // touch them, so it's pulled out before encoding and reattached
        // verbatim. See SeasonMapping and BroadcastController::updateSeasonMapping().
        $seasonMapping = is_array($payload['settings']) ? ($payload['settings']['season_mapping'] ?? null) : null;

        $encoded = ApiJson::encode($payload);

        if (is_array($seasonMapping) && is_array($encoded['settings'] ?? null)) {
            $encoded['settings']['season_mapping'] = $seasonMapping;
        }

        return $encoded;
    }

}
