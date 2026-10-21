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
     * Replace projection rows transactionally after verifying all retained Item versions.
     *
     * The caller supplies an existing private schema and owns its access control.
     * Invalid history aborts before any SQL mutation. SQL failures roll back both
     * tables; projection availability cannot authorize another archival revision.
     */
    public function rebuild(PDO $database, string $schema): array
    {
        if (! preg_match('/^[a-z_][a-z0-9_]*$/D', $schema)) {
            throw new \InvalidArgumentException('Projection schema must be a plain identifier.');
        }
        return $this->vault->exclusivePublication(function () use ($database, $schema): array {
            $rows = [];
            foreach ($this->vault->open()->listObjects() as $objectId) {
                if (! str_starts_with($objectId, 'urn:stashd:item:')) {
                    continue;
                }
                $id = Urn::fromString($objectId);
                if ($this->vault->inspectObject($id)['issues'] !== []) {
                    throw new UnexpectedValueException('Cannot project unreconciled Item publication.');
                }
                foreach ($this->vault->getObject($id)->versionNames() as $version) {
                    $record = $this->items->read($id, $version);
                    foreach ($record['pins'] as $pin) {
                        $assetId = Urn::fromString($pin['object_id']);
                        if ($this->vault->inspectObject($assetId)['issues'] !== [] || $this->assets->inspect($assetId, $pin['version'])['item_uri'] !== $record['uri']) {
                            throw new UnexpectedValueException('Cannot project unverified Item relationships.');
                        }
                    }
                    $rows[] = ['object_id' => $objectId, 'version' => $version, 'pins' => $record['pins']];
                }
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
            return array_map(static fn (array $row): array => ['object_id' => $row['object_id'], 'version' => $row['version']], $rows);
        });
    }
}
