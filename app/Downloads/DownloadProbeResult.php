<?php

declare(strict_types=1);

namespace App\Downloads;

final readonly class DownloadProbeResult
{
    public function __construct(
        public bool $available,
        public string $implementation,
        public ?string $implementationVersion = null,
        public ?string $message = null,
    ) {}
}
