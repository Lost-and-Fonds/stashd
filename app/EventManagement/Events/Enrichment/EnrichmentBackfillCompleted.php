<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Enrichment;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Enrichment a backfill completed.
 * The typed identifiers distinguish this occurrence from other operations on the same subject.
 */
final readonly class EnrichmentBackfillCompleted
{
    /**
     * Captures the established fact and its operation-specific references.
     * Callers must dispatch only after the stated occurrence condition is true.
     */
    public function __construct(
        public string $enrichmentId,
        public string $pluginId,
        public string $backfillId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
