<?php

declare(strict_types=1);

namespace App\Integration\DTO;

final readonly class AcquiredFile
{
    public function __construct(
        public string $reference,
        public int $size_bytes,
        public string $digest,
        public string $relative_path,
        public string $root,
        public string $outcome = 'complete',
    ) {}
}
