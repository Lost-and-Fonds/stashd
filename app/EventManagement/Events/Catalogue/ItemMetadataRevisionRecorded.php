<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Catalogue;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records a new revision of source metadata for an Item. It does not replace the separate editorial revision history.
 * Stash membership and display changes do not delete or rewrite canonical preserved bytes.
 */
final readonly class ItemMetadataRevisionRecorded
{
    /**
     * Records a new revision of source metadata for an Item. It does not replace the separate editorial revision history.
     *
     * @param string $itemId Canonical Item affected by this fact.
     */
    public function __construct(
        public string $itemId,
        public string $revisionId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
