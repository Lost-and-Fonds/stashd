<?php

declare(strict_types=1);

namespace App\Broadcasts;

final readonly class BroadcastDeleteResult
{
    public function __construct(
        public int $removedCount,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'deleted' => true,
            'removed_count' => $this->removedCount,
        ];
    }
}
