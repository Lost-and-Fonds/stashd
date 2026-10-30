<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Sources;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the identified Item was confirmed available through the identified Input, based on positive evidence.
 * Its typed IDs identify the subject and the operation that established this fact.
 */
final readonly class SourceAvailabilityConfirmed
{
    /**
     * Captures the established fact and its operation-specific references.
     * Callers must dispatch only after the stated occurrence condition is true.
     */
    public function __construct(
        public string $inputId,
        public string $itemId,
        public string $evidenceReference,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
