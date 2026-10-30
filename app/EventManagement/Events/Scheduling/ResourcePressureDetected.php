<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Scheduling;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records observed pressure on the named execution resource.
 * Dispatch and admission decisions do not establish that an Attempt started or completed.
 */
final readonly class ResourcePressureDetected
{
    /**
     * Records observed pressure on the named execution resource.
     */
    public function __construct(
        public string $resourceType,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
