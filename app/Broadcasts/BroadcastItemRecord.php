<?php

declare(strict_types=1);

namespace App\Broadcasts;

use App\Stashes\StashItemId;
use App\Stashes\StashItemRecord;
use App\Vault\ItemId;
use App\Vault\ItemRecord;
use Tempest\Database\BelongsTo;
use Tempest\Database\IsDatabaseModel;
use Tempest\Database\PrimaryKey;
use Tempest\Database\Table;
use Tempest\DateTime\DateTime;

#[Table(name: 'broadcast_items')]
final class BroadcastItemRecord
{
    use IsDatabaseModel;

    public PrimaryKey $id;

    #[BelongsTo(ownerJoin: 'broadcastId')]
    public BroadcastRecord $broadcast;

    #[BelongsTo(ownerJoin: 'stashItemId')]
    public StashItemRecord $stashItem;

    #[BelongsTo(ownerJoin: 'itemId')]
    public ItemRecord $item;

    public function __construct(
        public BroadcastId $broadcastId,
        public StashItemId $stashItemId,
        public ItemId $itemId,
        public BroadcastItemState $state,
        public ?string $publishedPath = null,
        public ?string $publishedUri = null,
        public ?DateTime $lastPublishedAt = null,
        public ?DateTime $lastVerifiedAt = null,
        public ?string $lastError = null,
        public ?DateTime $createdAt = null,
        public ?DateTime $updatedAt = null,
    ) {}
}
