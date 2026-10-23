<?php

namespace App\Integration;

use App\Administration\VaultConfig;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Uid\Uuid;
use Tempest\Database\Database;
use Tempest\Database\Migrations\CreateMigrationsTable;
use Tempest\Database\Migrations\MigrationManager;
use Tempest\Database\Query;

use function Tempest\Database\query;

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
        private OperationsDatabase $database,
        private MigrationManager $migrations,
    ) {}

    /**
     * Bootstrap operational configuration and discovery tables, preserving rows.
     *
     * The operator must provision the database and grant schema creation first.
     * No archive projection tables are created, replaced or read.
     */
    public function initialize(): void
    {
        $database = $this->database->connect();
        $this->database->transaction(function () use ($database): void {
            $this->lock($database);
            $database->execute(new Query('CREATE SCHEMA IF NOT EXISTS "' . $this->config->schema . '"'));
            // Avoid MigrationManager's missing-table probe aborting this PostgreSQL transaction.
            $catalog = $database->fetchFirst(new Query("SELECT to_regclass('migrations') AS name"));
            if (($catalog['name'] ?? null) === null) {
                $this->migrations->executeUp(new CreateMigrationsTable());
            }
            $this->migrations->up();
        });
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
        $database = $this->database->connect();
        return $this->database->transaction(function () use ($database, $kind, $component, $root): array {
            $this->lock($database);
            $root = $this->roots->canonical($root, allowMissing: $kind === 'broadcast');
            $vault = $this->roots->canonical($this->vault->root, allowMissing: true);
            $this->roots->requireSeparate($root, $vault);
            $existing = null;
            foreach ($database->fetch(query('configurations')->select('id', 'kind', 'component', 'settings')) as $row) {
                $record = $this->decode($row);
                if ($record['component'] !== 'filesystem') {
                    continue;
                }
                $otherRoot = $record['settings']['root'] ?? null;
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
                $database->execute(query('configurations')->insert(
                    id: $record['id'],
                    kind: $kind,
                    component: $component,
                    settings: json_encode($record['settings'], JSON_THROW_ON_ERROR),
                ));
            }
            return $record;
        });
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
        $row = $this->database->connect()->fetchFirst(query('configurations')->select('id', 'kind', 'component', 'settings')->where('id', $id)->where('kind', $kind));
        if ($row === null) {
            throw new InvalidArgumentException('Configuration not found for the requested lifecycle.');
        }
        return $this->decode($row);
    }

    /**
     * Read configured components so Filesystem can recheck all roots before execution.
     *
     * @return list<Configuration>
     */
    public function all(): array
    {
        $records = [];
        foreach ($this->database->connect()->fetch(query('configurations')->select('id', 'kind', 'component', 'settings')) as $row) {
            $records[] = $this->decode($row);
        }
        return $records;
    }

    /**
     * Serialize configuration checks and insertion within this database and schema.
     */
    private function lock(Database $database): void
    {
        $database->execute(new Query('SELECT pg_advisory_xact_lock(hashtext(current_database()), hashtext(?))', [$this->config->schema]));
    }

    /**
     * Decode component settings without exposing connection settings in public records.
     *
     * @param array $row
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
