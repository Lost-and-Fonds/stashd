<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Enrichment;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Enrichment input versions were pinned.
 * Derived processing must retain source histories and exact input versions even when it fails.
 */
final readonly class EnrichmentInputVersionsPinned
{
    /**
     * Records that the Enrichment input versions were pinned.
     *
     * @param string $itemId Canonical Item affected by this fact.
     * @param list<string> $inputAssetVersions Identified input asset versions for this occurrence.
     */
    public function __construct(
        public string $enrichmentId,
        public string $itemId,
        public array $inputAssetVersions,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
