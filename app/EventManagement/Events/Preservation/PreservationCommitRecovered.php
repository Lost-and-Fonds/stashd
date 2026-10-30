<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Preservation;

use App\EventManagement\Attributes\DurableHistory;
use App\EventManagement\DTO\EventMetadata;

/**
 * Records recovery of the identified preservation transaction from established archival evidence.
 * The Vault is authoritative; database projections and independently versioned Asset and Item histories remain separate.
 */
#[DurableHistory]
final readonly class PreservationCommitRecovered
{
    /**
     * Records recovery of the identified preservation transaction from established archival evidence.
     *
     * @param string $transactionId Preservation transaction responsible for the archival operation.
     */
    public function __construct(
        public string $transactionId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
