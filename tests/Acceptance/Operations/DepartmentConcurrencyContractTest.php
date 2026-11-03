<?php

declare(strict_types=1);

use App\Identity\Identifier;
use App\Operations\Assignment;
use App\Operations\AssignmentState;
use App\Operations\Worker;
use Tempest\Process\GenericProcessExecutor;
use Tempest\Process\PendingProcess;
use Tests\Support\Durable\DepartmentFixture;
use Tests\Support\Durable\DepartmentJob;

/**
 * Production C02/C03 acceptance: independent processes, actual PostgreSQL
 * contention and real Messenger/Durable dispatch. No historical-engine driver.
 */
beforeEach(function (): void {
    $this->department = new DepartmentFixture();
    $this->worker = $this->department->container->get(Worker::class);
});

afterEach(function (): void {
    if (isset($this->department)) {
        $this->department->dispose();
    }
});

function acceptance_child(DepartmentFixture $fixture, array $arguments = []): Tempest\Process\InvokedSystemProcess
{
    return new GenericProcessExecutor()->start(new PendingProcess(
        command: [PHP_BINARY, dirname(__DIR__, 2) . '/Support/Durable/department-process.php'],
        input: json_encode([
            'dsn' => $fixture->config->dsn,
            'username' => $fixture->config->username,
            'password' => $fixture->config->password,
            ...$arguments,
        ], JSON_THROW_ON_ERROR),
    ));
}

function acceptance_barrier(string $path, Tempest\Process\InvokedSystemProcess $child): array
{
    $deadline = microtime(true) + 8;
    while (! is_file($path) && $child->running && microtime(true) < $deadline) {
        usleep(10_000);
    }
    expect(is_file($path))->toBeTrue($child->errorOutput);

    return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
}

function acceptance_redeliver(DepartmentFixture $fixture): void
{
    $id = $fixture->connections->application->fetchOne("SELECT id FROM messenger_messages WHERE queue_name = 'durable_activities' ORDER BY id LIMIT 1");
    expect($id)->not->toBeFalse();
    $envelope = $fixture->runtime->activityQueue->find((string) $id);
    expect($envelope)->not->toBeNull();
    $fixture->runtime->activityQueue->send(new Symfony\Component\Messenger\Envelope($envelope->getMessage()));
}

function acceptance_scheduled(DepartmentFixture $fixture): void
{
    $fixture->runtime->drain('outbox');
    $fixture->runtime->drain('activities');
    $fixture->runtime->drain('resumes');
}

test('C02: simultaneous independent admissions coalesce into one retained demand and effect', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'c02-admission-');
    unlink($path);
    $children = [];
    try {
        for ($i = 0; $i < 4; $i++) {
            $children[] = acceptance_child($this->department, [
                'action' => 'admit',
                'scope' => 'c02-process-contention',
                'barrier' => $path,
            ]);
        }

        $deadline = microtime(true) + 8;
        while (count(glob($path . '.*')) < count($children) && microtime(true) < $deadline) {
            usleep(10_000);
        }
        expect(glob($path . '.*'))->toHaveCount(count($children));
        file_put_contents($path . '.go', 'go');

        $identities = [];
        foreach ($children as $child) {
            $result = $child->wait();
            expect($result->exitCode)->toBe(0, $result->errorOutput);
            expect(preg_match('/RECEIPT:(\{[^\n]+\})/', $result->output, $receipt))->toBe(1);
            $identities[] = json_decode($receipt[1], true, flags: JSON_THROW_ON_ERROR)['id'];
        }

        expect(array_unique($identities))->toHaveCount(1);
        $db = $this->department->connections->application;
        expect((int) $db->fetchOne('SELECT count(*) FROM operation_assignments'))->toBe(1);
        expect((int) $db->fetchOne('SELECT count(*) FROM operation_demands'))->toBe(1);

        $worker = acceptance_child($this->department)->wait();
        expect($worker->exitCode)->toBe(0, $worker->errorOutput);
        expect($this->worker->status(Identifier::parse($identities[0], Assignment::class))->state)->toBe(AssignmentState::Completed);
        expect($db->fetchFirstColumn('SELECT value FROM effects'))->toBe(['c02-process-contention:refresh:one']);
    } finally {
        foreach ($children as $child) {
            if ($child->running) {
                $child->signal(9)->wait();
            }
        }
        foreach (glob($path . '.*') as $file) {
            unlink($file);
        }
    }
});

