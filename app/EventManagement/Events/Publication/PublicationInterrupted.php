<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Publication;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records that the publication invocation stopped before its completion condition was established.
 * Its typed IDs identify the subject and the operation that established this fact.
 */
final readonly class PublicationInterrupted
{
    /**
     * Captures the established fact and its operation-specific references.
     * Callers must dispatch only after the stated occurrence condition is true.
     */
    public function __construct(
        public string $publicationId,
        public string $broadcastId,
        public string $inputFingerprint,
        public string $invocationId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
