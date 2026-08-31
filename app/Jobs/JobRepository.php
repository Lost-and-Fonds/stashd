<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Commands\CommandId;
use App\Support\PrefixedUlid;
use App\Support\PrefixedUlidGenerator;
use Tempest\Database\Connection\Connection;
use Tempest\Database\Direction;
use Tempest\Database\PrimaryKey;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\FormatPattern;
use Tempest\DateTime\Timezone;

use function Tempest\Database\query;

final class JobRepository
{
    public function __construct(
        private PrefixedUlidGenerator $ids,
        private Connection $connection,
    ) {}

    /** @param array<string, mixed>|null $payload */
    public function create(
        JobIntent $intent,
        ?CommandId $commandId = null,
        ?string $entityType = null,
        ?PrefixedUlid $entityId = null,
        int $priority = 100,
        ?array $payload = null,
    ): JobRecord {
        $id = $this->ids->generate('job')->toString();
        $record = new JobRecord(
            commandId: $commandId,
            intent: $intent,
            entityType: $entityType,
            entityId: $entityId?->toString(),
            state: JobState::Pending,
            priority: $priority,
            payload: $payload,
        );
        $record->id = new PrimaryKey($id);
        $now = DateTime::now(Timezone::UTC);
        $record->createdAt ??= $now;
        $record->updatedAt ??= $now;

        query(JobRecord::class)->insert($record)->execute();

        return $record;
    }

    public function find(JobId $id): ?JobRecord
    {
        return JobRecord::findById($id->toPrimaryKey());
    }

    public function hasPendingOrProcessing(JobIntent $intent, PrefixedUlid $entityId): bool
    {
        return $this->pendingOrProcessing($intent, $entityId) !== null;
    }

    public function pendingOrProcessing(JobIntent $intent, PrefixedUlid $entityId): ?JobRecord
    {
        $job = JobRecord::select()
            ->where('intent', $intent)
            ->where('entityId', $entityId->toString())
            ->whereIn('state', [JobState::Pending, JobState::Processing])
            ->first();

        return $job instanceof JobRecord ? $job : null;
    }

    public function hasPendingOrProcessingIntent(JobIntent $intent): bool
    {
        return JobRecord::select()
            ->where('intent', $intent)
            ->whereIn('state', [JobState::Pending, JobState::Processing])
            ->first() !== null;
    }

    public function save(JobRecord $record): JobRecord
    {
        $record->updatedAt = DateTime::now(Timezone::UTC);
        $record->save();

        return $record;
    }

    /**
     * PostgreSQL claims one locked candidate with SKIP LOCKED in a single
     * statement. The owner token lets stale-job recovery verify the owning
     * process is dead before re-queuing.
     */
    public function claimNextPending(?JobLane $lane = null, ?string $ownerToken = null): ?JobRecord
    {
        return $this->claimNextPendingPostgres($lane, $ownerToken);
    }

    private function claimNextPendingPostgres(?JobLane $lane, ?string $ownerToken): ?JobRecord
    {
        $bindings = [
            'pending' => JobState::Pending->value,
            'now' => DateTime::now(Timezone::UTC)->format(FormatPattern::SQL_DATE_TIME, Timezone::UTC),
        ];
        $where = [
            '"state" = :pending',
            '("scheduledAt" IS NULL OR "scheduledAt" <= :now)',
        ];

        if ($lane !== null) {
            $placeholders = [];

            foreach ($lane->intents() as $index => $intent) {
                $key = "intent_{$index}";
                $placeholders[] = ":{$key}";
                $bindings[$key] = $intent->value;
            }
            $where[] = '"intent" IN (' . implode(', ', $placeholders) . ')';
        }

        $statement = $this->connection->prepare(
            'UPDATE "jobs"
             SET "state" = :processing, "attempts" = "attempts" + 1,
                 "startedAt" = CURRENT_TIMESTAMP, "heartbeatAt" = CURRENT_TIMESTAMP,
                 "updatedAt" = CURRENT_TIMESTAMP, "lastError" = NULL, "ownerToken" = :ownerToken
             WHERE "id" = (
                SELECT "id" FROM "jobs" WHERE ' . implode(' AND ', $where)
                . ' ORDER BY "priority" ASC, "createdAt" ASC LIMIT 1 FOR UPDATE SKIP LOCKED
             )
             RETURNING "id"',
        );
        $statement->execute([
            ...$bindings,
            'processing' => JobState::Processing->value,
            'ownerToken' => $ownerToken,
        ]);
        $id = $statement->fetchColumn();

        return is_string($id) ? JobRecord::findById(new PrimaryKey($id)) : null;
    }

    public function parkForRetry(JobRecord $job, string $lastError, DateTime $scheduledAt): bool
    {
        $updatedAt = DateTime::now(Timezone::UTC);
        $statement = $this->connection->prepare(
            'UPDATE "jobs"
             SET "state" = :pending, "startedAt" = NULL, "heartbeatAt" = NULL,
                 "ownerToken" = NULL, "lastError" = :lastError,
                 "scheduledAt" = :scheduledAt, "updatedAt" = :updatedAt
             WHERE "id" = :id AND "state" = :processing',
        );
        $statement->execute([
            'pending' => JobState::Pending->value,
            'lastError' => $lastError,
            'scheduledAt' => $scheduledAt->format(FormatPattern::SQL_DATE_TIME, Timezone::UTC),
            'updatedAt' => $updatedAt->format(FormatPattern::SQL_DATE_TIME, Timezone::UTC),
            'id' => (string) $job->id,
            'processing' => JobState::Processing->value,
        ]);

        if ($statement->rowCount() !== 1) {
            return false;
        }

        $job->state = JobState::Pending;
        $job->startedAt = null;
        $job->heartbeatAt = null;
        $job->ownerToken = null;
        $job->lastError = $lastError;
        $job->scheduledAt = $scheduledAt;
        $job->updatedAt = $updatedAt;

        return true;
    }

    /** @return list<JobRecord> */
    public function listProcessingStale(DateTime $staleBefore): array
    {
        /** @var list<JobRecord> $jobs */
        $jobs = array_values(JobRecord::select()
            ->where('state', JobState::Processing)
            ->whereNotNull('heartbeatAt')
            ->where('heartbeatAt', $staleBefore, '<')
            ->all());

        return $jobs;
    }

    /**
     * Processing jobs are always included, on top of the $limit most recent.
     * They're claimed oldest-first (see claimNextPending), so a plain
     * "ORDER BY createdAt DESC LIMIT $limit" reliably drops the one actively
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

    /** @return list<JobRecord> */
    public function listForCommand(CommandId $commandId): array
    {
        /** @var list<JobRecord> $jobs */
        $jobs = array_values(JobRecord::select()
            ->where('commandId', $commandId->toString())
            ->orderBy('createdAt', Direction::ASC)
            ->all());

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
            ->where('intent', JobIntent::Download)
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
