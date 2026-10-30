<?php

declare(strict_types=1);

namespace App\Operations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use LogicException;

/**
 * Preserve the private schema on physical reconnect and forbid active-transaction loss.
 * Registered through DBAL's supported wrapperClass option, shared by both frameworks.
 */
final class SharedPostgresConnection extends Connection
{
    private string $schema;

    /**
     * Set the validated private schema before the first physical connection opens.
     */
    public function useSchema(string $schema): void
    {
        if ($this->isConnected()) {
            throw new LogicException('Configure the schema before opening the shared connection.');
        }

        $this->schema = $schema;
    }

    /**
     * Initialize every newly opened physical session, including native consumer reconnects.
     */
    protected function connect(): DriverConnection
    {
        $connected = $this->isConnected();
        $driver = parent::connect();
        if (! $connected) {
            // SharedOperationsConnection validates this schema identifier before construction.
            $driver->exec('SET search_path TO "' . $this->schema . '"');
        }

        return $driver;
    }

    /**
     * Refuse connection replacement while either Tempest or Ecotone owns a transaction.
     */
    public function close(): void
    {
        if ($this->isTransactionActive()) {
            throw new LogicException('Cannot close an active shared transaction.');
        }

        parent::close();
    }
}
