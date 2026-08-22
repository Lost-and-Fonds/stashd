<?php

declare(strict_types=1);

namespace App\Broadcasts;

use App\Stashes\StashInputRecord;
use App\Stashes\StashItemRecord;
use App\Stashes\StashRecord;
use App\Vault\AssetRecord;
use App\Vault\MediaItemRecord;

/** Runtime context for broadcast lifecycle operations. */
final readonly class BroadcastContext
{
    /**
     * @param  list<StashItemRecord>  $stashItems
     * @param  array<string, MediaItemRecord>  $mediaItems  keyed by media item id
     * @param  array<string, AssetRecord|null>  $vaultOriginals  keyed by media item id
     * @param  list<StashInputRecord>  $stashInputs
     */
    public function __construct(
        public BroadcastRecord $broadcast,
        public StashRecord $stash,
        public array $stashItems,
        public array $mediaItems,
        public array $vaultOriginals,
        public array $stashInputs = [],
    ) {}

    /** @return array<string, mixed> */
    public function settings(): array
    {
        return $this->broadcast->settings ?? [];
    }
}
