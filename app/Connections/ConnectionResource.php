<?php

declare(strict_types=1);

namespace App\Connections;

use App\Http\Api\ApiJson;

final readonly class ConnectionResource
{
    public function __construct(
        private ConnectionRecord $connection,
    ) {}

    public static function fromRecord(ConnectionRecord $connection): self
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
            'state' => $this->connection->state,
            'settings' => $this->connection->settings,
            'lastCheckedAt' => $this->connection->lastCheckedAt,
            'lastError' => $this->connection->lastError,
            'createdAt' => $this->connection->createdAt,
            'updatedAt' => $this->connection->updatedAt,
        ]);
    }
}
