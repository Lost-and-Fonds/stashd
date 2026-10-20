<?php

namespace Tests\Support\RecordsAndArchives;

/** Inject a failing physical storage write, while keeping the real filesystem and OCFL engine. */
readonly class CorruptingAssetStorage extends \App\RecordsAndArchives\Storage\Providers\LocalArchiveStorage
{
    public function writeStream(string $location, mixed $contents): static
    {
        parent::writeStream($location, $contents);
        if (str_contains($location, '/content/')) {
            parent::write($location, 'corrupted during storage copy');
        }

        return $this;
    }
}
