<?php

declare(strict_types=1);

namespace App\EventManagement\DTO;

use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\Uid\Uuid;

/**
 * Carries the immutable identity, occurrence time and safe causal context of an event.
 * Its timestamp describes when the fact became true, not when it was later stored.
 */
final readonly class EventMetadata
{
    public string $eventId;

    public DateTimeImmutable $occurredAt;

    /**
     * Creates event metadata, preserving explicit identity and normalizing time to UTC.
     * An omitted identity is generated for a newly occurring fact.
     */
    public function __construct(
        ?string $eventId = null,
        ?DateTimeImmutable $occurredAt = null,
        public ?string $correlationId = null,
        public ?string $causationId = null,
        public ?string $actorType = null,
        public ?string $actorId = null,
    ) {
        $this->eventId = $eventId ?? Uuid::v7()->toRfc4122();
        $this->occurredAt = ($occurredAt ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
    }
}
