<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Discovery;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the discovery batch and its checkpoint were accepted durably.
 * Coverage belongs to the logical discovery run; a checkpoint or interruption does not prove exhaustive refresh completion.
 */
final readonly class DiscoveryBatchCommitted
{
    /**
     * Records that the discovery batch and its checkpoint were accepted durably.
     *
     * @param list<string> $itemIds Identified item ids for this occurrence.
     */
    public function __construct(
        public string $discoveryRunId,
        public string $batchId,
        public array $itemIds,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
