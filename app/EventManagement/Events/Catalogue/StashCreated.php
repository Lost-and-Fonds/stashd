<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Catalogue;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Stash was created.
 * Stash membership and display changes do not delete or rewrite canonical preserved bytes.
 */
final readonly class StashCreated
{
    /**
     * Records that the Stash was created.
     *
     * @param string $stashId Organisational Stash affected by the change.
     */
    public function __construct(
        public string $stashId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
