<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Acquisition;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records a component added to the expected acquisition set. An estimated size is not an exact byte count.
 * Its typed IDs identify the subject and the operation that established this fact.
 */
final readonly class ExpectedComponentDeclared
{
    /**
     * Captures the established fact and its operation-specific references.
     * Callers must dispatch only after the stated occurrence condition is true.
     */
    public function __construct(
        public string $acquisitionId,
        public string $componentId,
        public ?int $sizeBytes,
        public bool $sizeIsEstimate,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
