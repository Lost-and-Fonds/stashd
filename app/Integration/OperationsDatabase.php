<?php

namespace App\Integration;

use InvalidArgumentException;
use PDO;

/**
 * Open explicit operational connections for configuration and discovery services.
 *
 * Connections are owned by callers; construction opens no SQL or Vault storage.
 */
final readonly class OperationsDatabase
{
    /**
     * Retain the same connection configuration used by operational schema bootstrap.
     */
    public function __construct(
        private OperationsConfig $config,
    ) {}

    /**
     * Open PostgreSQL in exception mode without assuming a database or login identity.
     */
    public function connect(): PDO
    {
        if (! str_starts_with($this->config->dsn, 'pgsql:')) {
            throw new InvalidArgumentException('Set STASHD_OPERATIONS_DSN to an explicit PostgreSQL connection before integration:init.');
        }
        if ($this->config->username === '') {
            throw new InvalidArgumentException('Set STASHD_OPERATIONS_USERNAME to an explicit application owner.');
        }
        return new PDO($this->config->dsn, $this->config->username, $this->config->password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
}
