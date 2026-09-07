<?php

declare(strict_types=1);

namespace App\Fixity;

use App\Support\PrefixedUlidGenerator;
use App\Vault\AssetId;
use Tempest\Database\Direction;
use Tempest\Database\PrimaryKey;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

use function Tempest\Database\query;

final class PreservationEventRepository
{
    public function __construct(
        private PrefixedUlidGenerator $ids,
    ) {}

    /** @param array<string, mixed>|null $detail */
    public function create(
        AssetId $assetId,
        PreservationEventType $eventType,
        PreservationOutcome $outcome,
        ?string $expectedChecksum = null,
        ?string $observedChecksum = null,
        ?string $jobId = null,
        ?string $agent = 'stashd',
        ?array $detail = null,
        ?DateTime $occurredAt = null,
    ): PreservationEventRecord {
        $record = new PreservationEventRecord(
            assetId: $assetId,
            eventType: $eventType,
            outcome: $outcome,
            occurredAt: $occurredAt ?? DateTime::now(Timezone::UTC),
            expectedChecksum: $expectedChecksum,
            observedChecksum: $observedChecksum,
            jobId: $jobId,
            agent: $agent,
            detail: $detail,
        );
        $record->id = new PrimaryKey($this->ids->generate('preservation')->toString());
        $record->createdAt = DateTime::now(Timezone::UTC);

        query(PreservationEventRecord::class)->insert($record)->execute();

        return $record;
    }

    public function latestForAsset(AssetId $assetId, ?PreservationEventType $eventType = null): ?PreservationEventRecord
    {
        $query = PreservationEventRecord::select()
            ->where('assetId', $assetId->toString());

        if ($eventType !== null) {
            $query->where('eventType', $eventType);
        }

        $event = $query
            ->orderBy('occurredAt', Direction::DESC)
            ->orderBy('id', Direction::DESC)
            ->first();

        return $event instanceof PreservationEventRecord ? $event : null;
    }

    /** @param list<AssetId> $assetIds
     * @return array<string, PreservationEventRecord>
     */
    public function latestForAssets(array $assetIds, ?PreservationEventType $eventType = null): array
    {
        if ($assetIds === []) {
            return [];
        }

        $latest = [];
        $query = PreservationEventRecord::select()
            ->whereIn('assetId', array_map(static fn(AssetId $assetId): string => $assetId->toString(), $assetIds));

        if ($eventType !== null) {
            $query->where('eventType', $eventType);
        }

        $events = $query
            ->orderBy('occurredAt', Direction::DESC)
            ->orderBy('id', Direction::DESC)
            ->all();

        foreach ($events as $event) {
            if ($event instanceof PreservationEventRecord) {
                $latest[(string) $event->assetId] ??= $event;
            }
        }

        return $latest;
    }

    /** @return list<PreservationEventRecord> */
    public function listForAsset(AssetId $assetId): array
    {
        /** @var list<PreservationEventRecord> $events */
        $events = PreservationEventRecord::select()
            ->where('assetId', $assetId->toString())
            ->orderBy('occurredAt', Direction::ASC)
            ->orderBy('id', Direction::ASC)
            ->all();

        return $events;
    }
}
