<?php

declare(strict_types=1);

namespace App\Broadcasts;

use App\Support\PrefixedUlidGenerator;
use Tempest\Database\PrimaryKey;

use function Tempest\Database\query;

use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

final class BroadcastTriggerRepository
{
    public function __construct(
        private PrefixedUlidGenerator $ids,
    ) {
    }

    public function create(
        BroadcastId $broadcastId,
        string $type,
        bool $enabled = true,
        BroadcastTriggerState $state = BroadcastTriggerState::Ready,
        /** @var array<string, scalar|null>|null $settings */
        ?array $settings = null,
    ): BroadcastTriggerRecord {
        $id = $this->ids->generate('btrigger')->toString();
        /** @var array<string, scalar|null>|null $settings */
        $record = new BroadcastTriggerRecord(
            broadcastId: $broadcastId,
            type: $type,
            enabled: $enabled,
            state: $state,
            settings: $settings,
        );
        $record->id = new PrimaryKey($id);
        $now = DateTime::now(Timezone::UTC);
        $record->createdAt ??= $now;
        $record->updatedAt ??= $now;

        query(BroadcastTriggerRecord::class)->insert($record)->execute();

        return $record;
    }

    public function save(BroadcastTriggerRecord $record): BroadcastTriggerRecord
    {
        $record->updatedAt = DateTime::now(Timezone::UTC);
        $record->save();

        return $record;
    }

    /** @return list<BroadcastTriggerRecord> */
    public function listForBroadcast(BroadcastId $broadcastId): array
    {
        return BroadcastTriggerRecord::select()
            ->where('broadcastId', $broadcastId->toString())
            ->all();
    }

    public function findEnabled(BroadcastId $broadcastId, string $type): ?BroadcastTriggerRecord
    {
        /** @var BroadcastTriggerRecord|null $record */
        $record = BroadcastTriggerRecord::select()
            ->where('broadcastId', $broadcastId->toString())
            ->where('type', $type)
            ->where('enabled', true)
            ->first();

        return $record;
    }
}
