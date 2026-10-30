<?php

declare(strict_types=1);

namespace App\Operations;

use App\Integration\OperationsConfig;
use Tempest\Container\Container;
use Tempest\Container\Initializer;
use Tempest\Container\Singleton;

/**
 * Resolve Jobs on the normal application's shared persistence boundary.
 */
final readonly class JobRuntimeInitializer implements Initializer
{
    /**
     * Supply the shared owner and an explicitly bound departmental executor.
     */
    #[Singleton]
    public function initialize(Container $container): JobRuntime
    {
        return new JobRuntime(
            $container->get(OperationsConfig::class),
            $container->has(WorkExecutor::class) ? $container->get(WorkExecutor::class) : null,
            $container->get(SharedOperationsConnection::class)->database(),
        );
    }
}
