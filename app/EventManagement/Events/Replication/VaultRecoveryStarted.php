<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Replication;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Vault recovery started.
 * Replicas copy the authoritative Vault and never become competing preservation authorities.
 */
final readonly class VaultRecoveryStarted
{
    /**
     * Records that the Vault recovery started.
     */
    public function __construct(
        public string $recoveryId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
