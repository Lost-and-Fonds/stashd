<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Publication;

use App\EventManagement\DTO\EventMetadata;

/**
 * Records completion of the identified publication invocation for its pinned input fingerprint.
 * Published results remain in history when another Item or a later republish fails; selection changes do not prune outputs.
 */
final readonly class PublicationCompleted
{
    /**
     * Records completion of the identified publication invocation for its pinned input fingerprint.
     *
     * @param string $publicationId Publication operation whose results remain separately identifiable.
     * @param string $inputFingerprint Fingerprint of the exact publication input selection.
     * @param string $invocationId Plugin invocation responsible for this observation.
     */
    public function __construct(
        public string $publicationId,
        public string $broadcastId,
        public string $inputFingerprint,
        public string $invocationId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
