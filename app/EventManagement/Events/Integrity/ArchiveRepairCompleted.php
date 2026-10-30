<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Integrity;

use App\EventManagement\Attributes\DurableHistory;
use App\EventManagement\Attributes\PremisSignificant;
use App\EventManagement\DTO\EventMetadata;

/**
 * Records repair only after the restored exact historical Asset versions passed required verification.
 * Verification evidence applies to the identified scope; inability to verify does not establish damage to unrelated Items.
 */
#[DurableHistory]
#[PremisSignificant]
final readonly class ArchiveRepairCompleted
{
    /**
     * Records repair only after the restored exact historical Asset versions passed required verification.
     *
     * @param string $itemId Canonical Item affected by this fact.
     * @param list<string> $restoredAssetVersions Identified restored asset versions for this occurrence.
     */
    public function __construct(
        public string $itemId,
        public string $repairId,
        public array $restoredAssetVersions,
        public string $verificationReference,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
