<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Publication;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Broadcast Updated was updated.
 * The typed identifiers distinguish this occurrence from other operations on the same subject.
 */
final readonly class BroadcastUpdated
{
    /**
     * Captures the established fact and its operation-specific references.
     * Callers must dispatch only after the stated occurrence condition is true.
     */
    public function __construct(
        public string $broadcastId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
