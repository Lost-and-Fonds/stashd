<?php

namespace App\Integration;

use InvalidArgumentException;

/**
 * Configure the PostgreSQL operational store independently of canonical Vault storage.
 *
 * No connection is opened during application boot; archival commands need no SQL.
 */
final readonly class OperationsConfig
{
    /**
     * Retain explicit connection settings and validate the private schema identifier.
     *
     * Credentials are never included in configuration command responses.
     */
    public function __construct(
        public string $dsn = '',
        public string $username = '',
        public string $password = '',
        public string $schema = 'stashd_operations',
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
