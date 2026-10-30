<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Enrichment;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Enrichment a backfill started.
 * Derived processing must retain source histories and exact input versions even when it fails.
 */
final readonly class EnrichmentBackfillStarted
{
    /**
     * Records that the Enrichment a backfill started.
     */
    public function __construct(
        public string $enrichmentId,
        public string $pluginId,
        public string $backfillId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
