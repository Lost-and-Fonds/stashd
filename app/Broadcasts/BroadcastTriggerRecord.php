<?php

declare(strict_types=1);

namespace App\Broadcasts;

use Tempest\Database\BelongsTo;
use Tempest\Database\IsDatabaseModel;
use Tempest\Database\PrimaryKey;
use Tempest\Database\Table;
use Tempest\DateTime\DateTime;

#[Table(name: 'broadcast_triggers')]
final class BroadcastTriggerRecord
{
    use IsDatabaseModel;

    public PrimaryKey $id;

    #[BelongsTo(ownerJoin: 'broadcastId')]
    public BroadcastRecord $broadcast;

    public function __construct(
        public BroadcastId $broadcastId,
        public string $type,
        public bool $enabled,
        public BroadcastTriggerState $state,
        /** @var array<string, scalar|null>|null */
        public ?array $settings = null,
        public ?DateTime $lastTriggeredAt = null,
        public ?DateTime $lastSuccessAt = null,
        public ?DateTime $lastFailureAt = null,
        public ?string $lastError = null,
        public ?DateTime $createdAt = null,
        public ?DateTime $updatedAt = null,
    ) {}
}
