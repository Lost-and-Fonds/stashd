<?php

declare(strict_types=1);

namespace App\Vault;

use App\Config\StashdConfig;
use App\Support\PrefixedUlid;
use RuntimeException;

use function Tempest\Support\Filesystem\create_directory;
use function Tempest\Support\Filesystem\delete_directory;

use Tempest\Support\Filesystem\Exceptions\RuntimeException as FilesystemException;

use function Tempest\Support\Filesystem\is_directory;
use function Tempest\Support\Filesystem\write_file;

final readonly class StageDownloadFiles
{
    public function __construct(
        private StashdConfig $config,
    ) {}

    public function createWorkDirectory(PrefixedUlid $jobId): string
    {
        $path = rtrim($this->config->tempPath(), '/') . '/downloads/' . $jobId->toString();

        if (is_directory($path)) {
            delete_directory($path);
        }

        try {
            create_directory($path, 0o775);
        } catch (FilesystemException) {
            throw new RuntimeException("Unable to create temp download directory: {$path}");
        }

        return $path;
    }

    public function cleanupSuccess(string $path): void
    {
        if (is_directory($path)) {
            delete_directory($path);
        }
    }

    public function markFailed(string $path): void
    {
        if (! is_directory($path)) {
            return;
        }

        $marker = rtrim($path, '/') . '/.failed';
        write_file($marker, gmdate('c'));
    }
}
