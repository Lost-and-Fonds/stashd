<?php

namespace App\Integration;

use Closure;
use InvalidArgumentException;
use Tempest\Container\Container;
use Tempest\Container\Singleton;
use Tempest\Database\Connection\Connection;
use Tempest\Database\Database;
use Tempest\Database\Query;
use Tempest\Database\Transactions\GenericTransactionManager;
use Throwable;

/**
 * Resolve Tempest's operational database lazily within its private PostgreSQL schema.
 *
 * The framework owns connections, queries and transaction primitives. This is the
 * application's SQL connection; canonical Vault storage and projections stay separate.
 */
#[Singleton]
final class OperationsDatabase
{
    private ?Database $connection = null;

    /**
     * Retain explicit settings and the real container without connecting at boot.
     */
    public function __construct(
        private OperationsConfig $config,
        private Container $container,
    ) {}

    /**
     * Open Tempest's nonpersistent connection and confine unqualified tables to our schema.
     */
    public function connect(): Database
    {
        if ($this->connection !== null) {
            return $this->connection;
        }
        if (! str_starts_with($this->config->dsn, 'pgsql:')) {
            throw new InvalidArgumentException('Set STASHD_OPERATIONS_DSN to an explicit PostgreSQL connection before integration:init.');
        }
        if ($this->config->username === '') {
            throw new InvalidArgumentException('Set STASHD_OPERATIONS_USERNAME to an explicit application owner.');
        }
        $this->container->config(new OperationsConnectionConfig($this->config));
        $database = $this->container->get(Database::class);
        $database->execute(new Query('SET search_path TO "' . $this->config->schema . '"'));
        return $this->connection = $database;
    }

    /**
     * Use Tempest transaction primitives while preserving the callback result and error.
     *
     * Database::withinTransaction swallows Throwable and returns only a boolean;
     * retry diagnostics require the original failure. Nested calls are not supported.
     *
     * @template T
     * @param Closure(): T $work
     * @return T
     */
    public function transaction(Closure $work): mixed
    {
        $this->connect();
        $transactions = new GenericTransactionManager($this->container->get(Connection::class));
        $transactions->begin();
        try {
            $result = $work();
            $transactions->commit();
            return $result;
        } catch (Throwable $error) {
            $transactions->rollback();
            throw $error;
        }
    }
}
