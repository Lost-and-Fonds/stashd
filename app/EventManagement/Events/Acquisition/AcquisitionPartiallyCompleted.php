<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Acquisition;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records acquisition completion with the identified known gaps; it does not erase successfully preserved partial material.
 * Its typed IDs identify the subject and the operation that established this fact.
 */
final readonly class AcquisitionPartiallyCompleted
{
    /**
     * Captures the established fact and its operation-specific references.
     * Callers must dispatch only after the stated occurrence condition is true.
     *
     * @param list<string> $missingComponentIds Stable identifiers or references included in this event.
     */
    public function __construct(
        public string $acquisitionId,
        public array $missingComponentIds,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
