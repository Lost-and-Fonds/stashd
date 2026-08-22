<?php

declare(strict_types=1);

namespace App\Connections;

use Tempest\Database\IsDatabaseModel;
use Tempest\Database\PrimaryKey;
use Tempest\Database\Table;
use Tempest\DateTime\DateTime;
use Tempest\Mapper\Hidden;

#[Table(name: 'media_server_connections')]
final class ConnectionRecord
{
    use IsDatabaseModel;

    public PrimaryKey $id;

    public function __construct(
        /** Historical column name; this is the opaque installed-plugin key. */
        public string $type,
        public string $name,
        /** Historical column name; this is the approved remote endpoint. */
        public string $baseUri,
        public ConnectionState $state,
        #[Hidden]
        /** Historical column name for the encrypted credential reference. */
        public ?string $tokenSecretId = null,
        /** @var array<string, mixed>|null Plugin-defined connection settings. */
        public ?array $settings = null,
        public ?DateTime $lastCheckedAt = null,
        public ?string $lastError = null,
        public ?DateTime $createdAt = null,
        public ?DateTime $updatedAt = null,
    ) {}
}
