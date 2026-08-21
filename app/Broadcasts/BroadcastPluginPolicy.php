<?php

declare(strict_types=1);

namespace App\Broadcasts;

use App\Stashes\DownloadPolicy;

/** Optional generic policy hooks for formats with derived representations. */
interface BroadcastPluginPolicy
{
    public function acceptsDownloadPolicy(BroadcastRecord $broadcast, DownloadPolicy $policy): bool;

    public function derivedWorkCount(BroadcastContext $context): int;

    public function prunesAfterPublish(): bool;
}
