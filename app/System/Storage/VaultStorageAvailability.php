<?php

declare(strict_types=1);

namespace App\System\Storage;

use App\Config\StashdConfig;
use Tempest\Support\Filesystem;

final readonly class VaultStorageAvailability
{
    public function __construct(
        private StorageLocationRepository $locations,
        private StashdConfig $config,
        private FilesystemProbe $filesystem,
    ) {}

    public function isUnavailable(): bool
    {
        $vault = $this->locations->findByKey(StorageLocationKey::Vault);

        if ($vault !== null && in_array($vault->state, [StorageLocationState::Unavailable, StorageLocationState::Missing], true)) {
            return true;
        }

        $path = $this->config->vaultPath();

        if (! Filesystem\is_directory($path) || ! Filesystem\is_readable($path)) {
            return true;
        }

        return $vault?->filesystemId !== null
            && ($current = $this->filesystem->filesystemId($path)) !== null
            && $current !== $vault->filesystemId;
    }
}
