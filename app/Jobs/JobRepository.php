<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Support\PrefixedUlid;
use App\Support\PrefixedUlidGenerator;
use Tempest\Database\Direction;
use Tempest\Database\PrimaryKey;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

use function Tempest\Database\query;

final class JobRepository
{
    public function __construct(
        private PrefixedUlidGenerator $ids,
    ) {}

    /** @param array<string, mixed>|null $payload */
    public function create(
        JobType $intent,
        ?string $entityType = null,
        ?PrefixedUlid $entityId = null,
        ?string $stashId = null,
        ?array $payload = null,
    ): JobRecord {
        $id = $this->ids->generate('job')->toString();
        $record = new JobRecord(
            intent: $intent->value,
            entityType: $entityType,
            entityId: $entityId?->toString(),
            stashId: $stashId,
            state: JobState::Pending,
            payload: $payload,
        );
        $record->id = new PrimaryKey($id);
        $now = DateTime::now(Timezone::UTC);
        $record->createdAt ??= $now;
        $record->updatedAt ??= $now;

        query(JobRecord::class)->insert($record)->execute();

        return $record;
    }

    /** @param array<string, mixed>|null $payload */
    public function createType(
        string $type,
        ?string $entityType = null,
        ?string $entityId = null,
        ?string $stashId = null,
        ?array $payload = null,
    ): JobRecord {
        $intent = new JobType($type);
        $record = $this->create($intent, entityType: $entityType, entityId: $entityId === null ? null : PrefixedUlid::parse($entityId), stashId: $stashId, payload: $payload);

        if ($stashId !== null) {
            $record->payload = [...($record->payload ?? []), 'stash_id' => $stashId];
        }

        return $this->save($record);
    }

    public function find(JobId $id): ?JobRecord
    {
        return JobRecord::findById($id->toPrimaryKey());
    }

    public function hasPendingOrProcessing(JobType $intent, PrefixedUlid $entityId): bool
    {
        return $this->pendingOrProcessing($intent, $entityId) !== null;
    }

    public function pendingOrProcessing(JobType $intent, PrefixedUlid $entityId): ?JobRecord
    {
        $job = JobRecord::select()
            ->where('intent', $intent->value)
            ->where('entityId', $entityId->toString())
            ->whereIn('state', [JobState::Pending, JobState::Processing, JobState::Retrying])
            ->first();

        return $job instanceof JobRecord ? $job : null;
    }

    public function hasPendingOrProcessingIntent(JobType $intent): bool
    {
        return JobRecord::select()
            ->where('intent', $intent->value)
            ->whereIn('state', [JobState::Pending, JobState::Processing, JobState::Retrying])
            ->first() !== null;
    }

    public function latestForEntity(JobType $intent, string $entityType, string $entityId): ?JobRecord
    {
        $job = JobRecord::select()
            ->where('intent', $intent->value)
            ->where('entityType', $entityType)
            ->where('entityId', $entityId)
            ->orderBy('createdAt', Direction::DESC)
            ->first();

        return $job instanceof JobRecord ? $job : null;
    }

    public function save(JobRecord $record): JobRecord
    {
        $record->updatedAt = DateTime::now(Timezone::UTC);
        $record->save();

        return $record;
    }

    /**
     * Processing jobs are always included, on top of the $limit most recent.
     * Active jobs are included, on top of the $limit most recent, so a plain
     * "ORDER BY createdAt DESC LIMIT $limit" reliably drops an active job
     * processing job during a large batch (e.g. backfilling a channel with
     * hundreds of items) -- which hid live download progress from the stash
     * detail page.
     *
     * @return list<JobRecord>
     */
    public function listRecent(int $limit = 50): array
    {
        /** @var list<JobRecord> $processing */
        $processing = array_values(JobRecord::select()
            ->where('state', JobState::Processing)
            ->orderBy('createdAt', Direction::ASC)
            ->all());

        /** @var list<JobRecord> $recent */
        $recent = array_values(JobRecord::select()
            ->orderBy('createdAt', Direction::DESC)
            ->limit($limit)
            ->all());

        $seen = [];
        $jobs = [];

        foreach ($processing as $job) {
            $seen[(string) $job->id] = true;
            $jobs[] = $job;
        }

        foreach ($recent as $job) {
            $id = (string) $job->id;

            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $jobs[] = $job;
        }

        return $jobs;
    }

    /**
     * The error message from each media item's most recent download job, but
     * only for items whose most recent attempt is the one that failed --
     * covers what the "why did this fail" tooltip needs without depending on
     * listRecent()'s bounded window, which a media item's download job can
     * easily fall out of by the time someone looks at a long-failed item.
     *
     * @param  list<string>  $mediaItemIds
     * @return array<string, string> lastError keyed by media item id
     */
    public function latestDownloadFailureByMediaItem(array $mediaItemIds): array
    {
        if ($mediaItemIds === []) {
            return [];
        }

        // createdAt is second-precision, so a retry issued within the same
        // second as the failure it's replacing would tie -- id (a ULID) is
        // monotonic and breaks the tie in actual creation order.
        /** @var list<JobRecord> $jobs */
        $jobs = array_values(JobRecord::select()
            ->where('entityType', 'media_item')
            ->where('intent', JobType::core('core.download')->value)
            ->whereIn('entityId', $mediaItemIds)
            ->orderBy('createdAt', Direction::DESC)
            ->orderBy('id', Direction::DESC)
            ->all());

        $latestByMediaItem = [];

        foreach ($jobs as $job) {
            $latestByMediaItem[(string) $job->entityId] ??= $job;
        }

        $failures = [];

        foreach ($latestByMediaItem as $mediaItemId => $job) {
            if ($job->state === JobState::Failed && $job->lastError !== null) {
                $failures[$mediaItemId] = $job->lastError;
            }
        }

        return $failures;
    }
}
