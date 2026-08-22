<?php

declare(strict_types=1);

namespace App\Stashes;

final readonly class StashInputCommitResult
{
    public function __construct(
        public string $stashId,
        public string $stashInputId,
        public int $mediaItemsCreated,
        public int $mediaItemsReused,
        public int $stashItemsCreated,
        public int $stashItemsReused,
        public string $preflightCommandId,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'stash_id' => $this->stashId,
            'stash_input_id' => $this->stashInputId,
            'preflight_command_id' => $this->preflightCommandId,
            'media_items_created' => $this->mediaItemsCreated,
            'media_items_reused' => $this->mediaItemsReused,
            'stash_items_created' => $this->stashItemsCreated,
            'stash_items_reused' => $this->stashItemsReused,
        ];
    }
}
