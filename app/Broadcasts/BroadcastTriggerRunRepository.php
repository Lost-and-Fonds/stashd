<?php

declare(strict_types=1);

namespace App\Broadcasts;

use App\Support\PrefixedUlidGenerator;
use Tempest\Database\PrimaryKey;

use function Tempest\Database\query;

use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

final class BroadcastTriggerRunRepository
{
    public function __construct(
        private PrefixedUlidGenerator $ids,
    ) {}

    public function create(
        BroadcastTriggerId $triggerId,
        BroadcastTriggerRunState $state = BroadcastTriggerRunState::Pending,
        ?string $reason = null,
    ): BroadcastTriggerRunRecord {
        $id = $this->ids->generate('btrun')->toString();
        $record = new BroadcastTriggerRunRecord(
            triggerId: $triggerId,
            state: $state,
            reason: $reason,
            startedAt: DateTime::now(Timezone::UTC),
        );
        $record->id = new PrimaryKey($id);
        $record->createdAt = DateTime::now(Timezone::UTC);

        query(BroadcastTriggerRunRecord::class)->insert($record)->execute();

        return $record;
    }
}
