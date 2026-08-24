<?php

declare(strict_types=1);

namespace App\Vault\Api;

use App\Vault\VaultItemSummary;

final readonly class VaultItemSummaryResource
{
    public function __construct(private VaultItemSummary $summary) {}

    public static function fromRecord(VaultItemSummary $summary): self
    {
        return new self($summary);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            ...MediaItemResource::fromRecord($this->summary->item)->toArray(),
            'kind' => $this->summary->kind,
            'stashCount' => $this->summary->stashCount,
            'broadcastCount' => $this->summary->broadcastCount,
            'preservedSizeBytes' => $this->summary->preservedSizeBytes,
        ];
    }
}
