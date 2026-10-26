<?php

declare(strict_types=1);

namespace App\Integration\DTO;

use InvalidArgumentException;

/**
 * Describe one destination-relative output without exposing a host filesystem path.
 */
final readonly class PublishedFile
{
    /**
     * Retain optional correlation identities and reject unsafe relative names.
     */
    public function __construct(
        public string $relativePath,
        public ?string $itemId = null,
        public ?string $assetId = null,
    ) {
        if (
            $relativePath === ''
            || str_contains($relativePath, "\0")
            || str_contains($relativePath, '\\')
            || str_starts_with($relativePath, '/')
            || preg_match('/^[A-Za-z]:/', $relativePath) === 1
        ) {
            throw new InvalidArgumentException('Publication paths must be relative names.');
        }

        foreach (explode('/', $relativePath) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new InvalidArgumentException('Publication paths must not contain traversal or empty components.');
            }
        }
    }
}
