<?php

declare(strict_types=1);

namespace App\Operations;

use App\Identity\Identifier;
use App\Operations\Durable\DurableRuntime;
use Tempest\Console\Console;
use Tempest\Console\ConsoleCommand;

final readonly class OperationsCommands
{
    public function __construct(
        private Console $console,
        private Worker $worker,
        private DurableRuntime $runtime,
    ) {}

    #[ConsoleCommand('operations:inspect')]
    public function inspect(string $assignment): void
    {
        $this->console->writeRaw(json_encode($this->worker->status(Identifier::parse($assignment, Assignment::class)), JSON_THROW_ON_ERROR) . PHP_EOL);
    }

    #[ConsoleCommand('operations:work')]
    public function work(bool $once = false, ?int $limit = null, ?string $receiver = null): void
    {
        $this->runtime->drain($receiver, $once, $limit);
    }
}
