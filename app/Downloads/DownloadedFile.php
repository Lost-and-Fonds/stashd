<?php

declare(strict_types=1);

namespace App\Downloads;

use App\Vault\AssetKind;
use App\Vault\AssetRole;

final readonly class DownloadedFile
{
    public function __construct(
        public string $tempPath,
        public string $filename,
        public AssetRole $role,
        public AssetKind $kind,
        public ?string $mimeType = null,
        public ?string $container = null,
        public ?int $sizeBytes = null,
        public ?int $durationSeconds = null,
    ) {}
}
