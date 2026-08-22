<?php

declare(strict_types=1);

namespace App\System\Activity;

use App\Support\PrefixedUlidGenerator;
use Tempest\Database\Direction;
use Tempest\Database\PrimaryKey;

use function Tempest\Database\query;

use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

final class ActivityEventRepository
{
    public function __construct(
        private PrefixedUlidGenerator $ids,
    ) {}

    /** @param array<string, mixed>|null $metadata */
    public function create(
        ActivityLevel $level,
        string $type,
        string $message,
        ?string $entityType = null,
        ?string $entityId = null,
        ?string $stashId = null,
        ?string $mediaItemId = null,
        ?string $broadcastId = null,
        ?string $jobId = null,
        ?string $commandId = null,
        ?string $groupKey = null,
        ?array $metadata = null,
    ): ActivityEventRecord {
        $id = $this->ids->generate('activity')->toString();
        $record = new ActivityEventRecord(
            level: $level,
            type: $type,
            message: $message,
            entityType: $entityType,
            entityId: $entityId,
            stashId: $stashId,
            mediaItemId: $mediaItemId,
            broadcastId: $broadcastId,
            jobId: $jobId,
            commandId: $commandId,
            groupKey: $groupKey,
            metadata: $metadata,
        );
        $record->id = new PrimaryKey($id);
        $record->createdAt = DateTime::now(Timezone::UTC);

        query(ActivityEventRecord::class)->insert($record)->execute();

        return $record;
    }

    /** @return list<ActivityEventRecord> */
    public function listRecent(int $limit = 50): array
    {
        return ActivityEventRecord::select()
            ->orderBy('createdAt', Direction::DESC)
            ->orderBy('id', Direction::DESC)
            ->limit($limit)
            ->all();
    }
}
