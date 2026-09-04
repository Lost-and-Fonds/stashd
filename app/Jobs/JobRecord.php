<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Support\DurationSecondsCaster;
use App\Support\DurationSecondsSerializer;
use Tempest\Database\IsDatabaseModel;
use Tempest\Database\PrimaryKey;
use Tempest\Database\Table;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Duration;
use Tempest\Mapper\CastWith;
use Tempest\Mapper\SerializeWith;

#[Table(name: 'jobs')]
final class JobRecord
{
    use IsDatabaseModel;

    public PrimaryKey $id;

    /** @param array<string, mixed>|null $payload */
    public function __construct(
        public string $intent,
        public ?string $entityType,
        public ?string $entityId,
        public JobState $state,
        public ?string $stashId = null,
        public int $attempts = 0,
        public ?DateTime $startedAt = null,
        public ?DateTime $finishedAt = null,
        public ?int $progressCurrent = null,
        public ?int $progressTotal = null,
        public ?float $progressPercent = null,
        public ?string $progressLabel = null,
        public ?float $progressRate = null,
        #[CastWith(DurationSecondsCaster::class)]
        #[SerializeWith(DurationSecondsSerializer::class)]
        public ?Duration $progressEtaSeconds = null,
        public ?string $lastError = null,
        public ?array $payload = null,
        public ?DateTime $createdAt = null,
        public ?DateTime $updatedAt = null,
    ) {}

    public function type(): string
    {
        return $this->intent;
    }
}
