<?php

namespace App\Integration;

use InvalidArgumentException;
use PDO;
use Tempest\Database\Config\DatabaseConfig;
use Tempest\Database\Config\DatabaseDialect;
use Tempest\Database\Migrations\DatePrefixStrategy;
use Tempest\Database\Migrations\MigrationNamingStrategy;
use Tempest\Database\Tables\NamingStrategy;
use Tempest\Database\Tables\PluralizedSnakeCaseStrategy;

/**
 * Configure the PostgreSQL operational store independently of canonical Vault storage.
 *
 * No connection is opened during application boot; archival commands need no SQL.
 */
final class OperationsConfig implements DatabaseConfig
{
    public DatabaseDialect $dialect {
        get => DatabaseDialect::POSTGRESQL;
    }
    public NamingStrategy $namingStrategy {
        get => new PluralizedSnakeCaseStrategy();
    }
    public MigrationNamingStrategy $migrationNamingStrategy {
        get => new DatePrefixStrategy();
    }
    public bool $usePersistentConnection {
        get => false;
    }
    public array $options {
        get => [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
    }
    public ?string $tag {
        get => null;
    }

    /**
     * Retain explicit connection settings and validate the private schema identifier.
     *
     * Credentials are never included in configuration command responses.
     */
    public function __construct(
        public readonly string $dsn = '',
        public readonly string $username = '',
        public readonly string $password = '',
        public readonly string $schema = 'stashd_operations',
    ) {
        if (
            ! preg_match('/^[a-z_][a-z0-9_]*$/D', $schema)
            || strlen($schema) > 63
            || str_starts_with($schema, 'pg_')
            || in_array($schema, ['public', 'information_schema'], true)
        ) {
            throw new InvalidArgumentException('Operations require a private PostgreSQL schema identifier.');
        }
    }
}
