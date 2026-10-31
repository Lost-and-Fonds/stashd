<?php

declare(strict_types=1);

namespace Evaluation\Official;

use Ecotone\Api\Tempest\DbalBackedConnection;
use Tempest\Container\Container;
use Tempest\Container\Initializer;
use Tempest\Container\Singleton;
use Tempest\Database\Config\DatabaseConfig;
use Tempest\Database\Connection\Connection;

/**
 * Opt into the proposed upstream connection through Tempest PR 2326 injection.
 * The unmodified official profile excludes this initializer through discovery.
 */
final readonly class InjectedConnectionInitializer implements Initializer
{
    /**
     * Let both frameworks resolve the library's one transaction owner from normal DI.
     */
    #[Singleton]
    public function initialize(Container $container): Connection
    {
        return DbalBackedConnection::fromConfiguration($container->get(DatabaseConfig::class));
    }
}
