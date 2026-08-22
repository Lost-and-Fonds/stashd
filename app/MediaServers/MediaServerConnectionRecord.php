<?php

declare(strict_types=1);

namespace App\MediaServers;

use Tempest\Database\IsDatabaseModel;
use Tempest\Database\PrimaryKey;
use Tempest\Database\Table;
use Tempest\DateTime\DateTime;
use Tempest\Mapper\Hidden;

#[Table(name: 'media_server_connections')]
final class MediaServerConnectionRecord
{
    use IsDatabaseModel;

    public PrimaryKey $id;

    public function __construct(
        /** Logical plugin key retained in the legacy connection table for upgrade compatibility. */
        public string $type,
        public string $name,
        public string $baseUri,
        public MediaServerConnectionState $state,
        #[Hidden]
        public ?string $tokenSecretId = null,
        /** @var array<string, mixed>|null Plugin-defined connection settings. */
        public ?array $settings = null,
        public ?DateTime $lastCheckedAt = null,
        public ?string $lastError = null,
        public ?DateTime $createdAt = null,
        public ?DateTime $updatedAt = null,
    ) {
    }
}
