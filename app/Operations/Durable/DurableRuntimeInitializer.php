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
 * Construct Operations' service graph through Tempest's container.
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
            throw new InvalidArgumentException('Operations requires the pdo_pgsql DBAL driver.');
        }

        $runtime = new DurableRuntime(
            $connections->journal,
            $connections->application,
            new LockFactory(new PostgreSqlStore($native)),
            60,
            fn ($routine, $interactive) => new ActivityDelivery(
                $routine,
                $interactive,
                $container->get(\App\Operations\Persistence\Assignments::class),
                $container->get(\Psr\Log\LoggerInterface::class),
            ),
            $container->get(\App\Operations\Authority\PublicationAuthority::class),
            $container->get(\App\Operations\Authority\SessionActivityClaims::class),
        );
        $runtime->workflows->registerClass(AssignmentWorkflow::class);
        $runtime->on(StartAssignment::class, fn (StartAssignment $message) => $container->get(\App\Operations\Worker::class)->start($message));
        $runtime->activities->register('operations.execute', fn (array $payload) => $container->get(\App\Operations\Worker::class)->execute(
            $payload['assignment'],
            $payload['demand'],
        ));

        return $runtime;
    }
}
