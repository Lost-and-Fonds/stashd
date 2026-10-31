<?php

declare(strict_types=1);

use App\Integration\IntegrationConfigurations;
use App\Integration\OperationsDatabase;
use App\Operations\Jobs;
use App\Operations\Work;
use App\Operations\WorkExecutor;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\Messaging\ConfiguredMessagingSystem;
use Tempest\Core\Tempest;
use Tempest\Database\Connection\Connection;
use Tempest\Database\Query;
use Tests\Support\Operations\LocalOperationsDriver;
use Tests\Support\Operations\ProbeExecutor;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

try {
    $request = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    $d = $request['descriptor'];
    $observer = LocalOperationsDriver::connect($d);
    if ($request['private_schema'] ?? false) {
        $private = $d['schema'] . '_injected';
        if ($observer->query("SELECT to_regnamespace('" . $d['schema'] . "')")->fetchColumn() !== null) {
            $observer->exec('ALTER SCHEMA "' . $d['schema'] . '" RENAME TO "' . $private . '"');
        }

        $d['schema'] = $private;
        $observer->exec('SET search_path TO "' . $private . '"');
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
    $container->singleton(WorkExecutor::class, new ProbeExecutor($observer));
    if (($request['order'] ?? '') === 'messaging-first' || $request['action'] === 'official-nested') {
        $container->get(ConfiguredMessagingSystem::class);
    }

    $database = $container->get(OperationsDatabase::class)->connect();
    $connection = $container->get(Connection::class);
    $dbal = $connection->database();
    $jobs = $container->get(Jobs::class);
    $system = $container->get(ConfiguredMessagingSystem::class);
    if ($request['action'] === 'install') {
        if (! ($request['provision_only'] ?? false)) {
            $container->get(IntegrationConfigurations::class)->initialize();
        }

        $system->getServiceFromContainer(\Ecotone\Api\Dbal\ExtensionObject\DatabaseSetupManager::class)->initializeAll();
        if (! ($request['provision_only'] ?? false)) {
            $observer->exec('CREATE TABLE probe_scopes (id UUID PRIMARY KEY, name TEXT NOT NULL)');
            $observer->exec(
                'CREATE TABLE probe_effects (sequence BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY, scope_id UUID REFERENCES probe_scopes(id), mode TEXT, job_id UUID, observation TEXT)',
            );
            $observer->prepare('INSERT INTO probe_scopes VALUES (?, ?)')->execute([$request['scope'], 'fresh-installation']);
        }

        echo '{}';
        exit(0);
    }

    if ($request['action'] === 'injected-consume') {
        $system->run('jobs', ExecutionPollingMetadata::createWithFinishWhenNoMessages());
        echo json_encode(['effects' => $observer->query('SELECT observation FROM probe_effects')->fetchAll(PDO::FETCH_COLUMN)], JSON_THROW_ON_ERROR);
        exit(0);
    }

    if ($request['action'] === 'injected-lifecycle') {
        $identity = $database->fetchFirst(new Query('SELECT pg_backend_pid() AS backend, current_schema() AS schema'));
        $sameOwner = $database->connection === $connection;
        $connection->reconnect();
        $after = $database->fetchFirst(new Query('SELECT pg_backend_pid() AS backend, current_schema() AS schema'));
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

        $jobs->request(new Work('acceptance.probe', $request['scope'], 'refresh', 'rolled-back'));
        $before = (int) $observer->query('SELECT count(*) FROM enqueue')->fetchColumn();
        $physicalSame =
            (int) $transaction['backend'] === (int) $dbal->fetchOne('SELECT pg_backend_pid()') && (string) $transaction['tx'] === (string) $dbal->fetchOne('SELECT txid_current()');
        $connection->rollback();
        $rolledBack = (int) $observer->query('SELECT count(*) FROM enqueue')->fetchColumn();
        $connection->beginTransaction();
        $jobs->request(new Work('acceptance.probe', $request['scope'], 'refresh', 'committed'));
        $connection->commit();
        echo json_encode([
            'same_owner' => $sameOwner,
            'same_transaction' => $physicalSame,
            'reconnected' => $identity['backend'] !== $after['backend'],
            'schema_before' => $identity['schema'],
            'schema_after' => $after['schema'],
            'persistent' => $container->get(\Tempest\Database\Config\DatabaseConfig::class)->usePersistentConnection,
            'rejected' => $rejected,
            'invisible' => $before,
            'rolled_back' => $rolledBack,
            'committed' => (int) $observer->query('SELECT count(*) FROM enqueue')->fetchColumn(),
            'dbal_level' => $dbal->getTransactionNestingLevel(),
            'effect_count' => (int) $observer->query('SELECT count(*) FROM probe_effects')->fetchColumn(),
        ], JSON_THROW_ON_ERROR);
        exit(0);
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
        'official_diagnostic' => ['messenger_installed' => \Composer\InstalledVersions::isInstalled('symfony/messenger'), 'connection_class' => $connection::class],
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    while ($error->getPrevious() !== null) {
        $error = $error->getPrevious();
    }

    fwrite(STDERR, $error::class . ': ' . $error->getMessage());
    exit(1);
}
