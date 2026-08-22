<?php

declare(strict_types=1);

namespace App\System\Activity;

use Tempest\Database\IsDatabaseModel;
use Tempest\Database\PrimaryKey;
use Tempest\Database\Table;
use Tempest\DateTime\DateTime;

#[Table(name: 'activity_events')]
final class ActivityEventRecord
{
    use IsDatabaseModel;

    public PrimaryKey $id;

    /** @param array<string, mixed>|null $metadata */
    public function __construct(
        public ActivityLevel $level,
        public string $type,
        public string $message,
        public ?string $entityType = null,
        public ?string $entityId = null,
        public ?string $stashId = null,
        public ?string $mediaItemId = null,
        public ?string $broadcastId = null,
        public ?string $jobId = null,
        public ?string $commandId = null,
        public ?string $groupKey = null,
        public ?array $metadata = null,
        public ?DateTime $createdAt = null,
    ) {}
}
