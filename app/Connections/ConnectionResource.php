<?php

declare(strict_types=1);

namespace App\Connections;

use App\Http\Api\ApiJson;
use App\MediaServers\MediaServerConnectionRecord;

final readonly class ConnectionResource
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
            'plugin_key' => $this->connection->type,
            'name' => $this->connection->name,
            'endpoint' => $this->connection->baseUri,
            'settings' => $this->connection->settings,
            'createdAt' => $this->connection->createdAt,
            'updatedAt' => $this->connection->updatedAt,
        ]);
    }

}
