<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Catalogue;

use App\EventManagement\DTO\EventMetadata;
use DateTimeImmutable;

/**
 * Records that the Item was observed at a source.
 * Stash membership and display changes do not delete or rewrite canonical preserved bytes.
 */
final readonly class ItemObservedAtSource
{
    /**
     * Records that the Item was observed at a source.
     *
     * @param string $itemId Canonical Item affected by this fact.
     * @param string $inputId Input through which the source relationship is known.
     */
    public function __construct(
        public string $itemId,
        public string $inputId,
        public DateTimeImmutable $observedAt,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
