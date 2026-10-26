<?php

declare(strict_types=1);

namespace App\Integration;

use RuntimeException;
use Tempest\Console\Console;
use Tempest\Console\ConsoleCommand;

/**
 * Expose manual acquisition and durable attempt inspection through the real CLI.
 */
final readonly class AcquisitionCommands
{
    /**
     * Connect console output to acquisition without opening source files at startup.
     */
    public function __construct(
        private Console $console,
        private AcquisitionCoordinator $acquisition,
    ) {}

    /**
     * Print run outcomes and fail the command unless every file is preserved or unchanged.
     *
     * Retrying the same run reuses persisted transaction identities.
     */
    #[ConsoleCommand(name: 'input:acquire', description: 'Preserve a finished discovery run, resuming saved transaction attempts on retry.')]
    public function acquire(string $runId): void
    {
        $result = $this->acquisition->acquire($runId);
        $this->console->writeRaw(json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL);
        if (array_diff(array_keys($result['counts']), ['preserved', 'unchanged']) !== []) {
            throw new RuntimeException('Acquisition did not preserve every file; inspect input:acquisitions.');
        }
    }

    /**
     * Print one saved attempt page and its continuation cursor without source access.
     */
    #[ConsoleCommand(name: 'input:acquisitions', description: 'Read a bounded page of saved acquisition attempts and failure details.')]
    public function inspect(string $runId, string $after = ''): void
    {
        $this->console->writeRaw(json_encode($this->acquisition->read($runId, $after), JSON_THROW_ON_ERROR) . PHP_EOL);
    }
}
