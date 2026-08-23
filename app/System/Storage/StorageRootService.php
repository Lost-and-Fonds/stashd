<?php

declare(strict_types=1);

namespace App\System\Storage;

use App\Config\StashdConfig;
use RuntimeException;
use Tempest\Support\Filesystem;
use Tempest\Support\Filesystem\Exceptions\RuntimeException as FilesystemException;

final readonly class StorageRootService
{
    public function __construct(
        private StashdConfig $config,
    ) {}

    /** @return list<string> */
    public function ensureDirectories(): array
    {
        $created = [];

        foreach ($this->requiredPaths() as $path) {
            if (Filesystem\is_directory($path)) {
                continue;
            }

            try {
                Filesystem\create_directory($path, 0o775);
            } catch (FilesystemException) {
                throw new RuntimeException("Stashd cannot create directory: {$path}");
            }

            $created[] = $path;
        }

        return $created;
    }

    /** @return list<string> */
    private function requiredPaths(): array
    {
        return [
            $this->config->dataPath,
            $this->config->backupsPath(),
            $this->config->vaultPath(),
            $this->config->broadcastsPath(),
            $this->config->tempPath(),
            $this->config->cachePath(),
            rtrim($this->config->tempPath(), '/') . '/downloads',
        ];
    }
}
