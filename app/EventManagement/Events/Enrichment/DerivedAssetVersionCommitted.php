<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Enrichment;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Derived was committed.
 * Derived processing must retain source histories and exact input versions even when it fails.
 */
final readonly class DerivedAssetVersionCommitted
{
    /**
     * Records that the Derived was committed.
     *
     * @param string $itemId Canonical Item affected by this fact.
     * @param string $assetId Asset whose bytes or history are identified.
     * @param string $assetVersion Exact historical OCFL Asset version, never a latest selector.
     */
    public function __construct(
        public string $enrichmentId,
        public string $itemId,
        public string $assetId,
        public string $assetVersion,
        public string $producerId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
