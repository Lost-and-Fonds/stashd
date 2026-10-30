<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Enrichment;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Enrichment completed.
 * The typed identifiers distinguish this occurrence from other operations on the same subject.
 */
final readonly class EnrichmentCompleted
{
    /**
     * Captures the established fact and its operation-specific references.
     * Callers must dispatch only after the stated occurrence condition is true.
     *
     * @param list<string> $outputAssetVersions Derived Asset versions that are present and verified.
     */
    public function __construct(
        public string $enrichmentId,
        public string $itemId,
        public array $outputAssetVersions,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
