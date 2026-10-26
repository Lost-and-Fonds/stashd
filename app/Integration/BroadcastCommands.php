<?php

declare(strict_types=1);

namespace App\Integration;

use App\Integration\DTO\BroadcastSelection;
use Generator;
use RuntimeException;
use Tempest\Console\Console;
use Tempest\Console\ConsoleCommand;

/**
 * Freeze explicit revision selections and inspect publication state through the CLI.
 */
final readonly class BroadcastCommands
{
    /**
     * Resolve application services without opening the database until a command runs.
     */
    public function __construct(
        private Console $console,
        private BroadcastCoordinator $broadcast,
        private BroadcastStore $store,
    ) {}

    /**
     * Read newline-delimited selections without loading the whole collection into memory.
     */
    #[ConsoleCommand(name: 'broadcast:select', description: 'Freeze Item ID/version selections from a JSON-lines file, without publishing.')]
    public function select(string $broadcastId, string $selectionFile): void
    {
        $id = $this->broadcast->select($broadcastId, $this->selections($selectionFile));
        $this->console->writeRaw(json_encode(['id' => $id, 'status' => 'ready'], JSON_THROW_ON_ERROR) . PHP_EOL);
    }

    /**
     * Expose run status and one bounded committed-report page; failed reports stay hidden.
     */
    #[ConsoleCommand(name: 'broadcast:publication', description: 'Inspect a publication run and its committed result page.')]
    public function inspect(string $runId, int $after = -1): void
    {
        $this->console->writeRaw(json_encode(['run' => $this->store->read($runId), 'results' => $this->store->results($runId, $after)], JSON_THROW_ON_ERROR) . PHP_EOL);
    }

    /**
     * Close the selection file on success or failure and reject malformed records.
     *
     * @return Generator<int, BroadcastSelection>
     */
    private function selections(string $path): Generator
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Could not open selection file.');
        }

        try {
            while (($line = fgets($stream, 65_537)) !== false) {
                $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($record) || ! is_string($record['item_id'] ?? null) || ! is_string($record['version'] ?? null)) {
                    throw new RuntimeException('Each selection line requires string item_id and version.');
                }

                yield new BroadcastSelection($record['item_id'], $record['version']);
            }

            if (! feof($stream)) {
                throw new RuntimeException('Selection file could not be completely read.');
            }
        } finally {
            fclose($stream);
        }
    }
}
