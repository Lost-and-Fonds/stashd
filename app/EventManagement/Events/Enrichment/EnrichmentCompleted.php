<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Enrichment;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Enrichment completed.
 * Derived processing must retain source histories and exact input versions even when it fails.
 */
final readonly class EnrichmentCompleted
{
    /**
     * Records that the Enrichment completed.
     *
     * @param string $itemId Canonical Item affected by this fact.
     * @param list<string> $outputAssetVersions Identified output asset versions for this occurrence.
     */
    public function __construct(
        public string $enrichmentId,
        public string $itemId,
        public array $outputAssetVersions,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
