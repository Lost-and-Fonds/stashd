<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Catalogue;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records a changed display projection without changing the historical source metadata revisions.
 * Stash membership and display changes do not delete or rewrite canonical preserved bytes.
 */
final readonly class ItemDisplayProjectionChanged
{
    /**
     * Records a changed display projection without changing the historical source metadata revisions.
     *
     * @param string $itemId Canonical Item affected by this fact.
     */
    public function __construct(
        public string $itemId,
        public string $projectionVersion,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
