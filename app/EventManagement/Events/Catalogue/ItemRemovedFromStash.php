<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Catalogue;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that an Item was removed from an organisational Stash. This does not delete the Item or its preserved content.
 * Stash membership and display changes do not delete or rewrite canonical preserved bytes.
 */
final readonly class ItemRemovedFromStash
{
    /**
     * Records that an Item was removed from an organisational Stash. This does not delete the Item or its preserved content.
     *
     * @param string $itemId Canonical Item affected by this fact.
     * @param string $stashId Organisational Stash affected by the change.
     */
    public function __construct(
        public string $itemId,
        public string $stashId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
