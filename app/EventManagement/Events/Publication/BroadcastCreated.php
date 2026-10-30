<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Publication;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records creation of a Broadcast that publishes selected content; it does not synchronize or prune destination files.
 * Its typed IDs identify the subject and the operation that established this fact.
 */
final readonly class BroadcastCreated
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
