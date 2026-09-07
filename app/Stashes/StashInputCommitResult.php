<?php

declare(strict_types=1);

namespace App\Stashes;

final readonly class StashInputCommitResult
{
    public function __construct(
        public string $stashId,
        public string $stashInputId,
        public int $itemsCreated,
        public int $itemsReused,
        public int $stashItemsCreated,
        public int $stashItemsReused,
        /** @var list<string> */
        public array $downloadableItemIds = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'stash_id' => $this->stashId,
            'stash_input_id' => $this->stashInputId,
            'items_created' => $this->itemsCreated,
            'items_reused' => $this->itemsReused,
            'stash_items_created' => $this->stashItemsCreated,
            'stash_items_reused' => $this->stashItemsReused,
        ];
    }
}
