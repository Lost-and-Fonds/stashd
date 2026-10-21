<?php

namespace Tests\Support\RecordsAndArchives;

/**
 * Corrupt copied content after real local I/O to exercise adopted-byte verification.
 *
 * Retains the real filesystem and OCFL engine so failed verification must come
 * from rereading changed bytes rather than an internal service mock.
 */
readonly class CorruptingAssetStorage extends \App\RecordsAndArchives\Storage\Providers\LocalArchiveStorage
{
    /**
     * Write the real stream, then replace OCFL content bytes with a corrupt payload.
     *
     * Only content paths are altered; the caller retains ownership of the input
     * stream. This deliberately leaves bad bytes for the publication checks.
     */
    public function writeStream(string $location, mixed $contents): static
    {
        parent::writeStream($location, $contents);
        if (str_contains($location, '/content/')) {
            parent::write($location, 'corrupted during storage copy');
        }

        return $this;
    }
}
