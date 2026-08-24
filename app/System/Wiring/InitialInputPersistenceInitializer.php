<?php

declare(strict_types=1);

namespace App\System\Wiring;

use App\Stashes\CreateStashFromDiscovery;
use App\Stashes\InitialInputPersistence;
use Tempest\Container\Container;
use Tempest\Container\Initializer;

final class InitialInputPersistenceInitializer implements Initializer
{
    public function initialize(Container $container): InitialInputPersistence
    {
        return $container->get(CreateStashFromDiscovery::class);
    }
}
