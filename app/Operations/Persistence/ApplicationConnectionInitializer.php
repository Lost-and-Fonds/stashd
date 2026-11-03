<?php

declare(strict_types=1);

namespace App\Operations\Persistence;

use App\Operations\Durable\DurableConnections;
use Tempest\Container\Container;
use Tempest\Container\Initializer;
use Tempest\Container\Singleton;
use Tempest\Database\Connection\Connection;

/**
 * Bind the shared connection before Tempest resolves any default database service.
 *
 * Together with upstream PR 2326, this makes transaction ownership independent
 * of whether a department resolves models or Operations first.
 */
final class ApplicationConnectionInitializer implements Initializer
{
    /**
     * Let the framework use DBAL's application session without opening another PDO.
     */
    #[Singleton]
    public function initialize(Container $container): Connection
    {
        return new ApplicationConnection($container->get(DurableConnections::class)->application);
    }
}
