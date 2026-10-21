<?php

namespace App\RecordsAndArchives\Concurrency;

use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Tempest\Container\Container;
use Tempest\Container\Initializer;

/**
 * Supply the container with the local flock-based Symfony lock factory.
 */
class LockFactoryInitializer implements Initializer
{
    /**
     * Create a factory backed by local lock files rather than a distributed store.
     */
    public function initialize(Container $container): LockFactory
    {
        return new LockFactory(
            new FlockStore(),
        );
    }
}
