<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Preservation;

use App\EventManagement\Attributes\DurableHistory;
use App\EventManagement\DTO\EventMetadata;

/**
 * Records recovery of the identified preservation transaction from established archival evidence.
 * Its typed IDs identify the subject and the operation that established this fact.
 */
#[DurableHistory]
final readonly class PreservationCommitRecovered
{
    /**
     * Captures the established fact and its operation-specific references.
     * Callers must dispatch only after the stated occurrence condition is true.
     */
    public function __construct(
        public string $transactionId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
