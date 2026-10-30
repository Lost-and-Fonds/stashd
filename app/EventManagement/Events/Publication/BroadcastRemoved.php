<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Publication;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Broadcast Removed was removed.
 * Published results remain in history when another Item or a later republish fails; selection changes do not prune outputs.
 */
final readonly class BroadcastRemoved
{
    /**
     * Records that the Broadcast Removed was removed.
     */
    public function __construct(
        public string $broadcastId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
