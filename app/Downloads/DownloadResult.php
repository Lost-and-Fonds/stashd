<?php

declare(strict_types=1);

namespace App\Downloads;

use App\Providers\StashdUri;
use Tempest\DateTime\DateTime;

final readonly class DownloadResult
{
    /**
     * @param  list<DownloadedFile>  $files
     * @param  array<string, mixed>  $provenance
     */
    public function __construct(
        public array $files,
        public string $implementation,
        public ?string $implementationVersion,
        public StashdUri $sourceUri,
        public DateTime $attemptedAt,
        public array $provenance = [],
    ) {}
}
