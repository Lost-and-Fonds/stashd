<?php

declare(strict_types=1);

namespace App\Broadcasts;

use App\Stashes\StashId;
use App\Stashes\StashRecord;
use Tempest\Database\BelongsTo;
use Tempest\Database\IsDatabaseModel;
use Tempest\Database\PrimaryKey;
use Tempest\Database\Table;
use Tempest\DateTime\DateTime;

#[Table(name: 'broadcasts')]
final class BroadcastRecord
{
    use IsDatabaseModel;

    public PrimaryKey $id;

    #[BelongsTo(ownerJoin: 'stashId')]
    public StashRecord $stash;

    /** @param array<string, mixed>|null $settings */
    public function __construct(
        public StashId $stashId,
        public string $type,
        public string $name,
        public string $slug,
        public BroadcastState $state,
        public ?array $settings = null,
        public ?DateTime $lastPlannedAt = null,
        public ?DateTime $lastBuiltAt = null,
        public ?DateTime $lastVerifiedAt = null,
        public ?string $lastError = null,
        public ?DateTime $createdAt = null,
        public ?DateTime $updatedAt = null,
    ) {}
}
