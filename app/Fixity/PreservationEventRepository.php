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

    public function latestForAsset(AssetId $assetId): ?PreservationEventRecord
    {
        $event = PreservationEventRecord::select()
            ->where('assetId', $assetId->toString())
            ->orderBy('occurredAt', Direction::DESC)
            ->orderBy('id', Direction::DESC)
            ->first();

        return $event instanceof PreservationEventRecord ? $event : null;
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
