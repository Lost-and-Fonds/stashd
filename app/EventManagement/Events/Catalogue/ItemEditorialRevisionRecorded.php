<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Catalogue;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records an editorial revision for an Item, separate from source-provided metadata.
 * Its typed IDs identify the subject and the operation that established this fact.
 */
final readonly class ItemEditorialRevisionRecorded
{
    /**
     * Captures the established fact and its operation-specific references.
     * Callers must dispatch only after the stated occurrence condition is true.
     */
    public function __construct(
        public string $itemId,
        public string $revisionId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
