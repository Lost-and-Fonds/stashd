<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Integrity;

use App\EventManagement\Attributes\DurableHistory;
use App\EventManagement\Attributes\PremisSignificant;
use App\EventManagement\DTO\EventMetadata;

/**
 * Records confirmed damage to the identified archive scope based on positive evidence.
 * Verification evidence applies to the identified scope; inability to verify does not establish damage to unrelated Items.
 */
#[DurableHistory]
#[PremisSignificant]
final readonly class ArchiveDamageConfirmed
{
    /**
     * Records confirmed damage to the identified archive scope based on positive evidence.
     *
     * @param string $itemId Canonical Item affected by this fact.
     */
    public function __construct(
        public string $itemId,
        public string $evidenceReference,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
