<?php

declare(strict_types=1);

namespace App\Catalogue;

use Tempest\Console\Console;
use Tempest\Console\ConsoleCommand;

/**
 * Public collection configuration, membership and frozen selection receipts.
 */
final readonly class CatalogueCommands
{
    /**
     * Connect the public collection commands to Catalogue and JSON output.
     */
    public function __construct(
        private Catalogue $catalogue,
        private Console $console,
    ) {}

    /**
     * Return the identity of the newly persisted Stash.
     */
    #[ConsoleCommand('stash:create')]
    public function create(string $name): void
    {
        $this->report(['id' => $this->catalogue->create($name)]);
    }

    /**
     * Report the saved relationship only after both referenced records are validated.
     */
    #[ConsoleCommand('stash:input:attach')]
    public function attach(string $stashId, string $inputId): void
    {
        $this->catalogue->attach($stashId, $inputId);
        $this->report(['stash_id' => $stashId, 'input_id' => $inputId]);
    }

    /**
     * Return one bounded page of persisted Catalogue membership.
     */
    #[ConsoleCommand('stash:items')]
    public function items(string $stashId, string $after = ''): void
    {
        $this->report($this->catalogue->items($stashId, $after));
    }

    /**
     * Return a frozen Broadcast selection resolved from real Stash membership.
     */
    #[ConsoleCommand('stash:broadcast:select')]
    public function select(string $stashId, string $broadcastId): void
    {
        $this->report(['id' => $this->catalogue->select($stashId, $broadcastId)]);
    }

    /**
     * Write one JSON receipt and leave command failures to Tempest.
     */
    private function report(array $record): void
    {
        $this->console->writeRaw(json_encode($record, JSON_THROW_ON_ERROR) . PHP_EOL);
    }
}
