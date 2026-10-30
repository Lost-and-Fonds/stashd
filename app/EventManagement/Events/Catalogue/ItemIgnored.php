<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Catalogue;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Item was ignored.
 * Stash membership and display changes do not delete or rewrite canonical preserved bytes.
 */
final readonly class ItemIgnored
{
    /**
     * Records that the Item was ignored.
     *
     * @param string $itemId Canonical Item affected by this fact.
     * @param string $reasonCode Safe machine-readable explanation, without process output or secrets.
     */
    public function __construct(
        public string $itemId,
        public string $reasonCode,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
