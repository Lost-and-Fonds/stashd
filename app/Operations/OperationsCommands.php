<?php

declare(strict_types=1);

namespace App\Operations;

use App\Operations\Durable\DurableRuntime;
use Tempest\Console\Console;
use Tempest\Console\ConsoleCommand;

/**
 * Expose worker execution, inspection and recovery through Tempest's console.
 */
final readonly class OperationsCommands
{
    /**
     * Resolve execution infrastructure only when an Operations command runs.
     */
    public function __construct(
        private Console $console,
        private Worker $worker,
        private DurableRuntime $runtime,
    ) {}

    /**
     * Print an authoritative domain lifecycle snapshot for an admitted Assignment.
     */
    #[ConsoleCommand('operations:inspect')]
    public function inspect(string $assignment): void
    {
        $this->console->writeRaw(json_encode($this->worker->find($assignment), JSON_THROW_ON_ERROR) . PHP_EOL);
    }

    /**
     * Recover an undelivered, never-started activity from the existing Durable journal.
     */
    #[ConsoleCommand('operations:recover')]
    public function recover(string $assignment): void
    {
        $this->console->success('Reissued ' . $this->worker->recover($assignment) . ' pending activity deliveries.');
    }

    /**
     * Run Messenger workers continuously or drain once for bounded operational checks.
     */
    #[ConsoleCommand('operations:work')]
    public function work(bool $once = false, ?int $limit = null, ?string $receiver = null): void
    {
        $this->runtime->drain($receiver, $once, $limit);
    }
}
