<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Enrichment;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records the enrichment planned observation for the identified operation.
 * Derived processing must retain source histories and exact input versions even when it fails.
 */
final readonly class EnrichmentPlanned
{
    /**
     * Records the enrichment planned observation for the identified operation.
     *
     * @param string $itemId Canonical Item affected by this fact.
     */
    public function __construct(
        public string $enrichmentId,
        public string $itemId,
        public string $inputAssetId,
        public string $inputAssetVersion,
        public string $capabilityId,
        public string $pluginId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
