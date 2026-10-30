<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Discovery;

use App\EventManagement\DTO\DiscoveryCoverage;
use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the logical discovery run completed with the declared exhaustive, partial or indeterminate coverage.
 * Its typed IDs identify the subject and the operation that established this fact.
 */
final readonly class DiscoveryRunCompleted
{
    /**
     * Captures the established fact and its operation-specific references.
     * Callers must dispatch only after the stated occurrence condition is true.
     */
    public function __construct(
        public string $discoveryRunId,
        public DiscoveryCoverage $coverage,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
