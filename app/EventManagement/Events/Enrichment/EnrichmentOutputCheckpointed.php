<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Enrichment;

use App\EventManagement\Attributes\TransientObservation;
use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Enrichment output was checkpointed.
 * Derived processing must retain source histories and exact input versions even when it fails.
 */
#[TransientObservation]
final readonly class EnrichmentOutputCheckpointed
{
    /**
     * Records that the Enrichment output was checkpointed.
     *
     * @param string $checkpointId Durably saved continuation position for this operation.
     * @param list<string> $outputReferences Identified output references for this occurrence.
     */
    public function __construct(
        public string $enrichmentId,
        public string $checkpointId,
        public array $outputReferences,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
