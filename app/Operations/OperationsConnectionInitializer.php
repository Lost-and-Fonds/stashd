<?php

declare(strict_types=1);

namespace App\Operations;

use Tempest\Container\Container;
use Tempest\Container\Initializer;
use Tempest\Container\Singleton;
use Tempest\Database\Connection\Connection;

/**
 * Bind Tempest's public connection interface before any Database is resolved.
 * Requires the isolated upstream Connection injection fix in PR 2326.
 */
final readonly class OperationsConnectionInitializer implements Initializer
{
    /**
     * Give Tempest an adapter over the same owner used by the Ecotone runtime.
     */
    #[Singleton]
    public function initialize(Container $container): Connection
    {
        return new TempestDbalConnection($container->get(SharedOperationsConnection::class)->database());
    }
}
