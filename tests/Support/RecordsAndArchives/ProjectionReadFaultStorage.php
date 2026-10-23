<?php

namespace Tests\Support\RecordsAndArchives;

use App\RecordsAndArchives\Storage\Providers\LocalArchiveStorage;
use RuntimeException;
use Tempest\Storage\Config\LocalStorageConfig;
use Tempest\Storage\Storage;

/**
 * Simulate unavailable inventory reads while retaining real filesystem listings and bytes.
 */
final readonly class ProjectionReadFaultStorage extends LocalArchiveStorage
{
    /**
     * Limit the external storage fault to inventories beneath one disposable Object root.
     */
    public function __construct(
        Storage $storage,
        LocalStorageConfig $config,
        private string $objectPath,
        private string $filename = 'inventory.json',
    ) {
        parent::__construct($storage, $config);
    }

    /**
     * Fail selected provider reads without changing files or hiding directory entries.
     */
    public function read(string $location): string
    {
        if (str_starts_with($location, $this->objectPath . '/') && str_ends_with($location, '/' . $this->filename)) {
            throw new RuntimeException('Injected inventory storage read failure.');
        }

        return parent::read($location);
    }
}
