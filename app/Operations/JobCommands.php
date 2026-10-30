<?php

namespace App\Operations;

use Tempest\Console\Console;
use Tempest\Console\ConsoleCommand;

final readonly class JobCommands
{
    public function __construct(
        private Console $console,
        private Jobs $jobs,
        private JobRuntime $runtime,
    ) {}

    #[ConsoleCommand(name: 'job:admit', description: 'Admit an example job with an idempotency key.')]
    public function admit(string $key, string $payload): void
    {
        $this->console->writeRaw(json_encode(['id' => $this->jobs->admit($key, $payload)], JSON_THROW_ON_ERROR) . PHP_EOL);
    }

    #[ConsoleCommand(name: 'job:retry-message', description: 'Redispatch one failed transport message by its message ID.')]
    public function retryMessage(string $id): void
    {
        $this->runtime->retryFailed($id);
    }

    #[ConsoleCommand(name: 'job:work', description: 'Run the job worker or outbox relay.')]
    public function work(string $receiver, bool $once = false): void
    {
        $this->runtime->work($receiver, $once);
    }
}
