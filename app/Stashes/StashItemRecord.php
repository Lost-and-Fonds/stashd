<?php

declare(strict_types=1);

namespace App\Stashes;

use App\Vault\MediaItemId;
use App\Vault\MediaItemRecord;
use Tempest\Database\BelongsTo;
use Tempest\Database\IsDatabaseModel;
use Tempest\Database\PrimaryKey;
use Tempest\Database\Table;
use Tempest\DateTime\DateTime;

#[Table(name: 'stash_items')]
final class StashItemRecord
{
    use IsDatabaseModel;

    public PrimaryKey $id;

    #[BelongsTo(ownerJoin: 'stashId')]
    public StashRecord $stash;

    #[BelongsTo(ownerJoin: 'mediaItemId')]
    public MediaItemRecord $mediaItem;

    public function __construct(
        public StashId $stashId,
        public MediaItemId $mediaItemId,
        public StashItemState $state,
        public ?StashInputId $stashInputId = null,
        public ?int $position = null,
        public ?string $displayTitle = null,
        public ?string $displayDescription = null,
        public ?DateTime $firstSeenAt = null,
        public ?DateTime $lastSeenAt = null,
        public ?DateTime $removedAt = null,
        public ?string $removedReason = null,
        public ?string $ignoredReason = null,
        public ?DateTime $createdAt = null,
        public ?DateTime $updatedAt = null,
    ) {
    }
}
