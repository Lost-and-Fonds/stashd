<?php

declare(strict_types=1);

namespace App\Timeline;

use App\Vault\MediaItemId;
use App\Vault\MediaItemRecord;
use Tempest\Database\BelongsTo;
use Tempest\Database\IsDatabaseModel;
use Tempest\Database\PrimaryKey;
use Tempest\Database\Table;
use Tempest\DateTime\DateTime;

#[Table(name: 'media_timeline_entries')]
final class TimelineEntryRecord
{
    use IsDatabaseModel;

    public PrimaryKey $id;

    #[BelongsTo(ownerJoin: 'mediaItemId')]
    public MediaItemRecord $mediaItem;

    /** @param array<string, mixed>|null $raw */
    public function __construct(
        public MediaItemId $mediaItemId,
        public TimelineEntrySource $source,
        public TimelineEntryKind $kind,
        public TimelineEntryCategory $category,
        public float $startSeconds,
        public float $endSeconds,
        public TimelineEntryState $state = TimelineEntryState::Ready,
        public ?string $title = null,
        public ?string $externalId = null,
        public ?array $raw = null,
        public ?DateTime $lastCheckedAt = null,
        public ?DateTime $createdAt = null,
        public ?DateTime $updatedAt = null,
    ) {}
}
