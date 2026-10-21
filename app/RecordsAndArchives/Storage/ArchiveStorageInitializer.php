<?php

namespace App\RecordsAndArchives\Storage;

use App\RecordsAndArchives\Storage\Providers\LocalArchiveStorage;
use Tempest\Container\Container;
use Tempest\Container\Initializer;
use Tempest\Container\Singleton;
use Tempest\Storage\Config\LocalStorageConfig;
use Tempest\Storage\Storage;

final class ArchiveStorageInitializer implements Initializer
{
    #[Singleton(tag: 'vault')]
    public function initialize(Container $container): ArchiveStorage
    {
        return new LocalArchiveStorage(
            storage: $container->get(Storage::class, 'vault'),
            config: $container->get(LocalStorageConfig::class, 'vault'),
        );
    }
}
