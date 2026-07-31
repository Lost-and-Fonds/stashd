<?php

declare(strict_types=1);

namespace App\Broadcasts;

use App\Support\PrefixedUlidGenerator;
use Tempest\Database\Builder\WhereOperator;
use Tempest\Database\Direction;
use Tempest\Database\PrimaryKey;

use function Tempest\Database\query;

use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

final class SponsorBlockRefreshRepository
{
    public function __construct(private PrefixedUlidGenerator $ids)
    {
    }

    public function create(BroadcastItemId $broadcastItemId, DateTime $nextCheckAt, DateTime $expiresAt): SponsorBlockRefreshRecord
    {
        $now = DateTime::now(Timezone::UTC);
        $record = new SponsorBlockRefreshRecord($broadcastItemId, $nextCheckAt, $expiresAt, createdAt: $now, updatedAt: $now);
        $record->id = new PrimaryKey($this->ids->generate('sbrefresh')->toString());
        query(SponsorBlockRefreshRecord::class)->insert($record)->execute();

        return $record;
    }

    public function findForBroadcastItem(BroadcastItemId $broadcastItemId): ?SponsorBlockRefreshRecord
    {
        $record = SponsorBlockRefreshRecord::select()
            ->where('broadcastItemId', $broadcastItemId->toString())
            ->first();

        return $record instanceof SponsorBlockRefreshRecord ? $record : null;
    }

    /** @return list<SponsorBlockRefreshRecord> */
    public function listDue(DateTime $now): array
    {
        $records = SponsorBlockRefreshRecord::select()
            ->whereNull('completedAt')
            ->where('nextCheckAt', $now, WhereOperator::LESS_THAN_OR_EQUAL)
            ->where('expiresAt', $now, WhereOperator::GREATER_THAN_OR_EQUAL)
            ->orderBy('nextCheckAt', Direction::ASC)
            ->all();

        return array_values(array_filter($records, static fn (mixed $record): bool => $record instanceof SponsorBlockRefreshRecord));
    }

    public function complete(SponsorBlockRefreshRecord $record): void
    {
        $record->completedAt = DateTime::now(Timezone::UTC);
        $record->lastCheckedAt = $record->completedAt;
        $record->lastError = null;
        $record->updatedAt = $record->completedAt;
        $record->save();
    }

    public function reschedule(SponsorBlockRefreshRecord $record, DateTime $now, ?string $error = null): void
    {
        $record->lastCheckedAt = $now;
        $record->lastError = $error;
        $nextCheckAt = $now->plusHours(1);

        if ($nextCheckAt->isAfter($record->expiresAt)) {
            $record->completedAt = $now;
        } else {
            $record->nextCheckAt = $nextCheckAt;
        }

        $record->updatedAt = $now;
        $record->save();
    }
}
