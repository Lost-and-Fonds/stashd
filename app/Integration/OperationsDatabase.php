<?php

namespace App\Integration;

use Closure;
use InvalidArgumentException;
use Tempest\Container\Container;
use Tempest\Container\Singleton;
use Tempest\Database\Connection\Connection;
use Tempest\Database\Database;
use Tempest\Database\Transactions\GenericTransactionManager;
use Throwable;

/**
 * Open the application database only when needed and use its private PostgreSQL schema.
 *
 * Tempest manages the connection, queries and transactions. This database holds
 * working configuration and scan state, separate from the Vault and archive projections.
 */
#[Singleton]
final class OperationsDatabase
{
    private ?Database $connection = null;

    /**
     * Keep the settings and application container ready without connecting at startup.
     */
    public function __construct(
        private OperationsConfig $config,
        private Container $container,
    ) {}

    /**
     * Open Tempest's connection and resolve table names only within our private schema.
     *
     * The connection is not reused across processes, so session locks end on process exit.
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

        return $this->connection = $this->container->get(Database::class);
    }

    /**
     * Commit the callback's work together, or roll it all back and rethrow its error.
     *
     * Tempest's Database::withinTransaction returns only a boolean and hides the
     * error needed to explain a retry. This uses its transaction manager instead,
     * keeping the result and error. The shared DBAL owner provides nested savepoints.
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
