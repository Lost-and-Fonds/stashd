<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Enrichment;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Enrichment started.
 * Derived processing must retain source histories and exact input versions even when it fails.
 */
final readonly class EnrichmentStarted
{
    /**
     * Records that the Enrichment started.
     *
     * @param string $itemId Canonical Item affected by this fact.
     */
    public function __construct(
        public string $enrichmentId,
        public string $itemId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
