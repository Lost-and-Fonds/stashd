<?php

declare(strict_types=1);

namespace App\System\Boot;

use App\Config\StashdConfig;
use RuntimeException;
use Tempest\Database\Config\DatabaseConfig;
use Tempest\Database\Config\SQLiteConfig;
use Tempest\Database\Database;
use Tempest\Database\Exceptions\QueryWasInvalid;
use Tempest\Database\Migrations\Migration;
use Tempest\Database\Migrations\MigrationManager;
use Tempest\Database\Migrations\RunnableMigrations;

use function Tempest\Support\Filesystem\create_directory;

use Tempest\Support\Filesystem\Exceptions\RuntimeException as FilesystemException;

final readonly class MigrationRunner
{
    public function __construct(
        private StashdConfig $config,
        private MigrationManager $migrations,
        private RunnableMigrations $runnableMigrations,
        private Database $database,
    ) {
    }

    public function run(DatabaseConfig $databaseConfig): void
    {
        if ($databaseConfig instanceof SQLiteConfig && $databaseConfig->path !== ':memory:' && $this->hasPendingMigrations()) {
            $this->backupIfExists($databaseConfig->path);
        }

        $this->migrations->up();
    }

    public function hasPendingMigrations(): bool
    {
        $applied = $this->appliedMigrationNames();

        foreach ($this->runnableMigrations->up() as $migration) {
            if (! in_array($migration->name, $applied, strict: true)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function appliedMigrationNames(): array
    {
        try {
            return array_map(
                static fn (Migration $migration): string => $migration->name,
                Migration::all(),
            );
        } catch (QueryWasInvalid $exception) {
            if ($this->database->dialect->isTableNotFoundError($exception)) {
                return [];
            }

            throw $exception;
        }
    }

    private function backupIfExists(string $databasePath): void
    {
        if (! is_file($databasePath)) {
            return;
        }

        $backupDir = $this->config->backupsPath();
        try {
            create_directory($backupDir, 0o775);
        } catch (FilesystemException) {
            throw new RuntimeException("Stashd cannot create backup directory: {$backupDir}");
        }

        $timestamp = gmdate('Y-m-d-His');
        $destination = rtrim($backupDir, '/') . "/stashd-before-migration-{$timestamp}.sqlite";

        if (! copy($databasePath, $destination)) {
            throw new RuntimeException("Stashd failed to back up database before migration: {$databasePath}");
        }

        $this->pruneOldBackups($backupDir, keep: 5);
    }

    private function pruneOldBackups(string $directory, int $keep): void
    {
        $files = glob(rtrim($directory, '/') . '/stashd-before-migration-*.sqlite') ?: [];
        rsort($files);

        foreach (array_slice($files, $keep) as $stale) {
            @unlink($stale);
        }
    }
}
