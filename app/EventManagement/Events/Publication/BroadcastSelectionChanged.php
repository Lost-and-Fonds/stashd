<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Publication;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records which Items entered or left the Broadcast selection. Deselecting an Item does not delete an earlier published output.
 * Published results remain in history when another Item or a later republish fails; selection changes do not prune outputs.
 */
final readonly class BroadcastSelectionChanged
{
    /**
     * Records which Items entered or left the Broadcast selection. Deselecting an Item does not delete an earlier published output.
     *
     * @param list<string> $itemIds Identified item ids for this occurrence.
     */
    public function __construct(
        public string $broadcastId,
        public array $itemIds,
        public bool $selected,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
