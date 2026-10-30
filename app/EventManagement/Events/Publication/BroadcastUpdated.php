<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Publication;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Broadcast Updated was updated.
 * Published results remain in history when another Item or a later republish fails; selection changes do not prune outputs.
 */
final readonly class BroadcastUpdated
{
    /**
     * Records that the Broadcast Updated was updated.
     */
    public function __construct(
        public string $broadcastId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
