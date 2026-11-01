<?php

declare(strict_types=1);

use App\Operations\Durable\DurableRuntime;
use Doctrine\DBAL\DriverManager;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Transport\ActivityMessage;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\PostgreSqlStore;
use Symfony\Component\Messenger\Envelope;
use Tests\Support\Durable\ProbeWorkflow;

beforeEach(function (): void {
    $config = json_decode(file_get_contents(__DIR__ . '/../../../tmp/transaction-projection-admin.json'), true, flags: JSON_THROW_ON_ERROR);
    $this->admin = new PDO($config['dsn'], $config['username'], $config['password']);
    $this->name = 'stashd_tx_test_' . bin2hex(random_bytes(8));
    $password = bin2hex(random_bytes(24));
    $this->admin->exec('CREATE ROLE "' . $this->name . '" LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE PASSWORD ' . $this->admin->quote($password));
    $this->admin->exec('CREATE DATABASE "' . $this->name . '" OWNER "' . $this->name . '"');
    $this->params = ['driver' => 'pdo_pgsql', 'host' => 'lerd-postgres', 'dbname' => $this->name, 'user' => $this->name, 'password' => $password];
    $this->journal = DriverManager::getConnection($this->params);
    $this->application = DriverManager::getConnection($this->params);
    $this->lockConnection = DriverManager::getConnection($this->params);
    $container = new Tempest\Container\GenericContainer();
    $container->singleton(
        App\Operations\Durable\DurableConnections::class,
        new App\Operations\Durable\DurableConnections($this->application, $this->journal, $this->lockConnection),
    );
    $container->addInitializer(App\Operations\Durable\DurableRuntimeInitializer::class);
    $this->runtime = $container->get(DurableRuntime::class);
    $this->runtime->workflows->registerClass(ProbeWorkflow::class);
    $this->application->executeStatement('CREATE TABLE effects (value TEXT NOT NULL)');
    $this->runtime->activities->register('spike.effect', function (array $payload): string {
        $this->application->insert('effects', ['value' => $payload['value']]);

        return $payload['value'];
    });
    $this->id = ExecutionId::fromString((string) Symfony\Component\Uid\Uuid::v7());
});

afterEach(function (): void {
    if (isset($this->name)) {
        $this->admin->exec('DROP DATABASE "' . $this->name . '" WITH (FORCE)');
        $this->admin->exec('DROP ROLE "' . $this->name . '"');
    }
});

test('a persisted workflow completes through Messenger and duplicate activity delivery preserves one effect', function (): void {
    $this->runtime->dispatcher->dispatchNewWorkflowRun($this->id, ProbeWorkflow::class, ['value' => 'preserved']);
    $this->runtime->drain();
    expect($this->runtime->metadata->get($this->id)['completed'])->toBeTrue();
    expect($this->application->fetchFirstColumn('SELECT value FROM effects'))->toBe(['preserved']);
    $scheduled = array_values(array_filter(iterator_to_array($this->runtime->events->readStream($this->id)), fn ($event) => $event instanceof ActivityScheduled))[0];
    $this->runtime->activityQueue->send(new Envelope(new ActivityMessage((string) $this->id, $scheduled->activityId(), 'spike.effect', ['value' => 'preserved'])));
    $this->runtime->drain();
    expect($this->application->fetchFirstColumn('SELECT value FROM effects'))->toBe(['preserved']);
});

