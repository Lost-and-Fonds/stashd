<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Publication;

use App\EventManagement\DTO\EventMetadata;
use App\EventManagement\DTO\PublicationOutcome;

/**
 * This records one Item's durable publication outcome; it is not an aggregate publication commit.
 * The typed identifiers distinguish this occurrence from other operations on the same subject.
 */
final readonly class ItemPublicationAcknowledged
{
    /**
     * Captures the established fact and its operation-specific references.
     * Callers must dispatch only after the stated occurrence condition is true.
     */
    public function __construct(
        public string $publicationId,
        public string $broadcastId,
        public string $itemId,
        public PublicationOutcome $outcome,
        public string $inputFingerprint,
        public string $invocationId,
        public ?string $reasonCode,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
