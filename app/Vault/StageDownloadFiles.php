<?php

declare(strict_types=1);

namespace App\Vault;

use App\Config\StashdConfig;
use App\Support\PrefixedUlid;
use RuntimeException;
use Tempest\Support\Filesystem;
use Tempest\Support\Filesystem\Exceptions\RuntimeException as FilesystemException;

final readonly class StageDownloadFiles
{
    public function __construct(
        private StashdConfig $config,
    ) {}

    public function createWorkDirectory(PrefixedUlid $jobId): string
    {
        $path = rtrim($this->config->tempPath(), '/') . '/downloads/' . $jobId->toString();

        if (Filesystem\is_directory($path)) {
            Filesystem\delete_directory($path);
        }

        try {
            Filesystem\create_directory($path, 0o775);
        } catch (FilesystemException) {
            throw new RuntimeException("Unable to create temp download directory: {$path}");
        }

        return $path;
    }

    public function cleanupSuccess(string $path): void
    {
        if (Filesystem\is_directory($path)) {
            Filesystem\delete_directory($path);
        }
    }

    public function markFailed(string $path): void
    {
        if (! Filesystem\is_directory($path)) {
            return;
        }

        $marker = rtrim($path, '/') . '/.failed';
        Filesystem\write_file($marker, gmdate('c'));
    }
}
