<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Catalogue;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records a changed display projection without changing the historical source metadata revisions.
 * Its typed IDs identify the subject and the operation that established this fact.
 */
final readonly class ItemDisplayProjectionChanged
{
    /**
     * Captures the established fact and its operation-specific references.
     * Callers must dispatch only after the stated occurrence condition is true.
     */
    public function __construct(
        public string $itemId,
        public string $projectionVersion,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
