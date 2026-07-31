<?php

declare(strict_types=1);

namespace App\System\Boot;

use RuntimeException;
use Tempest\Database\Config\DatabaseConfig;
use Tempest\Database\Config\SQLiteConfig;
use Tempest\Database\Database;
use Tempest\Database\Query;

use function Tempest\Support\Filesystem\create_directory;

use Tempest\Support\Filesystem\Exceptions\RuntimeException as FilesystemException;

/**
 * Applies SQLite pragmas on Tempest's active database connection.
 *
 * Do not open a separate PDO here: a throwaway connection (especially with
 * :memory:) would not affect the connection used by migrations and models.
 */
final readonly class SqliteConfigurator
{
    public function __construct(
        private Database $database,
    ) {
    }

    public function configure(DatabaseConfig $databaseConfig): void
    {
        if (! $databaseConfig instanceof SQLiteConfig) {
            return;
        }

        if ($databaseConfig->path !== ':memory:') {
            $directory = dirname($databaseConfig->path);
            try {
                create_directory($directory, 0o775);
            } catch (FilesystemException) {
                throw new RuntimeException("Stashd cannot create database directory: {$directory}");
            }
        }

        $this->database->execute(new Query('PRAGMA foreign_keys = ON'));
        $this->database->execute(new Query('PRAGMA busy_timeout = 5000'));
    }

    /** WAL is a persistent database mode, not a per-connection setting. */
    public function enableWriteAheadLogging(DatabaseConfig $databaseConfig): void
    {
        if (! $databaseConfig instanceof SQLiteConfig) {
            return;
        }

        $this->database->fetchFirst(new Query('PRAGMA journal_mode = WAL'));
    }

    /** @return array{foreign_keys: int|string, journal_mode: string, busy_timeout: int|string} */
    public function readPragmas(): array
    {
        return [
            'foreign_keys' => $this->fetchPragma('foreign_keys'),
            'journal_mode' => strtolower((string) $this->fetchPragma('journal_mode')),
            'busy_timeout' => $this->fetchPragma('busy_timeout'),
        ];
    }

    private function fetchPragma(string $name): int|string
    {
        $row = $this->database->fetchFirst(new Query("PRAGMA {$name}"));

        if (! is_array($row)) {
            return '';
        }

        return array_values($row)[0] ?? '';
    }
}
