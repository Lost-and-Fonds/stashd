<?php

declare(strict_types=1);

namespace Tests\Support\EventManagement;

use App\EventManagement\Attributes\ClientVisible;
use App\EventManagement\DTO\EventMetadata;

/**
 * Exercises the client projection allowlist without declaring a production event public.
 * Its privateConfig property must never enter the projected client fields.
 */
#[ClientVisible(topic: 'test.item', fields: ['itemId'])]
final readonly class ClientVisibleEvent
{
    /**
     * Creates a projection fixture with one approved and one private property.
     */
    public function __construct(
        public string $itemId,
        public string $privateConfig,
        public EventMetadata $metadata = new EventMetadata(),
    ) {}
}
