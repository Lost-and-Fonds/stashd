<?php

namespace App\Integration;

use App\Administration\VaultConfig;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

/**
 * Persist generic Input and Broadcast configuration in a separate operational schema.
 *
 * Filesystem is the sole registered in-process integration in this increment.
 * Configuration never creates source, destination or Vault contents. Schema and
 * root checks are serialized across cooperating local configuration writers.
 *
 * @phpstan-type Configuration array{id: string, kind: string, component: string, settings: array<string, mixed>}
 */
final readonly class IntegrationConfigurations
{
    /**
     * Bind explicit SQL settings and the local Filesystem policy without opening storage.
     */
    public function __construct(
        private OperationsConfig $config,
        private VaultConfig $vault,
        private FilesystemRoots $roots,
    ) {}

    /**
     * Bootstrap only the operational schema and configuration table, preserving rows.
     *
     * The operator must provision the database and grant schema creation first.
     * No archive projection tables are created, replaced or read.
     */
    public function initialize(): void
    {
        $database = $this->connect();
        $database->beginTransaction();
        try {
            $this->lock($database);
            $database->exec('CREATE SCHEMA IF NOT EXISTS "' . $this->config->schema . '"');
            $database->exec(
                'CREATE TABLE IF NOT EXISTS ' . $this->table() . " (
                id uuid PRIMARY KEY, kind text NOT NULL CHECK (kind IN ('input', 'broadcast')),
                component text NOT NULL, settings jsonb NOT NULL CHECK (jsonb_typeof(settings) = 'object'))",
            );
            $database->commit();
        } catch (\Throwable $error) {
            $database->rollBack();
            throw $error;
        }
    }

    /**
     * Configure a Filesystem Input or Broadcast after checking all roots under one SQL lock.
     *
     * Repeating identical configuration returns its existing identity. Other equal
     * or nested roots are refused before insertion, including a changed Vault root.
     * Root lives in component settings, never in the generic record's columns.
     *
     * @return Configuration
     */
    public function create(string $kind, string $component, string $root): array
    {
        if (! in_array($kind, ['input', 'broadcast'], true) || $component !== 'filesystem') {
            throw new InvalidArgumentException('Only the in-process filesystem Input and Broadcast are registered.');
        }
        if ($this->vault->provider !== 'local') {
            throw new InvalidArgumentException('Filesystem configuration currently requires a local Vault.');
        }
        $database = $this->connect();
        $database->beginTransaction();
        try {
            $this->lock($database);
            $root = $this->roots->canonical($root, allowMissing: $kind === 'broadcast');
            $vault = $this->roots->canonical($this->vault->root, allowMissing: true);
            $this->roots->requireSeparate($root, $vault);
            $existing = null;
            $rows = $database->query('SELECT id, kind, component, settings FROM ' . $this->table());
            if ($rows === false) {
                throw new RuntimeException('Could not read operational configurations.');
            }
            foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $record = $this->decode($row);
                if ($record['component'] !== 'filesystem') {
                    continue;
                }
                $otherRoot = $record['settings']['root'];
                if (! is_string($otherRoot)) {
                    throw new RuntimeException('Stored Filesystem configuration has no root.');
                }
                $otherRoot = $this->roots->canonical($otherRoot, allowMissing: true);
                $this->roots->requireSeparate($otherRoot, $vault);
                if ($record['kind'] === $kind && $otherRoot === $root) {
                    $existing = $record;
                    continue;
                }
                $this->roots->requireSeparate($root, $otherRoot);
            }
            $record = $existing ?? ['id' => Uuid::v7()->toString(), 'kind' => $kind, 'component' => $component, 'settings' => ['root' => $root]];
            if ($existing === null) {
                $statement = $database->prepare('INSERT INTO ' . $this->table() . ' (id, kind, component, settings) VALUES (?, ?, ?, ?)');
                if ($statement === false) {
                    throw new RuntimeException('Could not prepare configuration insertion.');
                }
                $statement->execute([$record['id'], $kind, $component, json_encode($record['settings'], JSON_THROW_ON_ERROR)]);
            }
            $database->commit();
            return $record;
        } catch (\Throwable $error) {
            $database->rollBack();
            throw $error;
        }
    }

    /**
     * Read persisted configuration without requiring the source to remain online.
     *
     * This is configuration inspection, not authorization to execute against a root.
     *
     * @return Configuration
     */
    public function read(string $kind, string $id): array
    {
        if (! Uuid::isValid($id)) {
            throw new InvalidArgumentException('A valid configuration UUID is required.');
        }
        $statement = $this->connect()->prepare('SELECT id, kind, component, settings FROM ' . $this->table() . ' WHERE id = ? AND kind = ?');
        if ($statement === false) {
            throw new RuntimeException('Could not prepare configuration lookup.');
        }
        $statement->execute([$id, $kind]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new InvalidArgumentException('Configuration not found for the requested lifecycle.');
        }
        return $this->decode($row);
    }

    /**
     * Open only an explicitly configured PostgreSQL connection when a command needs it.
     */
    private function connect(): PDO
    {
        if (! str_starts_with($this->config->dsn, 'pgsql:')) {
            throw new InvalidArgumentException('Set STASHD_OPERATIONS_DSN to an explicit PostgreSQL connection before integration:init.');
        }
        if ($this->config->username === '') {
            throw new InvalidArgumentException('Set STASHD_OPERATIONS_USERNAME to an explicit application owner.');
        }
        return new PDO($this->config->dsn, $this->config->username, $this->config->password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    /**
     * Serialize configuration checks and insertion within this database and schema.
     */
    private function lock(PDO $database): void
    {
        $statement = $database->prepare('SELECT pg_advisory_xact_lock(hashtext(current_database()), hashtext(?))');
        if ($statement === false) {
            throw new RuntimeException('Could not prepare operational configuration lock.');
        }
        $statement->execute([$this->config->schema]);
    }

    /**
     * Qualify the table using the schema identifier validated by OperationsConfig.
     */
    private function table(): string
    {
        return '"' . $this->config->schema . '".configurations';
    }

    /**
     * Decode component settings without exposing connection settings in public records.
     *
     * @param array<string, mixed> $row
     * @return Configuration
     */
    private function decode(array $row): array
    {
        if (! is_string($row['id'] ?? null) || ! is_string($row['kind'] ?? null) || ! is_string($row['component'] ?? null) || ! is_string($row['settings'] ?? null)) {
            throw new RuntimeException('Invalid stored operational configuration.');
        }
        $settings = json_decode($row['settings'], true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($settings)) {
            throw new RuntimeException('Stored component settings must be a JSON object.');
        }
        /** @var array<string, mixed> $settings */
        return [
            'id' => $row['id'],
            'kind' => $row['kind'],
            'component' => $row['component'],
            'settings' => $settings,
        ];
    }
}
