<?php

declare(strict_types=1);

namespace App\Stashes;

/** What a single pass of DiscoveredItemCommitter actually persisted. */
final readonly class DiscoveredItemCommitCounts
{
    /** @param list<string> $downloadableMediaItemIds */
    public function __construct(
        public int $mediaItemsCreated = 0,
        public int $mediaItemsReused = 0,
        public int $stashItemsCreated = 0,
        public int $stashItemsReused = 0,
        public array $downloadableMediaItemIds = [],
    ) {}
}
