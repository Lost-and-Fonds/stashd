<?php

declare(strict_types=1);

namespace App\Broadcasts;

final readonly class BroadcastPublishResult
{
    /**
     * @param  list<string>  $publishedPaths
     * @param  list<string>  $failedStashItemIds
     */
    public function __construct(
        public int $publishedCount,
        public int $skippedCount,
        public array $publishedPaths,
        public array $failedStashItemIds = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'published_count' => $this->publishedCount,
            'skipped_count' => $this->skippedCount,
            'published_paths' => $this->publishedPaths,
            'failed_stash_item_ids' => $this->failedStashItemIds,
        ];
    }
}
