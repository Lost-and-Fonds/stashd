<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Integrity;

use App\EventManagement\Attributes\DurableHistory;
use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Archive Repair started.
 * The typed identifiers distinguish this occurrence from other operations on the same subject.
 */
#[DurableHistory]
final readonly class ArchiveRepairStarted
{
    /**
     * Captures the established fact and its operation-specific references.
     * Callers must dispatch only after the stated occurrence condition is true.
     */
    public function __construct(
        public string $itemId,
        public string $repairId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
