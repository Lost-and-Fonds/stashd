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
 * Save Input and Broadcast settings in a separate database schema.
 *
 * Filesystem is the only integration available here so far. Saving settings never
 * creates source, destination or Vault contents. Database locks make callers check
 * and save one configuration at a time, preventing conflicting roots.
 *
 * @phpstan-type Configuration array{id: string, kind: string, component: string, settings: array<string, mixed>}
 */
final readonly class IntegrationConfigurations
{
    /**
     * Keep connection settings and path checks ready without opening the database.
     */
    public function __construct(
        private OperationsConfig $config,
        private VaultConfig $vault,
        private FilesystemRoots $roots,
        private OperationsDatabase $database,
        private MigrationManager $migrations,
    ) {}

    /**
     * Create or upgrade configuration and discovery tables without replacing existing rows.
     *
     * The operator must create the database and allow schema creation first.
     * This does not read, create or replace archive projection tables.
     */
    public function initialize(): void
    {
        $database = $this->database->connect();
        $this->database->transaction(function () use ($database): void {
            $this->lock($database);
            $database->execute(new Query('CREATE SCHEMA IF NOT EXISTS "' . $this->config->schema . '"'));
            // Create the migration ledger first; probing a missing table would abort this transaction.
            $catalog = $database->fetchFirst(new Query("SELECT to_regclass('migrations') AS name"));
            if (($catalog['name'] ?? null) === null) {
                $this->migrations->executeUp(new CreateMigrationsTable());
            }
            $this->migrations->up();
        });
    }

    /**
     * Check all directory roots and save a Filesystem Input or Broadcast under one lock.
     *
     * Repeating the same configuration returns its existing ID. Reject equal or
     * nested roots, including conflicts with a changed Vault path, before saving.
     * The root belongs in Filesystem settings, not in the generic table columns.
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
     * Read saved settings even when the source directory is unavailable.
     *
     * Reading settings does not establish that a path is still safe to use.
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
     * Read all saved settings so Filesystem can check for conflicting roots before use.
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
     * Allow one caller at a time to check and save configuration in this schema.
     */
    private function lock(Database $database): void
    {
        $database->execute(new Query('SELECT pg_advisory_xact_lock(hashtext(current_database()), hashtext(?))', [$this->config->schema]));
    }

    /**
     * Decode saved integration settings without including database credentials in the result.
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
