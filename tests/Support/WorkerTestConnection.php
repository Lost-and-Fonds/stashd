<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use Tempest\Database\Connection\Connection;

final class WorkerTestConnection implements Connection
{
    public bool $transaction = false;

    public function beginTransaction(): bool
    {
        return $this->transaction = true;
    }

    public function inTransaction(): bool
    {
        return $this->transaction;
    }

    public function commit(): bool
    {
        return $this->transaction = false;
    }

    public function rollback(): bool
    {
        return $this->transaction = false;
    }

    public function lastInsertId(): false|string
    {
        return false;
    }

    public function prepare(string $sql): \PDOStatement
    {
        throw new RuntimeException('unused');
    }

    public function close(): void {}

    public function connect(): void {}

    public function reconnect(): void {}

    public function ping(): bool
    {
        return true;
    }
}
