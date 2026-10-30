<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Catalogue;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records an editorial revision for an Item, separate from source-provided metadata.
 * Stash membership and display changes do not delete or rewrite canonical preserved bytes.
 */
final readonly class ItemEditorialRevisionRecorded
{
    /**
     * Records an editorial revision for an Item, separate from source-provided metadata.
     *
     * @param string $itemId Canonical Item affected by this fact.
     */
    public function __construct(
        public string $itemId,
        public string $revisionId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
