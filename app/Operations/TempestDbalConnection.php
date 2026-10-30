<?php

declare(strict_types=1);

namespace App\Operations;

use Doctrine\DBAL\Connection as Dbal;
use PDO;
use PDOStatement;
use Tempest\Database\Connection\Connection;
use Throwable;

/**
 * Let Tempest use Ecotone's connection without reading private PDO properties.
 *
 * Requires Tempest PR 2326. DBAL owns transactions and reconnects; install this
 * singleton before resolving Database. Never reconnect during a transaction.
 */
final readonly class TempestDbalConnection implements Connection
{
    /**
     * Retain the same DBAL object supplied to Ecotone's connection factory.
     */
    public function __construct(
        private Dbal $database,
    ) {}

    /**
     * Start a transaction through its single owner, DBAL.
     */
    public function beginTransaction(): bool
    {
        $this->database->beginTransaction();

        return true;
    }

    /**
     * Report the physical connection's transaction state.
     */
    public function inTransaction(): bool
    {
        return $this->pdo()->inTransaction();
    }

    /**
     * Commit through DBAL so its nesting counter stays consistent.
     */
    public function commit(): bool
    {
        $this->database->commit();

        return true;
    }

    /**
     * Roll back through DBAL, including nested savepoints.
     */
    public function rollback(): bool
    {
        $this->database->rollBack();

        return true;
    }

    /**
     * Return the last generated identifier for Tempest's insert helpers.
     */
    public function lastInsertId(): false|string
    {
        return $this->pdo()->lastInsertId();
    }

    /**
     * Prepare on the currently owned PDO; no PDO handle is cached here.
     */
    public function prepare(string $sql): PDOStatement
    {
        return $this->pdo()->prepare($sql) ?: throw new \RuntimeException('Could not prepare a shared database statement.');
    }

    /**
     * Refuse to silently lose an active transaction during connection closure.
     */
    public function close(): void
    {
        if ($this->inTransaction()) {
            throw new \LogicException('Cannot close an active shared transaction.');
        }

        $this->database->close();
    }

    /**
     * Resolve DBAL's lazy physical connection.
     */
    public function connect(): void
    {
        $this->pdo();
    }

    /**
     * Replace the physical connection only outside a transaction.
     */
    public function reconnect(): void
    {
        $this->close();
        $this->connect();
    }

    /**
     * Probe connectivity without concealing errors from normal queries.
     */
    public function ping(): bool
    {
        try {
            return $this->database->fetchOne('SELECT 1') !== false;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Use DBAL's supported native-connection API and enforce the PDO driver.
     */
    private function pdo(): PDO
    {
        $pdo = $this->database->getNativeConnection();
        if (! $pdo instanceof PDO) {
            throw new \LogicException('The shared Tempest connection requires a PDO DBAL driver.');
        }

        return $pdo;
    }
}
