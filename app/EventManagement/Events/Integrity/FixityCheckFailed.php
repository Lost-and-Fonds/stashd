<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Integrity;

use App\EventManagement\Attributes\DurableHistory;
use App\EventManagement\Attributes\PremisSignificant;
use App\EventManagement\DTO\EventMetadata;

/**
 * A failed check records inability to complete verification and does not prove byte corruption.
 * Verification evidence applies to the identified scope; inability to verify does not establish damage to unrelated Items.
 */
#[DurableHistory]
#[PremisSignificant]
final readonly class FixityCheckFailed
{
    /**
     * A failed check records inability to complete verification and does not prove byte corruption.
     *
     * @param string $itemId Canonical Item affected by this fact.
     * @param string $assetId Asset whose bytes or history are identified.
     * @param string $assetVersion Exact historical OCFL Asset version, never a latest selector.
     * @param string $reasonCode Safe machine-readable explanation, without process output or secrets.
     */
    public function __construct(
        public string $itemId,
        public string $assetId,
        public string $assetVersion,
        public string $checkId,
        public string $reasonCode,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
