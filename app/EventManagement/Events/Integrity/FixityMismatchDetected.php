<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Integrity;

use App\EventManagement\Attributes\DurableHistory;
use App\EventManagement\Attributes\PremisSignificant;
use App\EventManagement\DTO\EventMetadata;

/**
 * A mismatch records different observed and expected digests, without claiming the full cause is known.
 * Verification evidence applies to the identified scope; inability to verify does not establish damage to unrelated Items.
 */
#[DurableHistory]
#[PremisSignificant]
final readonly class FixityMismatchDetected
{
    /**
     * A mismatch records different observed and expected digests, without claiming the full cause is known.
     *
     * @param string $itemId Canonical Item affected by this fact.
     * @param string $assetId Asset whose bytes or history are identified.
     * @param string $assetVersion Exact historical OCFL Asset version, never a latest selector.
     */
    public function __construct(
        public string $itemId,
        public string $checkId,
        public string $assetId,
        public string $assetVersion,
        public string $expectedDigest,
        public string $observedDigest,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
