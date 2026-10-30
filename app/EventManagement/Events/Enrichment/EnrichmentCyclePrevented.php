<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Enrichment;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Enrichment was prevented as a cycle.
 * Derived processing must retain source histories and exact input versions even when it fails.
 */
final readonly class EnrichmentCyclePrevented
{
    /**
     * Records that the Enrichment was prevented as a cycle.
     *
     * @param string $itemId Canonical Item affected by this fact.
     * @param string $reasonCode Safe machine-readable explanation, without process output or secrets.
     */
    public function __construct(
        public string $enrichmentId,
        public string $itemId,
        public string $inputAssetVersion,
        public string $reasonCode,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
