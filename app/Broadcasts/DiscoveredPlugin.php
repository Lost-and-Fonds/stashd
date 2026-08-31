<?php

declare(strict_types=1);

namespace App\Broadcasts;

/**
 * Wrapper DTO for a discovered broadcast plugin.
 *
 * Holds the plugin instance alongside its metadata from the #[StashdBroadcast]
 * attribute, plus a deduplicated list of all broadcast keys.
 */
final readonly class DiscoveredPlugin
{
    /** @param list<string> $broadcastKeys */
    public function __construct(
        public string $className,
        public string $name,
        public string $description,
        public array $broadcastKeys,
        public BroadcastPlugin $plugin,
    ) {}
}
