<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Integrity;

use App\EventManagement\Attributes\DurableHistory;
use App\EventManagement\Attributes\PremisSignificant;
use App\EventManagement\DTO\EventMetadata;

/**
 * Records confirmed damage to the identified archive scope based on positive evidence.
 * The typed identifiers distinguish this occurrence from other operations on the same subject.
 */
#[DurableHistory]
#[PremisSignificant]
final readonly class ArchiveDamageConfirmed
{
    /**
     * Captures the established fact and its operation-specific references.
     * Callers must dispatch only after the stated occurrence condition is true.
     */
    public function __construct(
        public string $itemId,
        public string $evidenceReference,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
