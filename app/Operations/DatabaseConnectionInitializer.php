<?php

declare(strict_types=1);

namespace App\Operations;

use Ecotone\Api\Tempest\DbalBackedConnection;
use Tempest\Container\Container;
use Tempest\Container\Initializer;
use Tempest\Container\Singleton;
use Tempest\Database\Config\DatabaseConfig;
use Tempest\Database\Connection\Connection;

/**
 * Bind Tempest's public connection interface before any Database is resolved.
 * Requires the isolated upstream Connection injection fix in PR 2326.
 */
final readonly class DatabaseConnectionInitializer implements Initializer
{
    /**
     * Select the official adapter's shared transaction owner from Tempest configuration.
     */
    #[Singleton]
    public function initialize(Container $container): Connection
    {
        return DbalBackedConnection::fromConfiguration($container->get(DatabaseConfig::class));
    }
}
