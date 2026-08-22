<?php

declare(strict_types=1);

namespace App\Broadcasts;

use Tempest\Database\BelongsTo;
use Tempest\Database\IsDatabaseModel;
use Tempest\Database\PrimaryKey;
use Tempest\Database\Table;
use Tempest\DateTime\DateTime;

#[Table(name: 'broadcast_trigger_runs')]
final class BroadcastTriggerRunRecord
{
    use IsDatabaseModel;

    public PrimaryKey $id;

    #[BelongsTo(ownerJoin: 'triggerId')]
    public BroadcastTriggerRecord $trigger;

    public function __construct(
        public BroadcastTriggerId $triggerId,
        public BroadcastTriggerRunState $state,
        public ?string $reason = null,
        public ?DateTime $startedAt = null,
        public ?DateTime $finishedAt = null,
        public ?string $responseSummary = null,
        public ?string $error = null,
        public ?DateTime $createdAt = null,
    ) {}
}
