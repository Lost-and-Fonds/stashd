<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Enrichment;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Enrichment input versions were pinned.
 * The typed identifiers distinguish this occurrence from other operations on the same subject.
 */
final readonly class EnrichmentInputVersionsPinned
{
    /**
     * Captures the established fact and its operation-specific references.
     * Callers must dispatch only after the stated occurrence condition is true.
     *
     * @param list<string> $inputAssetVersions Stable identifiers or references included in this event.
     */
    public function __construct(
        public string $enrichmentId,
        public string $itemId,
        public array $inputAssetVersions,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
