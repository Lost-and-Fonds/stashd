<?php

declare(strict_types=1);

use App\Integration\OperationsConfig;
use App\Integration\OperationsDatabase;
use App\Operations\ExecuteJob;
use App\Operations\JobRuntime;
use App\Operations\Jobs;
use App\Operations\SharedOperationsConnection;
use App\Operations\Work;
use App\Operations\WorkExecutor;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Tempest\Core\Tempest;
use Tempest\Database\Connection\Connection;
use Tempest\Database\Query;
use Tests\Support\Ecotone\DeliveryProbe;
use Tests\Support\Operations\LocalOperationsDriver;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

try {
    $request = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    $d = $request['descriptor'];
    $observer = LocalOperationsDriver::connect($d);
    if (isset($request['schema'])) {
        // Exercise a schema different from PostgreSQL's default $user search path.
        new OperationsConfig($d['dsn'], $d['username'], $d['password'], $request['schema']);
        $d['schema'] = $request['schema'];
        $observer->exec('SET search_path TO "' . $d['schema'] . '"');
    }

    foreach ([
        'STASHD_OPERATIONS_DSN' => $d['dsn'],
        'STASHD_OPERATIONS_USERNAME' => $d['username'],
        'STASHD_OPERATIONS_PASSWORD' => $d['password'],
        'STASHD_OPERATIONS_SCHEMA' => $d['schema'],
        'VAULT_ROOT' => $request['workspace'] . '/vault',
    ] as $key => $value) {
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
    }

    $container = Tempest::boot(root: dirname(__DIR__, 3));
    $probe = new DeliveryProbe($observer, $request['boundary'] ?? '', $request['pause']);
    $container->singleton(WorkExecutor::class, $probe);
    if (($request['boundary'] ?? '') !== '') {
        // Only the controlled before-ack case needs a test interceptor in the real system.
        $container->singleton(
            JobRuntime::class,
            new JobRuntime($container->get(OperationsConfig::class), $probe, $container->get(SharedOperationsConnection::class)->database(), [$probe]),
        );
    }

    $result = [];
    $action = $request['action'];
    if ($action === 'request') {
        $result['id'] = $container->get(Jobs::class)->request(
            new Work('acceptance.probe', $request['scope'], 'refresh', $request['observation'] ?? 'v1'),
            $request['priority'] ?? 'routine',
        );
    } elseif ($action === 'consume') {
        $container->get(JobRuntime::class)->work('jobs', true, $request['limit'] ?? null);
    } elseif ($action === 'publish') {
        $container
            ->get(JobRuntime::class)
            ->messaging()
            ->getCommandBus()
            ->send(new ExecuteJob($request['id'], $request['generation']), metadata: [\Ecotone\Api\Messaging\MessageHeaders::PRIORITY => $request['priority'] ?? 0]);
    } elseif ($action === 'replay') {
        $container->get(JobRuntime::class)->messaging()->getGatewayByName(DeadLetterGateway::class)->replayAll();
    } elseif ($action === 'bootstrap') {
        if ($request['order'] === 'jobs-first') {
            $container->get(Jobs::class);
        }

        $database = $container->get(OperationsDatabase::class)->connect();
        $container->get(Jobs::class);
        $dbal = $container->get(SharedOperationsConnection::class)->database();
        $connection = $container->get(Connection::class);
        $database->execute(new Query('CREATE TABLE IF NOT EXISTS bootstrap_probe (id INT PRIMARY KEY, pid INT, tx BIGINT)'));
        $connection->reconnect();
        $dbal->close();
        $result['reconnected_same_pid'] = (int) $database->fetchFirst(new Query('SELECT pg_backend_pid() AS pid'))['pid'] === (int) $dbal->fetchOne('SELECT pg_backend_pid()');
        $connection->beginTransaction();
        try {
            $database->execute(new Query('INSERT INTO bootstrap_probe VALUES (1, pg_backend_pid(), txid_current())'));
            $row = $database->fetchFirst(new Query('SELECT * FROM bootstrap_probe'));
            $result['shared'] = (int) $row['pid'] === (int) $dbal->fetchOne('SELECT pg_backend_pid()') && (string) $row['tx'] === (string) $dbal->fetchOne('SELECT txid_current()');
            $result['hidden'] = (int) $observer->query('SELECT count(*) FROM bootstrap_probe')->fetchColumn() === 0;
            $container->get(Jobs::class)->request(new Work('acceptance.probe', $request['scope'], 'refresh', 'bootstrap'));
            $result['enqueue_hidden'] = (int) $observer->query('SELECT count(*) FROM enqueue')->fetchColumn() === 0;
            try {
                $dbal->close();
                throw new RuntimeException('Native close was incorrectly allowed.');
            } catch (LogicException) {
                $result['native_close_rejected'] = true;
            }

            try {
                $connection->reconnect();
                throw new RuntimeException('Reconnect was incorrectly allowed.');
            } catch (LogicException) {
                $result['reconnect_rejected'] = true;
            }

            if (($request['fault'] ?? '') === 'state') {
                $database->execute(new Query('INSERT INTO bootstrap_probe (id) VALUES (1)'));
            }

            if ($request['commit'] ?? false) {
                $connection->commit();
            } else {
                $connection->rollback();
            }
        } catch (Throwable $error) {
            $connection->rollback();
            if (($request['fault'] ?? '') !== 'state') {
                throw $error;
            }

            while ($error->getPrevious()) {
                $error = $error->getPrevious();
            }

            $result['state_fault'] = str_contains($error->getMessage(), '23505');
        }
    } else {
        throw new RuntimeException('Unknown native delivery process action.');
    }

    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    while ($error->getPrevious() !== null) {
        $error = $error->getPrevious();
    }

    fwrite(STDERR, $error::class . ': ' . $error->getMessage());
    exit(1);
}
