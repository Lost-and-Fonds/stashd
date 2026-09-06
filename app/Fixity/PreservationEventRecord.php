<?php

declare(strict_types=1);

namespace App\Fixity;

use App\Vault\AssetId;
use Tempest\Database\IsDatabaseModel;
use Tempest\Database\PrimaryKey;
use Tempest\Database\Table;
use Tempest\DateTime\DateTime;

#[Table(name: 'preservation_events')]
final class PreservationEventRecord
{
    use IsDatabaseModel;

    public PrimaryKey $id;

    /** @param array<string, mixed>|null $detail */
    public function __construct(
        public AssetId $assetId,
        public PreservationEventType $eventType,
        public PreservationOutcome $outcome,
        public DateTime $occurredAt,
        public ?string $expectedChecksum = null,
        public ?string $observedChecksum = null,
        public ?string $jobId = null,
        public ?string $agent = null,
        public ?array $detail = null,
        public ?DateTime $createdAt = null,
    ) {}
}
