<?php

declare(strict_types=1);

namespace Tests\Support\Integration;

use PDO;
use PHPUnit\Framework\Assert;

use function finish_configuration_cli;

/**
 * Observe real PostgreSQL and process boundaries without faking application outcomes.
 *
 * Locks and delivery backdating are disposable fixture controls. The production
 * worker still owns publication, result acceptance and restart/reconciliation.
 */
final class Pr4Recovery
{
    /**
     * Wait for the worker's actual PostgreSQL backend to block on a test lock.
     *
     * @param array{process: resource, pipes: array} $child
     */
    public static function awaitLock(PDO $database, array $child, int $class, int $object, int $seconds = 20): int
    {
        $statement = $database->prepare(<<<'SQL'
        SELECT pid
        FROM pg_locks
        WHERE locktype = 'advisory'
          AND classid = ?
          AND objid = ?
          AND NOT granted
          AND database = (SELECT oid FROM pg_database WHERE datname = current_database())
        SQL);

        $deadline = microtime(true) + $seconds;
        do {
            $statement->execute([$class, $object]);
            $pid = $statement->fetchColumn();
            if ($pid !== false) {
                return (int) $pid;
            }

            if (! proc_get_status($child['process'])['running']) {
                break;
            }

            usleep(20_000);
        } while (microtime(true) < $deadline);

        Assert::fail('The real worker never reached PostgreSQL advisory barrier ' . $class . '/' . $object);
    }

    /**
     * Let a revoked worker observe its own lost session and exit, rather than
     * turning a stale-worker test into a second unconditional SIGKILL test.
     */
    public static function awaitExit(array $child, int $seconds = 10): void
    {
        $deadline = microtime(true) + $seconds;
        do {
            if (! proc_get_status($child['process'])['running']) {
                return;
            }

            usleep(20_000);
        } while (microtime(true) < $deadline);

        Assert::fail('The revoked worker did not exit after its PostgreSQL session was terminated.');
    }

    /**
     * Reap a worker even if an assertion fails while its database write is blocked.
     */
    public static function kill(array $child): array
    {
        if (proc_get_status($child['process'])['running']) {
            proc_terminate($child['process'], 9);
        }

        return finish_configuration_cli($child);
    }

    /**
     * Make the interrupted Messenger delivery eligible without modifying its
     * production 60-second visibility policy or any archival/domain state.
     */
    public static function expireFixtureDelivery(PDO $database): void
    {
        $database->exec("UPDATE configuration_proof.messenger_messages SET delivered_at = CURRENT_TIMESTAMP - INTERVAL '61 seconds' WHERE delivered_at IS NOT NULL");
    }
}
