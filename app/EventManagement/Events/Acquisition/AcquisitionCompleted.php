<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Acquisition;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records completion against the sealed declared acquisition scope, not everything that may ever exist upstream.
 * Its typed IDs identify the subject and the operation that established this fact.
 */
final readonly class AcquisitionCompleted
{
    /**
     * Captures the established fact and its operation-specific references.
     * Callers must dispatch only after the stated occurrence condition is true.
     */
    public function __construct(
        public string $acquisitionId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
