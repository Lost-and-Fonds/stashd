<?php

declare(strict_types=1);

namespace App\Operations\Persistence;

use Doctrine\DBAL\Connection as DbalConnection;
use LogicException;
use PDO;
use PDOStatement;
use RuntimeException;
use Tempest\Database\Connection\Connection;

/**
 * Let Tempest models participate in the transaction owned by Messenger's DBAL handle.
 *
 * Upstream Tempest PR 2326 preserves this binding. Transaction nesting stays in
 * DBAL, so Tempest does not secretly commit the outbox's transaction through PDO.
 */
final readonly class ApplicationConnection implements Connection
{
    /**
     * Keep one application handle; Durable's journal never uses this adapter.
     */
    public function __construct(
        public DbalConnection $database,
    ) {}

    /**
     * Open a DBAL-owned transaction or nested savepoint.
     */
    public function beginTransaction(): bool
    {
        $this->database->beginTransaction();

        return true;
    }

    /**
     * Observe the shared transaction owner.
     */
    public function inTransaction(): bool
    {
        return $this->database->isTransactionActive();
    }

    /**
     * Commit through DBAL so its nesting state remains consistent.
     */
    public function commit(): bool
    {
        $this->database->commit();

        return true;
    }

    /**
     * Roll back through the same owner that opened the transaction.
     */
    public function rollback(): bool
    {
        $this->database->rollBack();

        return true;
    }

    /**
     * Return the application's most recently generated identifier.
     */
    public function lastInsertId(): false|string
    {
        return (string) $this->database->lastInsertId();
    }

    /**
     * Prepare Tempest's PDO statement on the very same underlying session.
     */
    public function prepare(string $sql): PDOStatement
    {
        return $this->native()->prepare($sql) ?: throw new RuntimeException('Unable to prepare application query.');
    }

    /**
     * Close the application handle at the host's lifecycle boundary.
     */
    public function close(): void
    {
        $this->database->close();
    }

    /**
     * Resolve DBAL's lazy PDO connection without replacing it.
     */
    public function connect(): void
    {
        $this->native();
    }

    /**
     * Reopen only after all transaction ownership has ended.
     */
    public function reconnect(): void
    {
        if ($this->inTransaction()) {
            throw new LogicException('Cannot reconnect during an application transaction.');
        }

        $this->close();
        $this->connect();
    }

    /**
     * Check connectivity without granting execution authority.
     */
    public function ping(): bool
    {
        return $this->database->fetchOne('SELECT 1') !== false;
    }

    /**
     * Require the PDO driver used by Tempest and PostgreSQL transport.
     */
    private function native(): PDO
    {
        $native = $this->database->getNativeConnection();

        return $native instanceof PDO ? $native : throw new LogicException('Operations requires pdo_pgsql.');
    }
}
