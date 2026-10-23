<?php

namespace App\Integration;

use Tempest\Console\Console;
use Tempest\Console\ConsoleCommand;

/**
 * Provide CLI commands to scan an Input and inspect saved results a page at a time.
 */
final readonly class DiscoveryCommands
{
    /**
     * Connect the console to discovery without opening the source or database at startup.
     */
    public function __construct(
        private Console $console,
        private DiscoveryCoordinator $discovery,
    ) {}

    /**
     * Start or resume an Input's scan and print its saved status and completeness result.
     */
    #[ConsoleCommand(name: 'input:discover', description: 'Recursively discover a configured Input, resuming its unfinished run after interruption.')]
    public function discover(string $inputId): void
    {
        $this->console->writeRaw(json_encode($this->discovery->discover($inputId), JSON_THROW_ON_ERROR) . PHP_EOL);
    }

    /**
     * Print saved run status even when the source directory is unavailable.
     */
    #[ConsoleCommand(name: 'input:discovery', description: 'Read durable discovery status and coverage by run UUID.')]
    public function inspect(string $runId): void
    {
        $this->console->writeRaw(json_encode($this->discovery->read($runId), JSON_THROW_ON_ERROR) . PHP_EOL);
    }

    /**
     * Print one page of saved files and the value to pass when requesting the next page.
     */
    #[ConsoleCommand(name: 'input:discoveries', description: 'Read at most 100 discovered Items from one run; pass the returned cursor to continue.')]
    public function items(string $runId, string $after = ''): void
    {
        $this->console->writeRaw(json_encode($this->discovery->items($runId, $after), JSON_THROW_ON_ERROR) . PHP_EOL);
    }
}
