<?php

declare(strict_types=1);

namespace App\Downloads;

final readonly class AssetAcquisitionResult
{
    /** @param list<DownloadedFile> $files
     * @param list<UnavailableAsset> $unavailable
     */
    public function __construct(
        public array $files = [],
        public array $unavailable = [],
    ) {}
}
