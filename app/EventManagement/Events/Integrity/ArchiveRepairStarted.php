<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Integrity;

use App\EventManagement\Attributes\DurableHistory;
use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Archive Repair started.
 * Verification evidence applies to the identified scope; inability to verify does not establish damage to unrelated Items.
 */
#[DurableHistory]
final readonly class ArchiveRepairStarted
{
    /**
     * Records that the Archive Repair started.
     *
     * @param string $itemId Canonical Item affected by this fact.
     */
    public function __construct(
        public string $itemId,
        public string $repairId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