test('enqueue rejection after journalling exposes the actual failure and replay behaviour', function (): void {
    $this->application->executeStatement(
        "CREATE FUNCTION reject_activity() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.queue_name = 'durable_activities' THEN RAISE EXCEPTION 'spike enqueue rejected'; END IF; RETURN NEW; END $$",
    );
    $this->application->executeStatement('CREATE TRIGGER reject_activity BEFORE INSERT ON messenger_messages FOR EACH ROW EXECUTE FUNCTION reject_activity()');
    $this->runtime->dispatcher->dispatchNewWorkflowRun($this->id, ProbeWorkflow::class, ['value' => 'preserved']);
    $this->runtime->drain();
    $types = array_map(fn ($event) => $event::class, iterator_to_array($this->runtime->events->readStream($this->id)));
    expect($types)->toContain(ActivityScheduled::class, Gplanchat\Durable\Event\WorkflowExecutionFailed::class);
    expect($this->application->fetchOne("SELECT count(*) FROM messenger_messages WHERE queue_name = 'durable_activities'"))->toBe(0);
    expect($this->application->fetchOne("SELECT count(*) FROM messenger_messages WHERE queue_name = 'durable_resumes'"))->toBe(1);
    $this->application->executeStatement('DROP TRIGGER reject_activity ON messenger_messages');
    $this->runtime->dispatcher->dispatchResume($this->id);
    $this->runtime->drain();
    expect($this->application->fetchFirstColumn('SELECT value FROM effects'))->toBe([]);
    $replacement = ExecutionId::generate();
    $this->runtime->dispatcher->dispatchNewWorkflowRun($replacement, ProbeWorkflow::class, ['value' => 'preserved']);
    $this->runtime->drain();
    expect($this->runtime->metadata->get($replacement)['completed'])->toBeTrue();
    expect($this->application->fetchFirstColumn('SELECT value FROM effects'))->toBe(['preserved']);
});

/**
 * Start a fresh PHP worker with credentials on stdin, never in process arguments.
 */
function durable_probe(array $params, ?string $receiver = null, ?string $barrier = null, bool $expiring = false): Tempest\Process\InvokedSystemProcess
{
    return new Tempest\Process\GenericProcessExecutor()->start(new Tempest\Process\PendingProcess(
        command: [PHP_BINARY, __DIR__ . '/../../Support/Durable/worker.php'],
        input: json_encode(array_filter(['params' => $params, 'receiver' => $receiver, 'barrier' => $barrier, 'expiring' => $expiring]), JSON_THROW_ON_ERROR),
    ));
}

/**
 * Require the independent worker to reach its blocking activity before proceeding.
 */
function durable_barrier(string $path, Tempest\Process\InvokedSystemProcess $child): void
{
    $deadline = microtime(true) + 5;
    while (! is_file($path) && $child->running && microtime(true) < $deadline) {
        usleep(10_000);
    }

    expect(is_file($path))->toBeTrue($child->errorOutput);
}

test('fresh worker processes replay persisted history without repeating a completed activity', function (): void {
    $this->runtime->dispatcher->dispatchNewWorkflowRun($this->id, ProbeWorkflow::class, ['value' => 'restart']);
    $first = durable_probe($this->params, 'resumes')->wait();
    expect($first->exitCode)->toBe(0, $first->errorOutput);
    expect($this->application->fetchFirstColumn('SELECT value FROM effects'))->toBe([]);
    $second = durable_probe($this->params, 'activities')->wait();
    expect($second->exitCode)->toBe(0, $second->errorOutput);
    $third = durable_probe($this->params, 'resumes')->wait();
    expect($third->exitCode)->toBe(0, $third->errorOutput);
    expect($this->runtime->metadata->get($this->id)['completed'])->toBeTrue();
    expect($this->application->fetchFirstColumn('SELECT value FROM effects'))->toBe(['restart']);
});

