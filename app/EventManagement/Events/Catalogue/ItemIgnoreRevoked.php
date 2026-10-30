<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Catalogue;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Item Ignore was revoked.
 * Stash membership and display changes do not delete or rewrite canonical preserved bytes.
 */
final readonly class ItemIgnoreRevoked
{
    /**
     * Records that the Item Ignore was revoked.
     *
     * @param string $itemId Canonical Item affected by this fact.
     */
    public function __construct(
        public string $itemId,
        public string $previousIgnoreId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
