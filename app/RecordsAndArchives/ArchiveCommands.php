<?php

namespace App\RecordsAndArchives;

use App\Administration\VaultConfig;
use JsonException;
use League\Uri\Urn;
use RuntimeException;
use Tempest\Console\Console;
use Tempest\Console\ConsoleCommand;

/**
 * Expose archival initialization, publication and inspection through the CLI.
 *
 * Commands print JSON results and let validation or storage failures propagate
 * to the console error handler. Item and Asset writes are separate commits.
 */
final readonly class ArchiveCommands
{
    /**
     * Bind the archival services and configured root used by CLI operations.
     */
    public function __construct(
        private Console $console,
        private Vault $vault,
        private AssetArchive $assets,
        private ItemArchive $items,
        private VaultConfig $config,
    ) {}

    /**
     * Initialize a new empty Vault and print its root and permanent identity.
     *
     * This is not an idempotent open operation; existing nonempty roots are rejected.
     */
    #[ConsoleCommand(name: 'vault:init', description: 'Initialize an empty local Vault at the configured VAULT_ROOT.')]
    public function initialize(): void
    {
        $this->vault->create();
        $this->console->writeln(json_encode(['root' => $this->config->root, 'vault_id' => $this->vault->getIdentity()?->identifier->toString()], JSON_THROW_ON_ERROR));
    }

    /**
     * Decode complete JSON metadata and pins, publish an Item revision and print its version.
     *
     * Requires object or array metadata and a JSON list of pins. Parsing and domain
     * validation failures propagate without a successful publication report.
     */
    #[ConsoleCommand(name: 'item:commit', description: 'Commit a complete Item snapshot with JSON metadata and an optional JSON list of exact Asset pins.')]
    public function commitItem(string $uri, string $metadata = '{}', string $pins = '[]'): void
    {
        try {
            $metadataValues = json_decode($metadata, true, flags: JSON_THROW_ON_ERROR);
            $pinValues = json_decode($pins, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidItemRevision('Item metadata and pins must be valid JSON.', previous: $exception);
        }
        if (! is_array($metadataValues) || ! is_array($pinValues) || ! str_starts_with(ltrim($pins), '[')) {
            throw new InvalidItemRevision('Metadata must be a JSON object or array; pins must be a JSON list.');
        }
        $object = $this->items->commitRevision($uri, $metadataValues, $pinValues);
        $this->report(['object_id' => $object->id(), 'version' => $object->head()]);
    }

    /**
     * Print a verified Item record and its publication diagnostics.
     *
     * An omitted version selects the current head; explicit versions are read exactly.
     */
    #[ConsoleCommand(name: 'item:inspect', description: 'Read an Item revision and its stored exact Asset pins. Omitted version reads the Item head.')]
    public function inspectItem(string $objectId, ?string $version = null): void
    {
        $id = Urn::fromString($objectId);
        $version ??= $this->vault->getObject($id)->head();
        $this->report([...$this->items->read($id, $version), 'ocfl_version' => $version, 'publication' => $this->vault->inspectObject($id)]);
    }

    /**
     * Preserve one absolute local source as the complete next Asset snapshot.
     *
     * This appends a version, not a file change to an existing file set. Prints
     * verified byte evidence without creating an Item revision or pin.
     */
    #[ConsoleCommand(name: 'asset:import', description: 'Preserve one file as a complete Asset version. Sources must be absolute local paths.')]
    public function import(string $itemUri, string $assetKey, string $logicalPath, string $source): void
    {
        $this->report($this->assets->preserve($itemUri, $assetKey, [$logicalPath => $source]));
    }

    /**
     * Verify an exact Asset version and print its record plus publication diagnostics.
     */
    #[ConsoleCommand(name: 'asset:inspect', description: 'Read and independently verify an exact Asset version.')]
    public function inspect(string $objectId, string $version): void
    {
        $id = Urn::fromString($objectId);
        $this->report([...$this->assets->inspect($id, $version), 'publication' => $this->vault->inspectObject($id)]);
    }

    /**
     * Copy an exact verified historical file to a new absolute destination.
     *
     * Exclusive creation prevents overwrites. The exported size and digest are
     * checked before success is printed; copy or verification failure removes
     * the new destination. Both input and output streams are closed.
     */
    #[ConsoleCommand(name: 'asset:export', description: 'Export an exact historical file to a new absolute destination. Never overwrites.')]
    public function export(string $objectId, string $version, string $logicalPath, string $destination): void
    {
        if (! str_starts_with($destination, '/') || str_contains($destination, "\0")) {
            throw new InvalidAsset('Export destination must be an explicit absolute path.');
        }
        $id = Urn::fromString($objectId);
        $record = $this->assets->inspect($id, $version);
        $input = $this->assets->readStream($id, $version, $logicalPath);
        try {
            $output = @fopen($destination, 'x+b');
            if ($output === false) {
                throw new InvalidAsset('Export destination must be a new writable file; existing files are never overwritten.');
            }
            try {
                $size = stream_copy_to_stream($input, $output);
                if ($size === false || ! fflush($output) || ! rewind($output)) {
                    throw new RuntimeException('Could not complete export.');
                }
                $context = hash_init('sha512');
                $read = hash_update_stream($context, $output);
                $digest = hash_final($context);
                $expected = $record['files'][$logicalPath];
                if ($read !== $expected['size'] || $size !== $read || ! hash_equals($expected['digest'], $digest)) {
                    throw new RuntimeException('Export bytes do not match preserved byte evidence.');
                }
            } catch (\Throwable $exception) {
                fclose($output);
                unlink($destination);
                throw $exception;
            }
            fclose($output);
        } finally {
            fclose($input);
        }
        $this->console->writeln(json_encode(['destination' => $destination, ...$expected], JSON_THROW_ON_ERROR));
    }

    /**
     * Print acknowledged Object history and stranded-file issues without repairing storage.
     */
    #[ConsoleCommand(name: 'vault:inspect-object', description: 'Verify acknowledged local Object history and report incomplete publication evidence without changing files.')]
    public function inspectPublication(string $objectId): void
    {
        $this->report(['object_id' => $objectId, ...$this->vault->inspectObject(Urn::fromString($objectId))]);
    }

    /**
     * Write one JSON result line, preserving readable slashes and propagating encoding errors.
     */
    private function report(array $record): void
    {
        $this->console->writeln(json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
