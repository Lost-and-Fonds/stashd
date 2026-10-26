<?php

declare(strict_types=1);

namespace App\Integration;

use RuntimeException;
use Tempest\Console\Console;
use Tempest\Console\ConsoleCommand;

final readonly class AcquisitionCommands
{
    public function __construct(
        private Console $console,
        private AcquisitionCoordinator $acquisition,
    ) {}

    #[ConsoleCommand(name: 'input:acquire', description: 'Preserve a finished discovery run, resuming saved transaction attempts on retry.')]
    public function acquire(string $runId): void
    {
        $result = $this->acquisition->acquire($runId);
        $this->console->writeRaw(json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL);
        if (array_diff(array_keys($result['counts']), ['preserved', 'unchanged']) !== []) {
            throw new RuntimeException('Acquisition did not preserve every file; inspect input:acquisitions.');
        }
    }

    #[ConsoleCommand(name: 'input:acquisitions', description: 'Read a bounded page of saved acquisition attempts and failure details.')]
    public function inspect(string $runId, string $after = ''): void
    {
        $this->console->writeRaw(json_encode($this->acquisition->read($runId, $after), JSON_THROW_ON_ERROR) . PHP_EOL);
    }
}
