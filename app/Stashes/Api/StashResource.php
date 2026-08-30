<?php

declare(strict_types=1);

namespace App\Stashes\Api;

use App\Http\Api\ApiJson;
use App\Stashes\StashRecord;

final readonly class StashResource
{
    /** @param array<string, mixed> $summary */
    public function __construct(private StashRecord $stash, private array $summary = []) {}

    /** @param array<string, mixed> $summary */
    public static function fromRecord(StashRecord $stash, array $summary = []): self
    {
        return new self($stash, $summary);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ApiJson::encode([
            'id' => (string) $this->stash->id,
            'name' => $this->stash->name,
            'description' => $this->stash->description,
            'syncMode' => $this->stash->syncMode->value,
            'downloadPolicy' => $this->stash->downloadPolicy->value,
            'organizationMode' => $this->stash->organizationMode->value,
            'state' => $this->stash->state->value,
            'iconUri' => $this->stash->iconUri,
            'createdAt' => $this->stash->createdAt,
            'updatedAt' => $this->stash->updatedAt,
            ...$this->summary,
        ]);
    }
}
