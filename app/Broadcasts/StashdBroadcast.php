<?php

declare(strict_types=1);

namespace App\Broadcasts;

/**
 * Attribute marking a class as a broadcast plugin implementation.
 *
 * Applied to classes that implement BroadcastPlugin to register them
 * with the broadcast plugin discovery system.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class StashdBroadcast
{
    public function __construct(
        /** Human-readable name for this broadcast plugin. */
        public string $name,
        /** Short description of what this plugin does. */
        public string $description = '',
    ) {}
}
