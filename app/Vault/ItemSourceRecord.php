<?php

declare(strict_types=1);

namespace App\Vault;

use App\Stashes\StashInputId;
use Tempest\Database\BelongsTo;
use Tempest\Database\IsDatabaseModel;
use Tempest\Database\PrimaryKey;
use Tempest\Database\Table;
use Tempest\DateTime\DateTime;

#[Table(name: 'item_sources')]
final class ItemSourceRecord
{
    use IsDatabaseModel;

    public PrimaryKey $id;

    #[BelongsTo(ownerJoin: 'itemId')]
    public ItemRecord $item;

    public function __construct(
        public ItemId $itemId,
        public string $providerKey,
        public string $providerInputId,
        public string $discoveredUri,
        public DateTime $discoveredAt,
        public ?StashInputId $stashInputId = null,
        public ?int $position = null,
        public ?int $rawPosition = null,
    ) {}
}
