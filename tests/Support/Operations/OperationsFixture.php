<?php

declare(strict_types=1);

namespace Tests\Support\Operations;

use PHPUnit\Framework\IncompleteTestError;
use RuntimeException;
use Tests\Support\RecordsAndArchives\ArchiveFixture;

/**
 * Provides one disposable installation boundary for an Operations scenario.
 *
 * The existing filesystem fixture supplies safe cleanup. Each action gets a
 * fresh driver instance so an adapter cannot accidentally pass using state
 * retained only in its PHP object between otherwise independent calls.
 */
final class OperationsFixture
{
    private ArchiveFixture $workspace;

    /**
     * Allocate a private root without connecting to any real database or worker.
     */
    public function __construct()
    {
        $this->workspace = new ArchiveFixture();

        if (! mkdir($this->workspace->path('operations'), 0o700, true)) {
            throw new RuntimeException('Unable to create the Operations acceptance workspace.');
        }
    }

    /**
     * Run one test-only operation against a fresh real application context.
     *
     * Missing production support is INCOMPLETE, never a fabricated pass.
     * The driver must isolate database schemas, broker queues and processes
     * using the private workspace supplied to every call.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function run(string $action, array $arguments = []): array
    {
        $class = getenv('STASHD_OPERATIONS_ACCEPTANCE_DRIVER');

        if ($class === false || $class === '') {
            throw new IncompleteTestError('No real Operations acceptance driver has been installed.');
        }

        if (! class_exists($class) || ! is_a($class, OperationsDriver::class, true)) {
            throw new RuntimeException('The Operations acceptance driver must implement OperationsDriver.');
        }

        /** @var OperationsDriver $driver */
        $driver = new $class();

        return $driver->run($action, [
            ...$arguments,
            'workspace' => $this->workspace->path('operations'),
        ]);
    }

    /**
     * Remove only this scenario's temporary filesystem workspace.
     *
     * A real driver remains responsible for stopping its workers and removing
     * its own disposable database schemas and transport resources.
     */
    public function dispose(): void
    {
        try {
            $class = getenv('STASHD_OPERATIONS_ACCEPTANCE_DRIVER');
            if ($class !== false && $class !== '' && is_a($class, OperationsDriver::class, true)) {
                /** @var OperationsDriver $driver */
                $driver = new $class();
                $driver->run('fixture.dispose', ['workspace' => $this->workspace->path('operations')]);
            }
        } finally {
            $this->workspace->dispose();
        }
    }
}
