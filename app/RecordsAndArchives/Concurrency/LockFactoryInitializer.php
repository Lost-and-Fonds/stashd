<?php

namespace App\RecordsAndArchives\Concurrency;

use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Tempest\Container\Container;
use Tempest\Container\Initializer;

class LockFactoryInitializer implements Initializer
{
    public function initialize(Container $container): LockFactory
    {
        return new LockFactory(
            new FlockStore(),
        );
    }
}
