<?php

declare(strict_types=1);

namespace App\EventManagement\Events\Catalogue;

use App\EventManagement\DTO\EventMetadata;

/**
 * The caller must establish durable discovery acceptance before publishing this fact.
 * Stash membership and display changes do not delete or rewrite canonical preserved bytes.
 */
final readonly class ItemDiscovered
{
    /**
     * The caller must establish durable discovery acceptance before publishing this fact.
     *
     * @param string $itemId Canonical Item affected by this fact.
     * @param string $inputId Input through which the source relationship is known.
     */
    public function __construct(
        public string $itemId,
        public string $inputId,
        public string $discoveryRunId,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
