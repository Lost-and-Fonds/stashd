<?php

declare(strict_types=1);

namespace App\Vault;

use App\Config\StashdConfig;
use App\Support\PrefixedUlid;
use RuntimeException;

use function Tempest\Support\Filesystem\create_directory;

use Tempest\Support\Filesystem\Exceptions\RuntimeException as FilesystemException;

final readonly class StageDownloadFiles
{
    public function __construct(
        private StashdConfig $config,
    ) {}

    public function createWorkDirectory(PrefixedUlid $jobId): string
    {
        $path = rtrim($this->config->tempPath(), '/') . '/downloads/' . $jobId->toString();

        if (is_dir($path)) {
            $this->removeDirectory($path);
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
        if (is_dir($path)) {
            $this->removeDirectory($path);
        }
    }

    public function markFailed(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $marker = rtrim($path, '/') . '/.failed';
        file_put_contents($marker, gmdate('c'));
    }

    private function removeDirectory(string $path): void
    {
        $entries = scandir($path);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $path . '/' . $entry;

            if (is_dir($full)) {
                $this->removeDirectory($full);
            } else {
                unlink($full);
            }
        }

        rmdir($path);
    }
}
