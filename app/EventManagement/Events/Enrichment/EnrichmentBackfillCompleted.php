<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Enrichment;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Enrichment a backfill completed.
 * Derived processing must retain source histories and exact input versions even when it fails.
 */
final readonly class EnrichmentBackfillCompleted
{
    /**
     * Records that the Enrichment a backfill completed.
     */
    public function __construct(
        public string $enrichmentId,
        public string $pluginId,
        public string $backfillId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
