<?php

declare(strict_types=1);

namespace App\EventManagement\DTO;

/**
 * Contains the deliberately small, safe representation allowed for client delivery.
 * It is a transient notification projection and never replaces event history.
 */
final readonly class ClientEvent
{
    /**
     * Creates an allowlisted client projection for a single event.
     * The fields must already have been selected by the event's ClientVisible attribute.
     *
     * @param array<string, scalar> $fields
     */
    public function __construct(
        public string $topic,
        public string $eventId,
        public string $occurredAt,
        public array $fields,
    ) {}
}
