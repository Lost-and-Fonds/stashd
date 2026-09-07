<?php

declare(strict_types=1);

namespace App\Stashes;

final readonly class StashInputSyncResult
{
    public function __construct(
        public string $stashId,
        public string $stashInputId,
        public int $itemsDiscovered,
        public int $itemsCreated,
        public int $stashItemsCreated,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'stash_id' => $this->stashId,
            'stash_input_id' => $this->stashInputId,
            'items_discovered' => $this->itemsDiscovered,
            'items_created' => $this->itemsCreated,
            'stash_items_created' => $this->stashItemsCreated,
        ];
    }
}
