<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Publication;

use App\EventManagement\DTO\EventMetadata;
use App\EventManagement\DTO\PublicationOutcome;

/**
 * This records one Item's durable publication outcome; it is not an aggregate publication commit.
 * Published results remain in history when another Item or a later republish fails; selection changes do not prune outputs.
 */
final readonly class ItemPublicationAcknowledged
{
    /**
     * This records one Item's durable publication outcome; it is not an aggregate publication commit.
     *
     * @param string $publicationId Publication operation whose results remain separately identifiable.
     * @param string $itemId Canonical Item affected by this fact.
     * @param string $inputFingerprint Fingerprint of the exact publication input selection.
     * @param string $invocationId Plugin invocation responsible for this observation.
     * @param ?string $reasonCode Safe machine-readable explanation, without process output or secrets.
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
