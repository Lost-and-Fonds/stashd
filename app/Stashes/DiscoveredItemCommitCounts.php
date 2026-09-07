<?php

declare(strict_types=1);

namespace App\Stashes;

/** What a single pass of DiscoveredItemCommitter actually persisted. */
final readonly class DiscoveredItemCommitCounts
{
    /** @param list<string> $downloadableItemIds */
    public function __construct(
        public int $itemsCreated = 0,
        public int $itemsReused = 0,
        public int $stashItemsCreated = 0,
        public int $stashItemsReused = 0,
        public array $downloadableItemIds = [],
    ) {}
}
