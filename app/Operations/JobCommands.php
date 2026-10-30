<?php

namespace App\Operations;

use Tempest\Console\Console;
use Tempest\Console\ConsoleCommand;

/**
 * Expose the evaluation job admission and native consumer commands.
 */
final readonly class JobCommands
{
    /**
     * Resolve domain admission and the isolated evaluation runtime.
     */
    public function __construct(
        private Console $console,
        private Jobs $jobs,
        private JobRuntime $runtime,
    ) {}

    /**
     * Print the durable identity returned by legacy example admission.
     */
    #[ConsoleCommand(name: 'job:admit', description: 'Admit an example job with an idempotency key.')]
    public function admit(string $key, string $payload): void
    {
        $this->console->writeRaw(json_encode(['id' => $this->jobs->admit($key, $payload)], JSON_THROW_ON_ERROR) . PHP_EOL);
    }

    /**
     * Consume the native jobs channel in this process.
     */
    #[ConsoleCommand(name: 'job:work', description: 'Run the native Ecotone job consumer.')]
    public function work(string $receiver, bool $once = false): void
    {
        $this->runtime->work($receiver, $once);
    }
}
