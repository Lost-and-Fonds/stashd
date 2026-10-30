<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Publication;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records creation of a Broadcast that publishes selected content; it does not synchronize or prune destination files.
 * Published results remain in history when another Item or a later republish fails; selection changes do not prune outputs.
 */
final readonly class BroadcastCreated
{
    /**
     * Records creation of a Broadcast that publishes selected content; it does not synchronize or prune destination files.
     */
    public function __construct(
        public string $broadcastId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
