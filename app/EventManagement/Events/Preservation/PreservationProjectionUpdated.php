<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Preservation;

use App\EventManagement\Attributes\DurableHistory;
use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the database projection was updated. It is separate from the authoritative OCFL commit.
 * The Vault is authoritative; database projections and independently versioned Asset and Item histories remain separate.
 */
#[DurableHistory]
final readonly class PreservationProjectionUpdated
{
    /**
     * Records that the database projection was updated. It is separate from the authoritative OCFL commit.
     *
     * @param string $transactionId Preservation transaction responsible for the archival operation.
     */
    public function __construct(
        public string $transactionId,
        public string $projectionRevisionId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
