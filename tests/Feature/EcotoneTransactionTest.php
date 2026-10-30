<?php

declare(strict_types=1);

use App\Integration\OperationsConfig;
use App\Integration\OperationsConnectionConfig;
use App\Operations\JobRuntime;
use App\Operations\Jobs;
use App\Operations\TempestDbalConnection;
use App\Operations\Work;
use Doctrine\DBAL\DriverManager;
use Tempest\Container\GenericContainer;
use Tempest\Database\Connection\Connection;
use Tempest\Database\Database;
use Tempest\Database\DatabaseInitializer;
use Tempest\Database\Query;
use Tempest\EventBus\EventBus;
use Tempest\EventBus\EventBusConfig;
use Tempest\EventBus\GenericEventBus;
use Tempest\Reflection\ClassReflector;
use Tests\Support\Operations\LocalOperationsDriver;
use Tests\Support\RecordsAndArchives\ArchiveFixture;

it('shares the injected physical transaction with native Ecotone admission', function (): void {
    $workspace = new ArchiveFixture();
    $root = $workspace->path('operations');
    mkdir($root, 0o700);
    $driver = new LocalOperationsDriver();
    try {
        $driver->run('probe.prepare', ['workspace' => $root, 'name' => 'atomic']);
        $descriptor = json_decode(file_get_contents($root . '/installation.json'), true, flags: JSON_THROW_ON_ERROR)['descriptor'];
        $dbal = DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'host' => 'lerd-postgres',
            'dbname' => $descriptor['username'],
            'user' => $descriptor['username'],
            'password' => $descriptor['password'],
        ]);
        $config = new OperationsConfig($descriptor['dsn'], $descriptor['username'], $descriptor['password'], $descriptor['schema']);
        $runtime = new JobRuntime($config, connection: $dbal);
        $adapter = new TempestDbalConnection($dbal);
        $container = new GenericContainer();
        GenericContainer::setInstance($container);
        $container->config(new OperationsConnectionConfig($config));
        $container->singleton(Connection::class, $adapter);
        $container->singleton(EventBus::class, new GenericEventBus($container, new EventBusConfig()));
        $database = new DatabaseInitializer()->initialize(new ClassReflector(Database::class), null, $container);
        $container->singleton(Database::class, $database);
        expect($database->connection)->toBe($adapter);
        $database->execute(new Query('CREATE TABLE atomic_probe (id INT PRIMARY KEY, pid INT, tx BIGINT)'));
        $observer = LocalOperationsDriver::connect($descriptor);
        foreach (['commit', 'rollback', 'enqueue-failure', 'mutation-failure'] as $case) {
            $adapter->beginTransaction();
            $database->execute(new Query('INSERT INTO atomic_probe VALUES (1, pg_backend_pid(), txid_current())'));
            $identity = $database->fetchFirst(new Query('SELECT pid, tx FROM atomic_probe'));
            expect((int) $identity['pid'])->toBe((int) $dbal->fetchOne('SELECT pg_backend_pid()'));
            expect((string) $identity['tx'])->toBe((string) $dbal->fetchOne('SELECT txid_current()'));
            expect((int) $observer->query('SELECT count(*) FROM atomic_probe')->fetchColumn())->toBe(0);
            if ($case === 'enqueue-failure') {
                $dbal->executeStatement('ALTER TABLE enqueue ADD CONSTRAINT reject_enqueue CHECK (false)');
            }

            try {
                $id = new Jobs($runtime)->request(new Work('acceptance.probe', $case, 'refresh', 'v1'));
                if ($case === 'mutation-failure') {
                    $database->execute(new Query('INSERT INTO atomic_probe (id) VALUES (1)'));
                }

                if ($case === 'enqueue-failure') {
                    throw new LogicException('The channel insert should have failed.');
                }

                expect((int) $observer->query('SELECT count(*) FROM enqueue')->fetchColumn())->toBe(0);
                if ($case === 'commit') {
                    $adapter->commit();
                } else {
                    $adapter->rollback();
                }
            } catch (Throwable $error) {
                $adapter->rollback();
                if (! in_array($case, ['enqueue-failure', 'mutation-failure'], true)) {
                    throw $error;
                }

                while ($error->getPrevious() !== null) {
                    $error = $error->getPrevious();
                }

                expect($error->getMessage())->toContain($case === 'enqueue-failure' ? '23514' : '23505');
            }

            $expected = (int) ($case === 'commit');
            expect((int) $observer->query('SELECT count(*) FROM atomic_probe')->fetchColumn())->toBe($expected);
            expect((int) $observer->query('SELECT count(*) FROM enqueue')->fetchColumn())->toBe($expected);
            expect((int) $observer->query('SELECT count(*) FROM jobs')->fetchColumn())->toBe($expected);
            $dbal->executeStatement('DELETE FROM enqueue');
            $dbal->executeStatement('DELETE FROM jobs');
            $dbal->executeStatement('DELETE FROM atomic_probe');
        }

        $dbal->executeStatement('CREATE TABLE event_probe (kind TEXT, id UUID, fields JSONB, PRIMARY KEY (kind, id))');
        $channels = array_map(
            static fn (string $name) => \Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder::create($name)->withReceiveTimeout(1),
            ['history', 'premis', 'client'],
        );
        $system = \Ecotone\Api\Lite\EcotoneLite::bootstrap(
            classesToResolve: [\Tests\Support\Ecotone\CatalogueSubscribers::class],
            containerOrAvailableServices: [
                \Tests\Support\Ecotone\CatalogueSubscribers::class => new \Tests\Support\Ecotone\CatalogueSubscribers($dbal),
                \Ecotone\Api\Dbal\ExtensionObject\DbalConnectionReference::DEFAULT => \Ecotone\Dbal\DbalConnection::create($dbal),
            ],
            extensionObjects: [...$channels, \Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration::createWithDefaults()->withDeduplication(false)->withDeadLetter(false)],
        );
        $event = new \App\EventManagement\Events\Integrity\FixityCheckPassed('item-1', 'asset-1', 'v1', 'check-1', 'digest-1');
        $adapter->beginTransaction();
        $system->getEventBus()->publish($event);
        expect((int) $observer->query('SELECT count(*) FROM enqueue')->fetchColumn())->toBe(0);
        $adapter->rollback();
        expect((int) $observer->query('SELECT count(*) FROM enqueue')->fetchColumn())->toBe(0);
        $adapter->beginTransaction();
        $system->getEventBus()->publish($event);
        $adapter->commit();
        expect((int) $observer->query('SELECT count(*) FROM enqueue')->fetchColumn())->toBe(3);
        foreach (['history', 'premis', 'client'] as $channel) {
            $process = new \Tempest\Process\GenericProcessExecutor()->run(new \Tempest\Process\PendingProcess(
                command: [PHP_BINARY, dirname(__DIR__) . '/Support/Ecotone/event-consumer.php'],
                input: json_encode([
                    'connection' => $dbal->getParams(),
                    'schema' => $descriptor['schema'],
                    'channel' => $channel,
                    'fail' => $channel === 'client',
                ], JSON_THROW_ON_ERROR),
                timeout: \Tempest\DateTime\Duration::seconds(10),
            ));
            expect($process->exitCode)->toBe($channel === 'client' ? 1 : 0);
            if ($channel === 'client') {
                expect($process->errorOutput)->toContain('Injected client subscriber failure');
            }
        }

        expect((int) $observer->query('SELECT count(*) FROM event_probe')->fetchColumn())->toBe(2);
        expect($observer->query('SELECT DISTINCT id FROM event_probe')->fetchAll(PDO::FETCH_COLUMN))->toBe([$event->metadata->eventId]);
        // A failed client subscriber cannot undo the other subscribers or source intent.
        expect((int) $observer->query('SELECT count(*) FROM enqueue')->fetchColumn())->toBe(1);
        $dbal->executeStatement('UPDATE enqueue SET redeliver_after = 0');
        $retry = new \Tempest\Process\GenericProcessExecutor()->run(new \Tempest\Process\PendingProcess(
            command: [PHP_BINARY, dirname(__DIR__) . '/Support/Ecotone/event-consumer.php'],
            input: json_encode(['connection' => $dbal->getParams(), 'schema' => $descriptor['schema'], 'channel' => 'client', 'fail' => false], JSON_THROW_ON_ERROR),
            timeout: \Tempest\DateTime\Duration::seconds(10),
        ));
        expect($retry->exitCode)->toBe(0);
        expect((int) $observer->query('SELECT count(*) FROM event_probe')->fetchColumn())->toBe(3);
        $client = $observer->query("SELECT id, fields FROM event_probe WHERE kind = 'client'")->fetch(PDO::FETCH_ASSOC);
        expect($client['id'])->toBe($event->metadata->eventId);
        expect(json_decode($client['fields'], true, flags: JSON_THROW_ON_ERROR))->toEqual(['itemId' => 'item-1', 'checkId' => 'check-1']);
        $before = $dbal->fetchOne('SELECT pg_backend_pid()');
        $adapter->reconnect();
        expect($dbal->fetchOne('SELECT pg_backend_pid()'))->not->toBe($before);
        expect($adapter->ping())->toBeTrue();
        $adapter->beginTransaction();
        try {
            $adapter->reconnect();
            throw new RuntimeException('Reconnect must reject an active transaction.');
        } catch (LogicException $error) {
            expect($error->getMessage())->toContain('active shared transaction');
        } finally {
            $adapter->rollback();
        }
    } finally {
        GenericContainer::setInstance(null);
        $driver->run('fixture.dispose', ['workspace' => $root]);
        $workspace->dispose();
    }
});
