<?php

declare(strict_types=1);

namespace Tests\Support\Integration;

use App\Identity\Identifier;
use App\Operations\Assignment;
use App\RecordsAndArchives\AssetArchive;
use App\RecordsAndArchives\ItemIdentity;
use App\RecordsAndArchives\Storage\Providers\LocalArchiveStorage;
use App\RecordsAndArchives\Vault;
use PDO;
use PHPUnit\Framework\Assert;
use Tempest\Database\Builder\QueryBuilders\BuildsQuery;
use Tempest\Database\Config\DatabaseDialect;
use Tempest\Storage\Config\LocalStorageConfig;
use Tempest\Storage\GenericStorage;
use Tests\Support\RecordsAndArchives\ArchiveFixture;
use Tests\Support\RecordsAndArchives\TransactionProbe;

use function finish_configuration_cli;
use function Tempest\Database\query;

/**
 * Observe real PostgreSQL and process boundaries without faking application outcomes.
 *
 * Locks and delivery backdating are disposable fixture controls. The production
 * worker still owns publication, result acceptance and restart/reconciliation.
 */
final class Pr4Recovery
{
    /**
     * Execute a framework-built read on the fixture's independent observer session.
     * Never bind the parent test container to a worker's publication connection.
     */
    public static function rows(PDO $database, BuildsQuery $builder): array
    {
        $query = $builder->build();
        $database->exec('SET search_path TO configuration_proof');
        // Query::compile normally translates builder quotes through the bound container.
        $statement = $database->prepare(str_replace('`', '"', $query->sql->compile(DatabaseDialect::POSTGRESQL)));
        $statement->execute($query->bindings);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Read only accepted coverage for one public Assignment, checking its namespace.
     */
    public static function results(PDO $database, string $assignment): array
    {
        return self::rows($database, query('operation_results')->select()->where('assignment_id', Identifier::parse($assignment)->uuidFor(Assignment::class)));
    }

    /**
     * Observe actual handler episodes without changing their authority tokens.
     */
    public static function attempts(PDO $database, string $assignment): array
    {
        return self::rows(
            $database,
            query('operation_attempts')->select()->where('assignment_id', Identifier::parse($assignment)->uuidFor(Assignment::class))->orderBy('started_at'),
        );
    }

    /**
     * Require a successor to replace the living worker's actual current Attempt.
     */
    public static function awaitSuccessor(PDO $database, array $child, string $assignment, string $old): array
    {
        $deadline = microtime(true) + 20;
        do {
            $attempts = self::attempts($database, $assignment);
            if (count($attempts) === 2 && $attempts[1]['uuid'] !== $old) {
                $demands = self::rows($database, query('operation_demands')->select()->where('assignment_id', Identifier::parse($assignment)->uuidFor(Assignment::class)));
                Assert::assertCount(1, $demands);
                Assert::assertSame($attempts[1]['uuid'], $demands[0]['current_attempt']);

                return $attempts[1];
            }

            if (! proc_get_status($child['process'])['running']) {
                break;
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);

        Assert::fail('The real successor did not replace the active Attempt.');
    }

    /**
     * Derive the production Asset identity without preserving any fixture bytes.
     */
    public static function assetId(ArchiveFixture $archive, string $uri): string
    {
        $config = new LocalStorageConfig(path: $archive->path('vault'), tag: 'vault');
        $vault = new Vault(new LocalArchiveStorage(new GenericStorage($config), $config));

        return new AssetArchive($vault, new ItemIdentity($vault))
            ->objectId($uri, 'original')
            ->toString();
    }

    /**
     * Wait for physical evidence while requiring the supervised worker to remain alive.
     * A timeout is a harness failure, never proof that the crash boundary was reached.
     */
    public static function awaitFile(string $path, array $child, int $seconds = 20): void
    {
        $deadline = microtime(true) + $seconds;
        do {
            clearstatcache(true, $path);
            if (is_file($path)) {
                Assert::assertTrue(proc_get_status($child['process'])['running'], 'Evidence must be observed while its worker is alive.');

                return;
            }

            if (! proc_get_status($child['process'])['running']) {
                break;
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);

        Assert::fail('The live worker did not publish expected evidence: ' . $path);
    }

    /**
     * Observe the Linux worker blocked on the Object flock held by this fixture.
     * A plan file alone can appear before Asset publication has finished.
     */
    public static function awaitObjectLock(array $child, int $seconds = 20): void
    {
        $deadline = microtime(true) + $seconds;
        do {
            $status = proc_get_status($child['process']);
            if (! $status['running']) {
                break;
            }

            $wait = file_get_contents('/proc/' . $status['pid'] . '/wchan');
            if (is_string($wait) && str_contains($wait, 'locks_lock_inode_wait')) {
                return;
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);

        Assert::fail('The real worker never blocked on the fixture-held Object lock.');
    }

    /**
     * Verify the immutable plan envelope independently before using its exact pins.
     */
    public static function evidence(string $path): array
    {
        $envelope = TransactionProbe::json($path);
        Assert::assertSame($envelope['sha512'], hash('sha512', $envelope['json']));

        return json_decode($envelope['json'], true, flags: JSON_THROW_ON_ERROR);
    }

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
     * Return the first exit status because later proc_get_status calls lose the signal.
     */
    public static function awaitExit(array $child, int $seconds = 10): array
    {
        $deadline = microtime(true) + $seconds;
        do {
            $status = proc_get_status($child['process']);
            if (! $status['running']) {
                return $status;
            }

            usleep(20_000);
        } while (microtime(true) < $deadline);

        Assert::fail('The supervised worker did not exit before its deadline.');
    }

    /**
     * Reap a worker even if an assertion fails while its database write is blocked.
     */
    public static function kill(array $child): array
    {
        try {
            if (proc_get_status($child['process'])['running']) {
                Assert::assertTrue(proc_terminate($child['process'], 9));
                $status = self::awaitExit($child);
                Assert::assertSame(9, $status['termsig'], 'The supervised worker must actually die from SIGKILL.');
            }
        } finally {
            $result = finish_configuration_cli($child);
        }

        return $result;
    }

    /**
     * Make the interrupted Messenger delivery eligible without modifying its
     * production 60-second visibility policy or any archival/domain state.
     * Truncate first so PostgreSQL's timestamp rounding cannot erase the extra second.
     */
    public static function expireFixtureDelivery(PDO $database): void
    {
        $database->exec(
            "UPDATE configuration_proof.messenger_messages SET delivered_at = date_trunc('second', CURRENT_TIMESTAMP) - INTERVAL '61 seconds' WHERE delivered_at IS NOT NULL",
        );
    }
}
