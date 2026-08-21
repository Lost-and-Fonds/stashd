<?php

declare(strict_types=1);

namespace App\Timeline;

use App\Vault\MediaItemId;

final readonly class TimelineMetadataRenderer
{
    public function __construct(private TimelineEntryRepository $entries)
    {
    }

    public function render(MediaItemId $mediaItemId): ?string
    {
        $entries = $this->entries->listForMediaItem($mediaItemId);

        if ($entries === []) {
            return null;
        }

        $metadata = ';FFMETADATA1' . "\n";

        foreach ($entries as $entry) {
            $metadata .= "[CHAPTER]\nTIMEBASE=1/1000\nSTART=" . (int) round($entry->startSeconds * 1000) . "\nEND=" . (int) round($entry->endSeconds * 1000) . "\ntitle=" . $this->escape($entry->title ?? $entry->category->value) . "\n";
        }

        return $metadata;
    }

    public function hasEntries(MediaItemId $mediaItemId): bool
    {
        return $this->entries->listForMediaItem($mediaItemId) !== [];
    }

    private function escape(string $value): string
    {
        return str_replace(["\\", "\n", "\r", '=', ';', '#'], ['\\\\', '\\n', '', '\\=', '\\;', '\\#'], $value);
    }
}
