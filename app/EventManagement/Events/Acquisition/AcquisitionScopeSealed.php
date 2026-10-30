<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Acquisition;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the known expected component set was closed to further additions for this acquisition scope.
 * Its typed IDs identify the subject and the operation that established this fact.
 */
final readonly class AcquisitionScopeSealed
{
    /**
     * Captures the established fact and its operation-specific references.
     * Callers must dispatch only after the stated occurrence condition is true.
     */
    public function __construct(
        public string $acquisitionId,
        public string $scopeId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
