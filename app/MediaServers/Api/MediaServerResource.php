<?php

declare(strict_types=1);

namespace App\MediaServers\Api;

use App\Http\Api\ApiJson;
use App\MediaServers\MediaServerConnectionRecord;

final readonly class MediaServerResource
{
    public function __construct(
        private MediaServerConnectionRecord $connection,
    ) {
    }

    public static function fromRecord(MediaServerConnectionRecord $connection): self
    {
        return new self($connection);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ApiJson::encode([
            'id' => (string) $this->connection->id,
            'type' => $this->connection->type,
            'name' => $this->connection->name,
            'baseUri' => $this->connection->baseUri,
            'state' => $this->connection->state->value,
            'settings' => $this->connection->settings,
            'lastCheckedAt' => $this->connection->lastCheckedAt,
            'lastError' => $this->connection->lastError,
            'createdAt' => $this->connection->createdAt,
            'updatedAt' => $this->connection->updatedAt,
        ]);
    }

}
