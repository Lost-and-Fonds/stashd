<?php

declare(strict_types=1);

namespace App\Operations;

use App\Integration\OperationsConfig;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use InvalidArgumentException;
use Tempest\Container\Singleton;

/**
 * Own one lazy physical connection for Tempest persistence and native delivery.
 * No PDO or connection is shared between processes. Vault commands remain lazy.
 */
#[Singleton]
final class SharedOperationsConnection
{
    private ?Connection $connection = null;

    /**
     * Retain explicit database settings without connecting at application boot.
     */
    public function __construct(
        private OperationsConfig $config,
    ) {}

    /**
     * Resolve the sole DBAL owner and establish its private schema search path.
     */
    public function database(): Connection
    {
        if ($this->connection !== null) {
            return $this->connection;
        }

        if (! str_starts_with($this->config->dsn, 'pgsql:') || $this->config->username === '') {
            throw new InvalidArgumentException('Explicit PostgreSQL operations credentials are required.');
        }

        $parts = [];
        foreach (explode(';', substr($this->config->dsn, 6)) as $part) {
            if ($part === '') {
                continue;
            }

            $pair = explode('=', $part, 2);
            if (count($pair) !== 2 || ! in_array($pair[0], ['host', 'port', 'dbname', 'sslmode', 'sslrootcert', 'sslcert', 'sslkey'], true)) {
                throw new InvalidArgumentException('Unsupported PostgreSQL operations DSN.');
            }

            $parts[$pair[0]] = $pair[1];
        }

        /** @var SharedPostgresConnection $database */
        $database = DriverManager::getConnection([
            ...$parts,
            'wrapperClass' => SharedPostgresConnection::class,
            'driver' => 'pdo_pgsql',
            'user' => $this->config->username,
            'password' => $this->config->password,
        ]);

        $database->useSchema($this->config->schema);

        return $this->connection = $database;
    }
}
