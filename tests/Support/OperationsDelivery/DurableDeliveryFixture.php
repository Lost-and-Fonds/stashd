<?php

declare(strict_types=1);

namespace Tests\Support\OperationsDelivery;

use PHPUnit\Framework\IncompleteTestError;
use RuntimeException;
use Tests\Support\RecordsAndArchives\ArchiveFixture;

/**
 * Give each durable-delivery scenario a private test installation.
 *
 * Each action creates a fresh adapter, so the only durable state is in the
 * disposable installation and its real transports, not in a PHP fixture object.
 */
final class DurableDeliveryFixture
{
    private ArchiveFixture $workspace;

    /**
     * Create an isolated workspace without touching a production database.
     */
    public function __construct()
    {
        $this->workspace = new ArchiveFixture();

        if (! mkdir($this->workspace->path('operations-delivery'), 0o700, true)) {
            throw new RuntimeException('Unable to create the durable-delivery acceptance workspace.');
        }
    }

    /**
     * Run an observable operation with a new driver instance.
     *
     * Without an installed driver the test is intentionally INCOMPLETE. Invalid
     * configuration and unexpected database/process failures must fail normally.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function run(string $action, array $arguments = []): array
    {
        $class = getenv('STASHD_OPERATIONS_DELIVERY_DRIVER');

        if ($class === false || $class === '') {
            throw new IncompleteTestError('No real OPS-C02 durable-delivery acceptance driver has been installed.');
        }

        if (! class_exists($class) || ! is_a($class, DurableDeliveryDriver::class, true)) {
            throw new RuntimeException('The configured durable-delivery driver must implement DurableDeliveryDriver.');
        }

        /** @var DurableDeliveryDriver $driver */
        $driver = new $class();

        return $driver->run($action, [
            ...$arguments,
            'workspace' => $this->workspace->path('operations-delivery'),
        ]);
    }

    /**
     * Shut down controlled processes and remove disposable data after any outcome.
     */
    public function dispose(): void
    {
        try {
            $class = getenv('STASHD_OPERATIONS_DELIVERY_DRIVER');
            if ($class !== false && $class !== '' && is_a($class, DurableDeliveryDriver::class, true)) {
                /** @var DurableDeliveryDriver $driver */
                $driver = new $class();
                $driver->run('fixture.dispose', ['workspace' => $this->workspace->path('operations-delivery')]);
            }
        } finally {
            $this->workspace->dispose();
        }
    }
}
