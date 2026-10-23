<?php

namespace App\Integration;

use Tempest\Console\Console;
use Tempest\Console\ConsoleCommand;

/**
 * Expose manual discovery and bounded inspection through real Tempest CLI commands.
 */
final readonly class DiscoveryCommands
{
    /**
     * Bind Core orchestration without opening an Input or database at console boot.
     */
    public function __construct(
        private Console $console,
        private DiscoveryCoordinator $discovery,
    ) {}

    /**
     * Start or resume an Input's current manual scan and print its durable coverage.
     */
    #[ConsoleCommand(name: 'input:discover', description: 'Recursively discover a configured Input, resuming its unfinished run after interruption.')]
    public function discover(string $inputId): void
    {
        $this->console->writeRaw(json_encode($this->discovery->discover($inputId), JSON_THROW_ON_ERROR) . PHP_EOL);
    }

    /**
     * Inspect a run without requiring its source to remain online.
     */
    #[ConsoleCommand(name: 'input:discovery', description: 'Read durable discovery status and coverage by run UUID.')]
    public function inspect(string $runId): void
    {
        $this->console->writeRaw(json_encode($this->discovery->read($runId), JSON_THROW_ON_ERROR) . PHP_EOL);
    }

    /**
     * Print one page of persisted observations and a cursor for the next page.
     */
    #[ConsoleCommand(name: 'input:discoveries', description: 'Read at most 100 discovered Items from one run; pass the returned cursor to continue.')]
    public function items(string $runId, string $after = ''): void
    {
        $this->console->writeRaw(json_encode($this->discovery->items($runId, $after), JSON_THROW_ON_ERROR) . PHP_EOL);
    }
}
