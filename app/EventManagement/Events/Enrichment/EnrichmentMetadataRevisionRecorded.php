<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Enrichment;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Enrichment a metadata revision was recorded.
 * Derived processing must retain source histories and exact input versions even when it fails.
 */
final readonly class EnrichmentMetadataRevisionRecorded
{
    /**
     * Records that the Enrichment a metadata revision was recorded.
     *
     * @param string $itemId Canonical Item affected by this fact.
     */
    public function __construct(
        public string $enrichmentId,
        public string $itemId,
        public string $revisionId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
