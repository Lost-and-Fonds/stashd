<?php

declare(strict_types=1);

namespace App\Broadcasts;

final readonly class BroadcastVerifyResult
{
    /**
     * @param  list<string>  $validItemIds
     * @param  list<string>  $staleItemIds
     * @param  list<string>  $missingItemIds
     * @param  list<string>  $invalidLinkItemIds
     */
    public function __construct(
        public bool $ok,
        public int $validCount,
        public int $staleCount,
        public array $validItemIds,
        public array $staleItemIds,
        public array $missingItemIds,
        public array $invalidLinkItemIds = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'valid_count' => $this->validCount,
            'stale_count' => $this->staleCount,
            'valid_item_ids' => $this->validItemIds,
            'stale_item_ids' => $this->staleItemIds,
            'missing_item_ids' => $this->missingItemIds,
            'invalid_link_item_ids' => $this->invalidLinkItemIds,
        ];
    }
}
