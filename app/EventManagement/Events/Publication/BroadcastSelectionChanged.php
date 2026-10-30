<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Publication;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records which Items entered or left the Broadcast selection. Deselecting an Item does not delete an earlier published output.
 * Its typed IDs identify the subject and the operation that established this fact.
 */
final readonly class BroadcastSelectionChanged
{
    /**
     * Captures the established fact and its operation-specific references.
     * Callers must dispatch only after the stated occurrence condition is true.
     *
     * @param list<string> $itemIds Items whose membership in the selection changed.
     */
    public function __construct(
        public string $broadcastId,
        public array $itemIds,
        public bool $selected,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