test('C03: a healthy owner excludes redelivery and publishes one effect', function (): void {
    $assignment = $this->worker->do(new DepartmentJob('c03-live-exclusion'));
    acceptance_scheduled($this->department);
    $path = tempnam(sys_get_temp_dir(), 'c03-live-');
    unlink($path);
    $owner = acceptance_child($this->department, ['handlerBarrier' => $path]);
    try {
        acceptance_barrier($path, $owner);
        expect($this->worker->status($assignment->id)->state)->toBe(AssignmentState::Running);
        acceptance_redeliver($this->department);
        $competitor = acceptance_child($this->department)->wait();
        expect($competitor->exitCode)->toBe(0, $competitor->errorOutput);
        $db = $this->department->connections->application;
        expect((int) $db->fetchOne('SELECT count(*) FROM operation_attempts'))->toBe(1);
        expect((int) $db->fetchOne('SELECT count(*) FROM effects'))->toBe(0);

        file_put_contents($path . '.release', 'go');
        $completed = $owner->wait();
        expect($completed->exitCode)->toBe(0, $completed->errorOutput);
        $this->department->runtime->drain();
        expect($this->worker->status($assignment->id)->state)->toBe(AssignmentState::Completed);
        expect($db->fetchFirstColumn('SELECT value FROM effects'))->toBe(['c03-live-exclusion:refresh:one']);
    } finally {
        if ($owner->running) {
            $owner->signal(9)->wait();
        }
        foreach (glob($path . '*') as $file) {
            unlink($file);
        }
    }
});

test('C03: loss of ownership during a protected transaction rolls back its effect', function (): void {
    $assignment = $this->worker->do(new DepartmentJob('c03-atomic-revocation'));
    acceptance_scheduled($this->department);
    $path = tempnam(sys_get_temp_dir(), 'c03-rollback-');
    unlink($path);
    $owner = acceptance_child($this->department, ['handlerBarrier' => $path, 'insideMutation' => true]);
    try {
        $observed = acceptance_barrier($path, $owner);
        $db = $this->department->connections->application;
        expect((int) $db->fetchOne('SELECT count(*) FROM effects'))->toBe(0);
        $this->department->admin->query('SELECT pg_terminate_backend(' . (int) $observed['pid'] . ')');
        file_put_contents($path . '.release', 'go');
        $owner->wait();
        expect((int) $db->fetchOne('SELECT count(*) FROM effects'))->toBe(0);

        acceptance_redeliver($this->department);
        $successor = acceptance_child($this->department)->wait();
        expect($successor->exitCode)->toBe(0, $successor->errorOutput);
        expect($this->worker->status($assignment->id)->state)->toBe(AssignmentState::Completed);
        expect($db->fetchFirstColumn('SELECT value FROM effects'))->toBe(['c03-atomic-revocation:refresh:one']);
    } finally {
        if ($owner->running) {
            $owner->signal(9)->wait();
        }
        foreach (glob($path . '*') as $file) {
            unlink($file);
        }
    }
});

test('C03: a superseded living handler cannot publish a second result', function (): void {
    $assignment = $this->worker->do(new DepartmentJob('c03-superseded'));
    acceptance_scheduled($this->department);
    $path = tempnam(sys_get_temp_dir(), 'c03-superseded-');
    unlink($path);
    $old = acceptance_child($this->department, ['handlerBarrier' => $path]);
    try {
        $observed = acceptance_barrier($path, $old);
        expect($this->department->admin->query('SELECT pg_terminate_backend(' . (int) $observed['pid'] . ')')->fetchColumn())->toBeTrue();
        acceptance_redeliver($this->department);
        $next = acceptance_child($this->department)->wait();
        expect($next->exitCode)->toBe(0, $next->errorOutput);
        file_put_contents($path . '.release', 'go');
        $old->wait();

        $db = $this->department->connections->application;
        expect($this->worker->status($assignment->id)->state)->toBe(AssignmentState::Completed);
        expect($db->fetchFirstColumn('SELECT value FROM effects'))->toBe(['c03-superseded:refresh:one']);
        expect($db->fetchFirstColumn('SELECT outcome FROM operation_attempts ORDER BY started_at'))->toBe(['interrupted', 'completed']);
    } finally {
        if ($old->running) {
            $old->signal(9)->wait();
        }
        foreach (glob($path . '*') as $file) {
            unlink($file);
        }
    }
});

test('C02: native outbox rejection rolls back admission and retained demand together', function (): void {
    $db = $this->department->connections->application;
    $db->executeStatement(
        "CREATE FUNCTION c02_reject_outbox() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.queue_name = 'operations_outbox' THEN RAISE EXCEPTION 'C02 rejected delivery'; END IF; RETURN NEW; END $$",
    );
    $db->executeStatement('CREATE TRIGGER c02_reject_outbox BEFORE INSERT ON messenger_messages FOR EACH ROW EXECUTE FUNCTION c02_reject_outbox()');
    try {
        expect(fn () => $this->worker->do(new DepartmentJob('c02-atomic')))->toThrow('C02 rejected delivery');
        expect((int) $db->fetchOne('SELECT count(*) FROM operation_assignments'))->toBe(0);
        expect((int) $db->fetchOne('SELECT count(*) FROM operation_demands'))->toBe(0);
        expect((int) $db->fetchOne('SELECT count(*) FROM messenger_messages'))->toBe(0);
    } finally {
        $db->executeStatement('DROP TRIGGER c02_reject_outbox ON messenger_messages');
        $db->executeStatement('DROP FUNCTION c02_reject_outbox()');
    }
    $assignment = $this->worker->do(new DepartmentJob('c02-atomic'));
    $this->department->runtime->drain();
    expect($this->worker->status($assignment->id)->state)->toBe(AssignmentState::Completed);
    expect($db->fetchFirstColumn('SELECT value FROM effects'))->toBe(['c02-atomic:refresh:one']);
});
