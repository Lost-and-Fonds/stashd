<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Integrity;

use App\EventManagement\Attributes\DurableHistory;
use App\EventManagement\Attributes\PremisSignificant;
use App\EventManagement\DTO\EventMetadata;

/**
 * Records repair only after the restored exact historical Asset versions passed required verification.
 * Its typed IDs identify the subject and the operation that established this fact.
 */
#[DurableHistory]
#[PremisSignificant]
final readonly class ArchiveRepairCompleted
{
    /**
     * Captures the established fact and its operation-specific references.
     * Callers must dispatch only after the stated occurrence condition is true.
     *
     * @param list<string> $restoredAssetVersions Exact historical Asset version references verified after repair.
     */
    public function __construct(
        public string $itemId,
        public string $repairId,
        public array $restoredAssetVersions,
        public string $verificationReference,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
