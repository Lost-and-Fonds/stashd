<?php

namespace App\RecordsAndArchives;

use League\Uri\Urn;
use PDO;
use UnexpectedValueException;

/**
 * Rebuild disposable relational Item history from verified authoritative OCFL.
 *
 * This narrow schema is an initial projection, separate from preservation commit.
 * Callers own the PDO connection and schema lifecycle; rebuild never writes Vault
 * bytes or treats existing SQL rows as evidence of archival completion.
 */
final readonly class ArchiveProjection
{
    /**
     * Bind the same Vault and exact-version archive readers used for preservation.
     */
    public function __construct(
        private Vault $vault,
        private ItemArchive $items,
        private AssetArchive $assets,
    ) {}

    /**
     * Atomically replace disposable rows with complete verified Item histories and incidents.
     *
     * Any failed Item revision or pinned Asset excludes that entire Item, including
     * stale SQL rows. Other Items are verified independently, even for shared pins.
     * Unidentifiable Object roots produce path-scoped discovery incidents. Root scan
     * and SQL failures abort replacement; SQL errors roll back all three tables.
     * Callers must supply an exception-mode PDO and own schema access control.
     * Incidents describe this scan, not archival evidence or permanent audit history.
     *
     * @return array{items: list<array{object_id: string, version: string}>, incidents: list<array{object_id: ?string, path: string, item_version: ?string, dependency_object_id: ?string, dependency_version: ?string, reason: string}>}
     */
    public function rebuild(PDO $database, string $schema): array
    {
        if (! preg_match('/^[a-z_][a-z0-9_]*$/D', $schema)) {
            throw new \InvalidArgumentException('Projection schema must be a plain identifier.');
        }
        return $this->vault->exclusivePublication(function () use ($database, $schema): array {
            $rows = [];
            $incidents = [];
            foreach ($this->vault->discoverObjects() as $entry) {
                $objectId = $entry['object_id'];
                if ($objectId === null) {
                    $incidents[] = [
                        ...$entry,
                        'item_version' => null,
                        'dependency_object_id' => null,
                        'dependency_version' => null,
                        'reason' => 'Cannot identify Object at its layout path.',
                    ];
                    continue;
                }
                if (! str_starts_with($objectId, 'urn:stashd:item:')) {
                    continue;
                }
                $version = null;
                $dependency = null;
                $itemRows = [];
                try {
                    $id = Urn::fromString($objectId);
                    if ($this->vault->inspectObject($id)['issues'] !== []) {
                        throw new UnexpectedValueException('Cannot project unreconciled Item publication.');
                    }
                    foreach ($this->vault->getObject($id)->versionNames() as $version) {
                        $dependency = null;
                        $record = $this->items->read($id, $version);
                        foreach ($record['pins'] as $pin) {
                            $dependency = $pin;
                            $assetId = Urn::fromString($pin['object_id']);
                            if ($this->vault->inspectObject($assetId)['issues'] !== [] || $this->assets->inspect($assetId, $pin['version'])['item_uri'] !== $record['uri']) {
                                throw new UnexpectedValueException('Cannot project unverified Item relationships.');
                            }
                        }
                        $itemRows[] = ['object_id' => $objectId, 'version' => $version, 'pins' => $record['pins']];
                    }
                } catch (\Exception $error) {
                    $incidents[] = [
                        ...$entry,
                        'item_version' => $version,
                        'dependency_object_id' => $dependency['object_id'] ?? null,
                        'dependency_version' => $dependency['version'] ?? null,
                        'reason' => $error->getMessage(),
                    ];
                    continue;
                }
                array_push($rows, ...$itemRows);
            }
            $schema = '"' . $schema . '"';
            $database->beginTransaction();
            try {
                $database->exec('CREATE SCHEMA IF NOT EXISTS ' . $schema);
                $database->exec('CREATE TABLE IF NOT EXISTS ' . $schema . '.items (object_id text NOT NULL, version text NOT NULL, PRIMARY KEY (object_id, version))');
                $database->exec(
                    'CREATE TABLE IF NOT EXISTS '
                    . $schema
                    . '.pins (item_object_id text NOT NULL, item_version text NOT NULL, asset_object_id text NOT NULL, asset_version text NOT NULL, PRIMARY KEY (item_object_id, item_version, asset_object_id))',
                );
                $database->exec(
                    'CREATE TABLE IF NOT EXISTS '
                    . $schema
                    . '.incidents (object_id text, path text PRIMARY KEY, item_version text, dependency_object_id text, dependency_version text, reason text NOT NULL)',
                );
                $database->exec('DELETE FROM ' . $schema . '.incidents');
                $insertIncident = $database->prepare(
                    'INSERT INTO ' . $schema . '.incidents (object_id, path, item_version, dependency_object_id, dependency_version, reason) VALUES (?, ?, ?, ?, ?, ?)',
                );
                if ($insertIncident === false) {
                    throw new \RuntimeException('Could not prepare incident statement.');
                }
                foreach ($incidents as $incident) {
                    $insertIncident->execute([
                        $incident['object_id'],
                        $incident['path'],
                        $incident['item_version'],
                        $incident['dependency_object_id'],
                        $incident['dependency_version'],
                        $incident['reason'],
                    ]);
                }
                $database->exec('DELETE FROM ' . $schema . '.pins');
                $database->exec('DELETE FROM ' . $schema . '.items');
                $insertItem = $database->prepare('INSERT INTO ' . $schema . '.items VALUES (?, ?)');
                $insertPin = $database->prepare('INSERT INTO ' . $schema . '.pins VALUES (?, ?, ?, ?)');
                if ($insertItem === false || $insertPin === false) {
                    throw new \RuntimeException('Could not prepare projection statements.');
                }
                foreach ($rows as $row) {
                    $insertItem->execute([$row['object_id'], $row['version']]);
                    foreach ($row['pins'] as $pin) {
                        $insertPin->execute([$row['object_id'], $row['version'], $pin['object_id'], $pin['version']]);
                    }
                }
                $database->commit();
            } catch (\Throwable $error) {
                $database->rollBack();
                throw $error;
            }
            return ['items' => array_map(static fn (array $row): array => ['object_id' => $row['object_id'], 'version' => $row['version']], $rows), 'incidents' => $incidents];
        });
    }
}
