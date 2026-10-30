<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Scheduling;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that resource capacity prevented admission, with the reason for deferral.
 * Dispatch and admission decisions do not establish that an Attempt started or completed.
 */
final readonly class ResourceAdmissionDeferred
{
    /**
     * Records that resource capacity prevented admission, with the reason for deferral.
     *
     * @param string $reasonCode Safe machine-readable explanation, without process output or secrets.
     */
    public function __construct(
        public string $resourceType,
        public string $reasonCode,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
