<?php

declare(strict_types=1);

namespace App\Broadcasts;

final readonly class BroadcastPruneResult
{
    /**
     * @param  list<string>  $removedPaths
     */
    public function __construct(
        public int $removedCount,
        public array $removedPaths,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'removed_count' => $this->removedCount,
            'removed_paths' => $this->removedPaths,
        ];
    }
}
