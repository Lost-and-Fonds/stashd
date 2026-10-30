<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Acquisition;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Reacquisition started.
 * The typed identifiers distinguish this occurrence from other operations on the same subject.
 */
final readonly class ReacquisitionStarted
{
    /**
     * Captures the established fact and its operation-specific references.
     * Callers must dispatch only after the stated occurrence condition is true.
     */
    public function __construct(
        public string $acquisitionId,
        public string $parentAcquisitionId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
