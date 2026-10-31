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
 * Give Tempest the operator's settings and establish the private schema on every connection.
 *
 * Register this only when database commands need it; Vault-only commands do not connect.
 */
final class OperationsConnectionConfig implements DatabaseConfig
{
    public string $dsn {
        get => $this->operations->dsn === '' ? '' : $this->operations->dsn . ';options=--search_path=' . $this->operations->schema;
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
     * Keep settings lazy and reject conflicting startup options that could change schema.
     */
    public function __construct(
        private readonly OperationsConfig $operations,
    ) {
        if (preg_match('/(?:^|;)\s*options\s*=/i', $operations->dsn)) {
            throw new \InvalidArgumentException('Use STASHD_OPERATIONS_SCHEMA for PostgreSQL startup search_path; DSN options are reserved.');
        }
    }
}
