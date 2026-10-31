<?php

declare(strict_types=1);

namespace Tests\Support\OperationsFencing;

use PHPUnit\Framework\IncompleteTestError;
use RuntimeException;
use Tests\Support\RecordsAndArchives\ArchiveFixture;

/**
 * Provide a private installation for an outside-in OPS-C03 fencing scenario.
 *
 * Only the installed driver can touch transport or database state; this fixture
 * retains no Job, lease, worker, or result state in its PHP object.
 */
final class FencingFixture
{
    private ArchiveFixture $workspace;

    /**
     * Allocate a filesystem root without connecting to an application database.
     */
    public function __construct()
    {
        $this->workspace = new ArchiveFixture();

        if (! mkdir($this->workspace->path('operations-fencing'), 0o700, true)) {
            throw new RuntimeException('Unable to create the Operations fencing workspace.');
        }
    }

    /**
     * Execute an action through a new real adapter using the private workspace.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function run(string $action, array $arguments = []): array
    {
        $class = getenv('STASHD_OPERATIONS_FENCING_DRIVER');
        if ($class === false || $class === '') {
            throw new IncompleteTestError('No real OPS-C03 fencing acceptance driver has been installed.');
        }

        if (! class_exists($class) || ! is_a($class, FencingDriver::class, true)) {
            throw new RuntimeException('The OPS-C03 driver must implement FencingDriver.');
        }

        /** @var FencingDriver $driver */
        $driver = new $class();

        return $driver->run($action, [
            ...$arguments,
            'workspace' => $this->workspace->path('operations-fencing'),
        ]);
    }

    /**
     * Stop every controlled worker before removing its disposable installation.
     */
    public function dispose(): void
    {
        try {
            $class = getenv('STASHD_OPERATIONS_FENCING_DRIVER');
            if ($class !== false && $class !== '' && is_a($class, FencingDriver::class, true)) {
                /** @var FencingDriver $driver */
                $driver = new $class();
                $driver->run('fixture.dispose', ['workspace' => $this->workspace->path('operations-fencing')]);
            }
        } finally {
            $this->workspace->dispose();
        }
    }
}
