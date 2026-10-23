<?php

namespace App\Integration;

use Tempest\Console\Console;
use Tempest\Console\ConsoleCommand;

/**
 * Expose persistent in-process Input and Broadcast configuration through Tempest.
 *
 * Commands report JSON configuration only; they never discover or move bytes.
 */
final readonly class IntegrationCommands
{
    /**
     * Bind the operational configuration service without requiring SQL at CLI boot.
     */
    public function __construct(
        private Console $console,
        private IntegrationConfigurations $configurations,
    ) {}

    /**
     * Initialize the operational schema explicitly and report completion.
     */
    #[ConsoleCommand(name: 'integration:init', description: 'Initialize the PostgreSQL operational configuration schema without changing Vault contents.')]
    public function initialize(): void
    {
        $this->configurations->initialize();
        $this->report(['status' => 'initialized']);
    }

    /**
     * Persist a registered Input configuration after checking its local source root.
     */
    #[ConsoleCommand(name: 'input:create', description: 'Configure an in-process Filesystem Input from an existing absolute directory.')]
    public function createInput(string $component, string $root): void
    {
        $this->report($this->configurations->create('input', $component, $root));
    }

    /**
     * Print a saved Input even if its source is currently unavailable.
     */
    #[ConsoleCommand(name: 'input:inspect', description: 'Read saved Input configuration by UUID.')]
    public function inspectInput(string $id): void
    {
        $this->report($this->configurations->read('input', $id));
    }

    /**
     * Persist a registered Broadcast configuration without creating its destination.
     */
    #[ConsoleCommand(name: 'broadcast:create', description: 'Configure a copy-only Filesystem Broadcast at an absolute directory.')]
    public function createBroadcast(string $component, string $root): void
    {
        $this->report($this->configurations->create('broadcast', $component, $root));
    }

    /**
     * Print saved Broadcast settings without performing publication or root creation.
     */
    #[ConsoleCommand(name: 'broadcast:inspect', description: 'Read saved Broadcast configuration by UUID.')]
    public function inspectBroadcast(string $id): void
    {
        $this->report($this->configurations->read('broadcast', $id));
    }

    /**
     * Print one JSON result while leaving failures to the console error handler.
     */
    private function report(array $record): void
    {
        $this->console->writeRaw(json_encode($record, JSON_THROW_ON_ERROR) . PHP_EOL);
    }
}
