<?php

declare(strict_types=1);

namespace App\Broadcasts;

use App\Stashes\StashItemId;
use App\Support\PrefixedUlidGenerator;
use App\Vault\MediaItemId;
use InvalidArgumentException;
use Tempest\Database\Direction;
use Tempest\Database\PrimaryKey;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

use function Tempest\Database\query;

final class BroadcastItemRepository
{
    public function __construct(
        private PrefixedUlidGenerator $ids,
    ) {}

    public function create(
        BroadcastId $broadcastId,
        StashItemId $stashItemId,
        MediaItemId $mediaItemId,
        BroadcastItemState $state = BroadcastItemState::Pending,
    ): BroadcastItemRecord {
        $id = $this->ids->generate('bitem')->toString();
        $record = new BroadcastItemRecord(
            broadcastId: $broadcastId,
            stashItemId: $stashItemId,
            mediaItemId: $mediaItemId,
            state: $state,
        );
        $record->id = new PrimaryKey($id);
        $now = DateTime::now(Timezone::UTC);
        $record->createdAt ??= $now;
        $record->updatedAt ??= $now;

        query(BroadcastItemRecord::class)->insert($record)->execute();

        return BroadcastItemRecord::select()
            ->get(new PrimaryKey($id))
            ?? throw new InvalidArgumentException('Failed to persist broadcast item record.');
    }

    public function find(BroadcastItemId $id): ?BroadcastItemRecord
    {
        return BroadcastItemRecord::select()->get($id->toPrimaryKey());
    }

    public function save(BroadcastItemRecord $record): BroadcastItemRecord
    {
        $record->updatedAt = DateTime::now(Timezone::UTC);
        $record->save();

        return $record;
    }

    /** @return list<BroadcastItemRecord> */
    public function listForBroadcast(BroadcastId $broadcastId): array
    {
        /** @var list<BroadcastItemRecord> $records */
        $records = BroadcastItemRecord::select()
            ->where('broadcastId', $broadcastId->toString())
            ->orderBy('createdAt', Direction::ASC)
            ->all();

        return $records;
    }

    public function findByBroadcastAndStashItem(
        BroadcastId $broadcastId,
        StashItemId $stashItemId,
    ): ?BroadcastItemRecord {
        /** @var BroadcastItemRecord|null $record */
        $record = BroadcastItemRecord::select()
            ->where('broadcastId', $broadcastId->toString())
            ->where('stashItemId', $stashItemId->toString())
            ->first();

        return $record;
    }

    /** @return list<BroadcastItemRecord> */
    public function listForMediaItem(MediaItemId $mediaItemId): array
    {
        /** @var list<BroadcastItemRecord> $records */
        $records = BroadcastItemRecord::select()
            ->with('broadcast')
            ->where('mediaItemId', $mediaItemId->toString())
            ->all();

        return $records;
    }
}
