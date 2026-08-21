<?php

declare(strict_types=1);

namespace App\Vault;

use App\Broadcasts\BroadcastId;
use App\Broadcasts\BroadcastItemId;
use App\Support\DurationSecondsCaster;
use App\Support\DurationSecondsSerializer;
use Tempest\Database\IsDatabaseModel;
use Tempest\Database\PrimaryKey;
use Tempest\Database\Table;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Duration;
use Tempest\Mapper\CastWith;
use Tempest\Mapper\SerializeWith;

#[Table(name: 'assets')]
final class AssetRecord
{
    use IsDatabaseModel;

    public PrimaryKey $id;

    public function __construct(
        public AssetRole $role,
        public AssetKind $kind,
        public AssetState $state,
        public ?MediaItemId $mediaItemId = null,
        public ?BroadcastId $broadcastId = null,
        public ?BroadcastItemId $broadcastItemId = null,
        public ?string $path = null,
        public ?string $relativePath = null,
        public ?string $mimeType = null,
        public ?string $container = null,
        public ?string $videoCodec = null,
        public ?string $audioCodec = null,
        public ?string $language = null,
        public ?int $sizeBytes = null,
        public ?string $checksum = null,
        #[CastWith(DurationSecondsCaster::class)]
        #[SerializeWith(DurationSecondsSerializer::class)]
        public ?Duration $durationSeconds = null,
        public ?AssetId $derivedFromAssetId = null,
        public ?DateTime $lastVerifiedAt = null,
        public ?DateTime $missingAt = null,
        public ?string $missingReason = null,
        public ?string $derivationKey = null,
        public ?DateTime $createdAt = null,
        public ?DateTime $updatedAt = null,
    ) {
    }
}
