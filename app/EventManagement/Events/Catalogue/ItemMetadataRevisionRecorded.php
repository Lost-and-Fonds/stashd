<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Catalogue;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records a new revision of source metadata for an Item. It does not replace the separate editorial revision history.
 * Its typed IDs identify the subject and the operation that established this fact.
 */
final readonly class ItemMetadataRevisionRecorded
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
