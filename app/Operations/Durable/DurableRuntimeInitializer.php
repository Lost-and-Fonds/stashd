<?php

declare(strict_types=1);

namespace App\Operations\Durable;

use InvalidArgumentException;
use PDO;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\PostgreSqlStore;
use Tempest\Container\Container;
use Tempest\Container\Initializer;
use Tempest\Container\Singleton;

/**
 * Construct the experimental service graph through Tempest's container.
 *
 * Explicit connection registration keeps this opt-in and avoids opening a
 * second application's database while unrelated Tempest commands boot.
 */
final class DurableRuntimeInitializer implements Initializer
{
    /**
     * Use upstream session claims so blocking activities need no heartbeat loop.
     */
    #[Singleton]
    public function initialize(Container $container): DurableRuntime
    {
        $connections = $container->get(DurableConnections::class);

        $native = $connections->claims->getNativeConnection();
        if (! $native instanceof PDO) {
            throw new InvalidArgumentException('The spike requires the pdo_pgsql DBAL driver.');
        }

        return new DurableRuntime(
            $connections->journal,
            $connections->application,
            new LockFactory(new PostgreSqlStore($native)),
        );
    }
}
