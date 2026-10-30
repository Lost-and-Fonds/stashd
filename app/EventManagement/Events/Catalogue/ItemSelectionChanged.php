<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Catalogue;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Item selection changed.
 * Stash membership and display changes do not delete or rewrite canonical preserved bytes.
 */
final readonly class ItemSelectionChanged
{
    /**
     * Records that the Item selection changed.
     *
     * @param string $itemId Canonical Item affected by this fact.
     * @param string $stashId Organisational Stash affected by the change.
     */
    public function __construct(
        public string $itemId,
        public string $stashId,
        public bool $selected,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
