<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Discovery;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Discovery was cancelled.
 * The typed identifiers distinguish this occurrence from other operations on the same subject.
 */
final readonly class DiscoveryRunCancelled
{
    /**
     * Captures the established fact and its operation-specific references.
     * Callers must dispatch only after the stated occurrence condition is true.
     */
    public function __construct(
        public string $discoveryRunId,
        public string $reasonCode,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
