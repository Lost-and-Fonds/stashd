<?php

declare(strict_types=1);

namespace App\Vault;

use App\Support\DurationSecondsCaster;
use App\Support\DurationSecondsSerializer;
use Tempest\Database\IsDatabaseModel;
use Tempest\Database\PrimaryKey;
use Tempest\Database\Table;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Duration;
use Tempest\Mapper\CastWith;
use Tempest\Mapper\SerializeWith;

#[Table(name: 'media_items')]
final class MediaItemRecord
{
    use IsDatabaseModel;

    public PrimaryKey $id;

    public function __construct(
        public string $providerKey,
        public string $providerItemId,
        public string $canonicalUri,
        public string $title,
        public MediaItemState $state,
        public UpstreamState $upstreamState,
        public ?string $description = null,
        public ?string $creatorName = null,
        public ?string $creatorProviderId = null,
        #[CastWith(DurationSecondsCaster::class)]
        #[SerializeWith(DurationSecondsSerializer::class)]
        public ?Duration $durationSeconds = null,
        public ?DateTime $publishedAt = null,
        public ?string $thumbnailUri = null,
        public ?string $contentType = null,
        public ?DateTime $metadataCapturedAt = null,
        public ?DateTime $metadataRefreshedAt = null,
        public ?DateTime $lastSeenUpstreamAt = null,
        public ?DateTime $createdAt = null,
        public ?DateTime $updatedAt = null,
    ) {}
}
