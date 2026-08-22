<?php

declare(strict_types=1);

namespace App\Broadcasts;

use App\Vault\AssetId;
use Tempest\Database\BelongsTo;
use Tempest\Database\IsDatabaseModel;
use Tempest\Database\PrimaryKey;
use Tempest\Database\Table;

#[Table(name: 'published_resources')]
final class PublishedResourceRecord
{
    use IsDatabaseModel;

    public PrimaryKey $id;

    #[BelongsTo(ownerJoin: 'broadcastId')]
    public BroadcastRecord $broadcast;

    public function __construct(
        public BroadcastId $broadcastId,
        public ?AssetId $assetId = null,
        public ?string $relativePath = null,
        public string $mediaType = 'application/octet-stream',
        public ?string $downloadName = null,
        public string $access = 'public',
        public ?string $credentialSecretId = null,
        public string $state = 'ready',
        public ?string $lastError = null,
    ) {}
}
