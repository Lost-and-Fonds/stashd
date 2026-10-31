<?php

declare(strict_types=1);

use App\Integration\IntegrationConfigurations;
use App\Integration\OperationsDatabase;
use App\Operations\DeliverySchema;
use App\Operations\Jobs;
use App\Operations\SharedOperationsConnection;
use App\Operations\Work;
use Tempest\Core\Tempest;
use Tempest\Database\Connection\Connection;
use Tempest\Database\Query;
use Tests\Support\Operations\LocalOperationsDriver;

$request = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$injected = str_starts_with($request['action'], 'injected-');
$official = $request['action'] === 'official-nested' || $injected;
$root = dirname(__DIR__, 3);
if ($official) {
    $loader = require $root . '/tmp/official-tempest-vendor/autoload.php';
    $loader->addPsr4('Tests\\', $root . '/tests/');
    $root .= '/tools/evaluations/official-tempest';
    chdir($root);
} else {
    require $root . '/vendor/autoload.php';
}

try {
    if ($request['without_messenger'] ?? false) {
        // Prove the fresh lifecycle cannot construct or autoload a Messenger service.
        spl_autoload_register(
            static function (string $class): void {
                if (str_starts_with($class, 'Symfony\\Component\\Messenger\\')) {
                    throw new LogicException('Messenger dependency requested in the clean-install contract: ' . $class);
                }
            },
            true,
            true,
        );
    }

    $d = $request['descriptor'];
    $observer = LocalOperationsDriver::connect($d);
    if ($injected && ($request['private_schema'] ?? false)) {
        $d['schema'] .= '_injected';
        $observer->exec('CREATE SCHEMA IF NOT EXISTS "' . $d['schema'] . '"');
        $observer->exec('SET search_path TO "' . $d['schema'] . '"');
        $d['dsn'] .= ';options=--search_path=' . $d['schema'];
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

    putenv('ECOTONE_INJECTED_CONNECTION=' . ($injected ? '1' : '0'));
    $container = Tempest::boot(root: $root);
    if ($request['action'] === 'injected-lifecycle' && $request['order'] === 'messaging-first') {
        $container->get(\Ecotone\Api\Messaging\ConfiguredMessagingSystem::class);
    }

    $database = $container->get(OperationsDatabase::class)->connect();
    if ($request['action'] === 'install') {
        if (! ($request['provision_only'] ?? false)) {
            $container->get(IntegrationConfigurations::class)->initialize();
        }

        $container->get(DeliverySchema::class)->provision();
        if (! ($request['provision_only'] ?? false)) {
            $observer->exec('CREATE TABLE probe_scopes (id UUID PRIMARY KEY, name TEXT NOT NULL)');
            $observer->exec(
                'CREATE TABLE probe_effects (sequence BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY, scope_id UUID REFERENCES probe_scopes(id), mode TEXT, job_id UUID, observation TEXT)',
            );
            $seed = $observer->prepare('INSERT INTO probe_scopes VALUES (?, ?)');
            $seed->execute([$request['scope'], 'fresh-installation']);
        }

        echo '{}';
        exit(0);
    }

    $diagnostic = null;
    if ($official) {
        $system = $container->get(\Ecotone\Api\Messaging\ConfiguredMessagingSystem::class);
        $system->getServiceFromContainer(\Ecotone\Api\Dbal\ExtensionObject\DatabaseSetupManager::class)->initialize('message_queue');
        $dbal = $system
            ->getServiceFromContainer(\Ecotone\Api\Dbal\ExtensionObject\DbalConnectionReference::DEFAULT)
            ->createContext()
            ->getDbalConnection();
        if ($request['action'] === 'injected-consume') {
            $system->run('official_probe', \Ecotone\Api\ExtensionObject\ExecutionPollingMetadata::createWithFinishWhenNoMessages());
            echo json_encode(['effects' => $observer->query('SELECT value FROM official_effects')->fetchAll(PDO::FETCH_COLUMN)], JSON_THROW_ON_ERROR);
            exit(0);
        }

        if ($request['action'] === 'injected-lifecycle') {
            $connection = $container->get(Connection::class);
            $config = $container->get(\Tempest\Database\Config\DatabaseConfig::class);
            $identity = $database->fetchFirst(new Query('SELECT pg_backend_pid() AS backend, current_schema() AS schema'));
            $sameOwner = $database->connection === $connection && $connection->database() === $dbal;
            $connection->reconnect();
            $after = $database->fetchFirst(new Query('SELECT pg_backend_pid() AS backend, current_schema() AS schema'));
            $database->execute(new Query('CREATE TABLE official_effects (value TEXT)'));
            $connection->beginTransaction();
            $transaction = $database->fetchFirst(new Query('SELECT pg_backend_pid() AS backend, txid_current() AS tx'));
            $rejected = [];
            foreach (['tempest-reconnect', 'dbal-close'] as $operation) {
                try {
                    if ($operation === 'tempest-reconnect') {
                        $connection->reconnect();
                    } else {
                        $dbal->close();
                    }

                    $rejected[$operation] = false;
                } catch (LogicException) {
                    $rejected[$operation] = true;
                }
            }

            $system->getCommandBus()->send(new \Evaluation\Official\BridgePing('rolled-back'));
            $before = (int) $observer->query('SELECT count(*) FROM enqueue')->fetchColumn();
            $physicalSame =
                (int) $transaction['backend'] === (int) $dbal->fetchOne('SELECT pg_backend_pid()')
                && (string) $transaction['tx'] === (string) $dbal->fetchOne('SELECT txid_current()');
            $connection->rollback();
            $rolledBack = (int) $observer->query('SELECT count(*) FROM enqueue')->fetchColumn();
            $connection->beginTransaction();
            $system->getCommandBus()->send(new \Evaluation\Official\BridgePing('committed'));
            $connection->commit();
            echo json_encode([
                'same_owner' => $sameOwner,
                'same_transaction' => $physicalSame,
                'reconnected' => $identity['backend'] !== $after['backend'],
                'schema_before' => $identity['schema'],
                'schema_after' => $after['schema'],
                'persistent' => $config->usePersistentConnection,
                'rejected' => $rejected,
                'invisible' => $before,
                'rolled_back' => $rolledBack,
                'committed' => (int) $observer->query('SELECT count(*) FROM enqueue')->fetchColumn(),
                'dbal_level' => $dbal->getTransactionNestingLevel(),
                'effect_count' => (int) $observer->query('SELECT count(*) FROM official_effects')->fetchColumn(),
            ], JSON_THROW_ON_ERROR);
            exit(0);
        }

        $jobs = new Jobs(new \App\Operations\JobRuntime($container->get(\App\Integration\OperationsConfig::class), connection: $dbal));
        // Validate native messaging independently of the Operations nesting diagnostic.
        $database->execute(new Query('CREATE TABLE official_effects (value TEXT)'));
        $system->getCommandBus()->send(new \Evaluation\Official\BridgePing('native'));
        $system->run('official_probe', \Ecotone\Api\ExtensionObject\ExecutionPollingMetadata::createWithFinishWhenNoMessages());
        $diagnostic = [
            'effect' => $observer->query('SELECT value FROM official_effects')->fetchColumn(),
            'messenger_installed' => \Composer\InstalledVersions::isInstalled('symfony/messenger'),
            'connection_class' => $container->get(Connection::class)::class,
        ];
        if ($diagnostic['effect'] !== 'native') {
            throw new RuntimeException('The official consumer did not persist its diagnostic effect.');
        }
    } else {
        $jobs = $container->get(Jobs::class);
        $dbal = $container->get(SharedOperationsConnection::class)->database();
    }

    $observer->exec('CREATE TABLE nested_state (id TEXT PRIMARY KEY, backend INT, tx BIGINT)');
    $observer->exec('CREATE TABLE nested_writes (kind TEXT, backend INT, tx BIGINT)');
    $observer->exec(
        "CREATE FUNCTION record_nested_write() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN INSERT INTO nested_writes VALUES (TG_TABLE_NAME, pg_backend_pid(), txid_current()); RETURN NEW; END'",
    );
    $observer->exec('CREATE TRIGGER observe_job AFTER INSERT ON jobs FOR EACH ROW EXECUTE FUNCTION record_nested_write()');
    $observer->exec('CREATE TRIGGER observe_invitation AFTER INSERT ON enqueue FOR EACH ROW EXECUTE FUNCTION record_nested_write()');
    if ($request['mode'] === 'inner-failure') {
        $observer->exec('ALTER TABLE enqueue ADD CONSTRAINT reject_nested CHECK (false)');
    }

    $observe = static fn (): array => [
        'mutations' => (int) $observer->query('SELECT count(*) FROM nested_state')->fetchColumn(),
        'jobs' => (int) $observer->query('SELECT count(*) FROM jobs')->fetchColumn(),
        'invitations' => (int) $observer->query('SELECT count(*) FROM enqueue')->fetchColumn(),
    ];
    $connection = $container->get(Connection::class);
    $connection->beginTransaction();
    $database->execute(new Query("INSERT INTO nested_state VALUES ('outer', pg_backend_pid(), txid_current())"));
    $identity = $database->fetchFirst(new Query("SELECT backend, tx FROM nested_state WHERE id = 'outer'"));
    $errorCode = null;
    $errorMessage = null;
    try {
        $jobs->request(new Work('acceptance.probe', $request['scope'], 'refresh', 'nested'));
    } catch (Throwable $error) {
        while ($error->getPrevious() !== null) {
            $error = $error->getPrevious();
        }

        $errorCode = (string) $error->getCode();
        $errorMessage = $error->getMessage();
    }

    $before = $observe();
    $active = $connection->inTransaction();
    $same = (int) $identity['backend'] === (int) $dbal->fetchOne('SELECT pg_backend_pid()') && (string) $identity['tx'] === (string) $dbal->fetchOne('SELECT txid_current()');
    $writes = $database->fetch(new Query('SELECT kind, backend, tx FROM nested_writes'));
    foreach ($writes as $write) {
        $same = $same && (int) $write['backend'] === (int) $identity['backend'] && (string) $write['tx'] === (string) $identity['tx'];
    }

    if ($active) {
        if ($request['mode'] === 'inner-failure') {
            $database->execute(new Query("INSERT INTO nested_state VALUES ('unrelated', pg_backend_pid(), txid_current())"));
        }

        if ($request['mode'] === 'rollback') {
            $connection->rollback();
        } else {
            $connection->commit();
        }
    }

    echo json_encode([
        'before' => $before,
        'same_transaction' => $same,
        'outer_active' => $active,
        'inner_error' => $errorCode,
        'after' => $observe(),
        'inner_error_message' => $errorMessage,
        'writes_in_outer' => count($writes),
        'official_diagnostic' => $diagnostic,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    while ($error->getPrevious() !== null) {
        $error = $error->getPrevious();
    }

    fwrite(STDERR, $error::class . ': ' . $error->getMessage());
    exit(1);
}
