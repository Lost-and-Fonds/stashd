<?php

declare(strict_types=1);

namespace App\Downloads;

final readonly class DownloadExecutionResult
{
    /** @param list<string> $warnings */
    public function __construct(
        public string $itemId,
        public string $stashId,
        public bool $skipped,
        public int $assetsReady,
        public array $warnings = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'item_id' => $this->itemId,
            'stash_id' => $this->stashId,
            'skipped' => $this->skipped,
            'assets_ready' => $this->assetsReady,
            'warnings' => $this->warnings,
        ];
    }
}
