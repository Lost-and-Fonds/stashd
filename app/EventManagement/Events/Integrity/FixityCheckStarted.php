<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Integrity;

use App\EventManagement\Attributes\DurableHistory;
use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Fixity Check started.
 * Verification evidence applies to the identified scope; inability to verify does not establish damage to unrelated Items.
 */
#[DurableHistory]
final readonly class FixityCheckStarted
{
    /**
     * Records that the Fixity Check started.
     *
     * @param string $itemId Canonical Item affected by this fact.
     * @param string $assetId Asset whose bytes or history are identified.
     * @param string $assetVersion Exact historical OCFL Asset version, never a latest selector.
     */
    public function __construct(
        public string $itemId,
        public string $assetId,
        public string $assetVersion,
        public string $checkId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
