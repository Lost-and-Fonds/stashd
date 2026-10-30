<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Publication;

use App\EventManagement\DTO\EventMetadata;

/**
 * This records a complete aggregate publication unit, not an individual Item result.
 * Published results remain in history when another Item or a later republish fails; selection changes do not prune outputs.
 */
final readonly class AggregatePublicationCommitted
{
    /**
     * This records a complete aggregate publication unit, not an individual Item result.
     *
     * @param string $publicationId Publication operation whose results remain separately identifiable.
     * @param string $invocationId Plugin invocation responsible for this observation.
     */
    public function __construct(
        public string $publicationId,
        public string $broadcastId,
        public string $aggregateFingerprint,
        public string $invocationId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
