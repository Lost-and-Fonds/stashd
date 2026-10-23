<?php

namespace App\Integration;

use Tempest\Container\Container;
use Tempest\Container\Initializer;
use Tempest\Container\Singleton;

/**
 * Register the first-party Filesystem implementation of Core's discovery port.
 */
final class InputDiscoveryInitializer implements Initializer
{
    /**
     * Resolve the in-process adapter without a plugin package or external runtime.
     */
    #[Singleton]
    public function initialize(Container $container): InputDiscovery
    {
        return $container->get(FilesystemDiscovery::class);
    }
}
