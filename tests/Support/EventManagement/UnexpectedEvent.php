<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Catalogue;

use App\EventManagement\DTO\EventMetadata;

/**
 * Provides an intentionally undocumented event class for audit regression tests.
 * It is kept outside the production event tree and is never dispatched.
 */
final readonly class UnexpectedEvent
{
    /**
     * Supplies the required event metadata shape for the audit fixture.
     * The fixture exists only to verify unexpected-class detection.
     */
    public function __construct(
        public string $itemId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
