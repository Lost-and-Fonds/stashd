<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Scheduling;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Resource Pressure Detected resource pressure was detected.
 * The typed identifiers distinguish this occurrence from other operations on the same subject.
 */
final readonly class ResourcePressureDetected
{
    /**
     * Captures the established fact and its operation-specific references.
     * Callers must dispatch only after the stated occurrence condition is true.
     */
    public function __construct(
        public string $resourceType,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
