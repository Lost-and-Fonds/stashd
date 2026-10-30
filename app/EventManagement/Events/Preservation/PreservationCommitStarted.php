<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Preservation;

use App\EventManagement\Attributes\DurableHistory;
use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Preservation Commit started.
 * The Vault is authoritative; database projections and independently versioned Asset and Item histories remain separate.
 */
#[DurableHistory]
final readonly class PreservationCommitStarted
{
    /**
     * Records that the Preservation Commit started.
     *
     * @param string $transactionId Preservation transaction responsible for the archival operation.
     */
    public function __construct(
        public string $transactionId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
