<?php

declare(strict_types=1);

namespace App\Stashes;

final readonly class StashCollectionEntry
{
    public function __construct(
        public string $stashName,
        public string $broadcastKey,
        public string $broadcastName,
        public string $publicUrl,
    ) {}
}
