<?php

namespace App\Integration;

use PDO;
use Tempest\Database\Config\DatabaseConfig;
use Tempest\Database\Config\DatabaseDialect;
use Tempest\Database\Migrations\DatePrefixStrategy;
use Tempest\Database\Migrations\MigrationNamingStrategy;
use Tempest\Database\Tables\NamingStrategy;
use Tempest\Database\Tables\PluralizedSnakeCaseStrategy;

/**
 * Adapt the existing explicit DSN to Tempest without parsing away PostgreSQL options.
 *
 * Registered lazily by operational commands; Vault-only commands need no database.
 */
final class OperationsConnectionConfig implements DatabaseConfig
{
    public string $dsn {
        get => $this->operations->dsn;
    }
    public ?string $username {
        get => $this->operations->username;
    }
    public ?string $password {
        get => $this->operations->password;
    }
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
     * Retain operator-supplied connection settings without opening SQL storage.
     */
    public function __construct(
        private readonly OperationsConfig $operations,
    ) {}
}
