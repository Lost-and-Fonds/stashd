<?php

namespace App\Operations;

use Tempest\Console\Console;
use Tempest\Console\ConsoleCommand;

/**
 * Expose keyed Job admission; native worker and recovery commands belong to Ecotone.
 */
final readonly class JobCommands
{
    /**
     * Resolve domain admission and console output.
     */
    public function __construct(
        private Console $console,
        private Jobs $jobs,
    ) {}

    /**
     * Print the durable identity returned by legacy example admission.
     */
    #[ConsoleCommand(name: 'job:admit', description: 'Admit an example job with an idempotency key.')]
    public function admit(string $key, string $payload): void
    {
        $this->console->writeRaw(json_encode(['id' => $this->jobs->admit($key, $payload)], JSON_THROW_ON_ERROR) . PHP_EOL);
    }
}
