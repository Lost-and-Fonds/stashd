<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Security;

use App\EventManagement\Attributes\DurableHistory;
use App\EventManagement\DTO\EventMetadata;

/**
 * Records an update to a credential reference or scope. It never contains the credential value.
 * Only safe identifying references belong here; credential values and authentication headers must never be included.
 */
#[DurableHistory]
final readonly class CredentialUpdated
{
    /**
     * Records an update to a credential reference or scope. It never contains the credential value.
     *
     * @param string $credentialId Safe credential identifier; never the credential value.
     */
    public function __construct(
        public string $credentialId,
        public string $scopeReference,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
