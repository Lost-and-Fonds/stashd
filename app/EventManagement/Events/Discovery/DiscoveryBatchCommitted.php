<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Discovery;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the discovery batch and its checkpoint were accepted durably.
 * Its typed IDs identify the subject and the operation that established this fact.
 */
final readonly class DiscoveryBatchCommitted
{
    /**
     * Captures the established fact and its operation-specific references.
     * Callers must dispatch only after the stated occurrence condition is true.
     *
     * @param list<string> $itemIds Stable identifiers or references included in this event.
     */
    public function __construct(
        public string $discoveryRunId,
        public string $batchId,
        public array $itemIds,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
