<?php

declare(strict_types=1);

namespace App\Downloads;

final readonly class DownloadExecutionResult
{
    /** @param list<string> $warnings */
    public function __construct(
        public string $mediaItemId,
        public string $stashId,
        public bool $skipped,
        public int $assetsReady,
        public array $warnings = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'media_item_id' => $this->mediaItemId,
            'stash_id' => $this->stashId,
            'skipped' => $this->skipped,
            'assets_ready' => $this->assetsReady,
            'warnings' => $this->warnings,
        ];
    }
}
