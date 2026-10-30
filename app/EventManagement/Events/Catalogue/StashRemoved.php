<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Catalogue;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that an organisational Stash was removed. This does not delete Items or preserved content.
 * Stash membership and display changes do not delete or rewrite canonical preserved bytes.
 */
final readonly class StashRemoved
{
    /**
     * Records that an organisational Stash was removed. This does not delete Items or preserved content.
     *
     * @param string $stashId Organisational Stash affected by the change.
     */
    public function __construct(
        public string $stashId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
