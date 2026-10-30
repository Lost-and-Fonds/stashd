<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Security;

use App\EventManagement\Attributes\DurableHistory;
use App\EventManagement\DTO\EventMetadata;

/**
 * Records an update to a credential reference or scope. It never contains the credential value.
 * Its typed IDs identify the subject and the operation that established this fact.
 */
#[DurableHistory]
final readonly class CredentialUpdated
{
    /**
     * Captures the established fact and its operation-specific references.
     * Callers must dispatch only after the stated occurrence condition is true.
     */
    public function __construct(
        public string $credentialId,
        public string $scopeReference,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