test('session claims outlive a shortened TTL and killed workers can be recovered promptly', function (): void {
    $this->runtime->dispatcher->dispatchNewWorkflowRun($this->id, ProbeWorkflow::class, ['value' => 'recovered']);
    $this->runtime->drain('resumes');
    $path = tempnam(sys_get_temp_dir(), 'durable-barrier-');
    unlink($path);
    $child = durable_probe($this->params, 'activities', $path);
    try {
        durable_barrier($path, $child);
        usleep(1_100_000); // Exceed the one-second claim TTL without a multi-minute sleep.
        $scheduled = array_values(array_filter(iterator_to_array($this->runtime->events->readStream($this->id)), fn ($event) => $event instanceof ActivityScheduled))[0];
        $claim = new Gplanchat\Bridge\Dbal\Messenger\LockActivityAttemptClaim(new LockFactory(new PostgreSqlStore($this->lockConnection->getNativeConnection())), 0.05);
        expect($claim->claim($this->id, $scheduled->activityId(), 1))->toBeNull();
        $child->signal(9);
        expect($child->wait()->exitCode)->toBe(137);
        $deadline = microtime(true) + 3;
        do {
            $release = $claim->claim($this->id, $scheduled->activityId(), 1);
            if ($release !== null) {
                break;
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);

        expect($release)->not->toBeNull();
        $release();
        // Native transport visibility expires normally; do not rewrite ownership rows.
        usleep(2_100_000);
        $result = durable_probe($this->params)->wait();
        expect($result->exitCode)->toBe(0, $result->errorOutput);
        expect($this->runtime->metadata->get($this->id)['completed'])->toBeTrue();
        expect($this->application->fetchFirstColumn('SELECT value FROM effects'))->toBe(['recovered']);
    } finally {
        if ($child->running) {
            $child->signal(9)->wait();
        }

        @unlink($path);
    }
});

test('losing the lock session permits takeover and stale effects but blocks stale completion', function (): void {
    $this->runtime->dispatcher->dispatchNewWorkflowRun($this->id, ProbeWorkflow::class, ['value' => 'unsafe']);
    $this->runtime->drain('resumes');
    $path = tempnam(sys_get_temp_dir(), 'durable-barrier-');
    unlink($path);
    $child = durable_probe($this->params, 'activities', $path);
    try {
        durable_barrier($path, $child);
        $backend = (int) file_get_contents($path);
        expect($this->admin->query('SELECT pg_terminate_backend(' . $backend . ')')->fetchColumn())->toBeTrue();
        expect($child->running)->toBeTrue();
        usleep(2_100_000);
        $result = durable_probe($this->params)->wait();
        expect($result->exitCode)->toBe(0, $result->errorOutput);
        expect($this->application->fetchFirstColumn('SELECT value FROM effects'))->toBe(['unsafe']);
        file_put_contents($path . '.release', 'go');
        $child->wait(); // Lock release may fail on the closed session, after the writes.
        expect($this->application->fetchFirstColumn('SELECT value FROM effects'))->toBe(['unsafe', 'unsafe']);
        $completed = array_filter(iterator_to_array($this->runtime->events->readStream($this->id)), fn ($event) => $event instanceof Gplanchat\Durable\Event\ActivityCompleted);
        expect($completed)->toHaveCount(1);
    } finally {
        if ($child->running) {
            $child->signal(9)->wait();
        }

        @unlink($path);
        @unlink($path . '.release');
    }
});

test('an expiring claim allows a stale living activity to append completion after takeover', function (): void {
    $this->runtime->dispatcher->dispatchNewWorkflowRun($this->id, ProbeWorkflow::class, ['value' => 'stale']);
    $this->runtime->drain('resumes');
    $path = tempnam(sys_get_temp_dir(), 'durable-barrier-');
    unlink($path);
    $child = durable_probe($this->params, 'activities', $path, expiring: true);
    try {
        durable_barrier($path, $child);
        usleep(2_100_000);
        $result = durable_probe($this->params, expiring: true)->wait();
        expect($result->exitCode)->toBe(0, $result->errorOutput);
        expect($child->running)->toBeTrue();
        expect($this->application->fetchFirstColumn('SELECT value FROM effects'))->toBe(['stale']);
        file_put_contents($path . '.release', 'go');
        $child->wait();
        expect($this->application->fetchFirstColumn('SELECT value FROM effects'))->toBe(['stale', 'stale']);
        $completed = array_filter(iterator_to_array($this->runtime->events->readStream($this->id)), fn ($event) => $event instanceof Gplanchat\Durable\Event\ActivityCompleted);
        expect($completed)->toHaveCount(2);
    } finally {
        if ($child->running) {
            $child->signal(9)->wait();
        }

        @unlink($path);
        @unlink($path . '.release');
    }
});
