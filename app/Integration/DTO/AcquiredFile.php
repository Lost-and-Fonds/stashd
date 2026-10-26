<?php

declare(strict_types=1);

namespace App\Integration\DTO;

/**
 * Carry accepted staged-byte evidence and Filesystem provenance before preservation.
 *
 * The opaque reference is invocation-scoped, not a path or canonical Asset ID.
 * Possessing this value alone does not establish archival completion.
 */
final readonly class AcquiredFile
{
    /**
     * Retain the host-computed size and SHA-512 together with the observed source path.
     *
     * AcquisitionStaging checks receipt ownership before resolving the private file.
     */
    public function __construct(
        public string $reference,
        public int $size_bytes,
        public string $digest,
        public string $relative_path,
        public string $root,
        public string $outcome = 'complete',
    ) {}
}
