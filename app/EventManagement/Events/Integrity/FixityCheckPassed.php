<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Integrity;

use App\EventManagement\Attributes\DurableHistory;
use App\EventManagement\Attributes\PremisSignificant;
use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Fixity Check passed.
 * Verification evidence applies to the identified scope; inability to verify does not establish damage to unrelated Items.
 */
#[DurableHistory]
#[PremisSignificant]
final readonly class FixityCheckPassed
{
    /**
     * Records that the Fixity Check passed.
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
        public string $verifiedDigest,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
