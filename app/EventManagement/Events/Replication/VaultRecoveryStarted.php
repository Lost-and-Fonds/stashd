<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Replication;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the Vault recovery started.
 * The typed identifiers distinguish this occurrence from other operations on the same subject.
 */
final readonly class VaultRecoveryStarted
{
    /**
     * Captures the established fact and its operation-specific references.
     * Callers must dispatch only after the stated occurrence condition is true.
     */
    public function __construct(
        public string $recoveryId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
