<?php

declare(strict_types=1);

namespace App\Broadcasts;

use App\Stashes\StashInputRecord;
use App\Stashes\StashItemRecord;
use App\Stashes\StashRecord;
use App\Vault\AssetRecord;
use App\Vault\ItemRecord;

/** Runtime context for broadcast lifecycle operations. */
final readonly class BroadcastContext
{
    /**
     * @param  list<StashItemRecord>  $stashItems
     * @param  array<string, ItemRecord>  $items  keyed by item id
     * @param  array<string, AssetRecord|null>  $vaultOriginals  keyed by item id
     * @param  list<StashInputRecord>  $stashInputs
     */
    public function __construct(
        public BroadcastRecord $broadcast,
        public StashRecord $stash,
        public array $stashItems,
        public array $items,
        public array $vaultOriginals,
        public array $stashInputs = [],
        public mixed $progress = null,
    ) {}

    /** @return array<string, mixed> */
    public function settings(): array
    {
        return $this->broadcast->settings ?? [];
    }
}
