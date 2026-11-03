<?php

declare(strict_types=1);

use App\Identity\Identifier;
use App\Operations\Assignment;
use App\Operations\AssignmentState;
use App\Operations\Priority;
use App\Operations\Worker;
use Tests\Support\Durable\DepartmentFixture;
use Tests\Support\Durable\DepartmentJob;

beforeEach(function (): void {
    $this->department = new DepartmentFixture();
    $this->worker = $this->department->container->get(Worker::class);
});
afterEach(function (): void {
    if (isset($this->department)) {
        $this->department->dispose();
    }
});

test('typed discovered Jobs coalesce but retain unmet observations and distinct modes', function (): void {
    $a = $this->worker->do(new DepartmentJob('asset'));
    expect($a->state)->toBe(AssignmentState::Queued);
    expect($this->department->connections->application->fetchOne('SELECT count(*) FROM effects'))->toBe(0);
    expect($this->worker->do(new DepartmentJob('asset', 'two'))->id)->toEqual($a->id);
    expect($this->worker->do(new DepartmentJob('asset'))->id)->toEqual($a->id);
    $other = $this->worker->do(new DepartmentJob('asset', mode: 'complete'));
    expect($other->id)->not->toEqual($a->id);
    $this->department->runtime->drain();
    expect($this->worker->status($a->id)->state)->toBe(AssignmentState::Completed);
    expect($this->department->connections->application->fetchFirstColumn('SELECT value FROM effects ORDER BY id'))->toEqualCanonicalizing([
        'asset:refresh:one',
        'asset:refresh:two',
        'asset:complete:one',
    ]);
    $later = $this->worker->do(new DepartmentJob('asset'));
    expect($later->id)->not->toEqual($a->id);
    $this->department->runtime->drain();
    expect($this->worker->status($later->id)->state)->toBe(AssignmentState::Completed);
});

test('a rejected native outbox insertion rolls back Tempest model admission and demand', function (): void {
    $db = $this->department->connections->application;
    $db->executeStatement(
        "CREATE FUNCTION reject_admission() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.queue_name = 'operations_outbox' THEN RAISE EXCEPTION 'admission rejected'; END IF; RETURN NEW; END $$",
    );
    $db->executeStatement('CREATE TRIGGER reject_admission BEFORE INSERT ON messenger_messages FOR EACH ROW EXECUTE FUNCTION reject_admission()');
    expect(fn () => $this->worker->do(new DepartmentJob('rollback')))->toThrow('admission rejected');
    expect($db->fetchOne('SELECT count(*) FROM operation_assignments'))->toBe(0);
    expect($db->fetchOne('SELECT count(*) FROM operation_demands'))->toBe(0);
    $db->executeStatement('DROP TRIGGER reject_admission ON messenger_messages');
    $a = $this->worker->do(new DepartmentJob('rollback'));
    $this->department->runtime->drain();
    expect($this->worker->status($a->id)->state)->toBe(AssignmentState::Completed);
});

test('queued interactive promotion changes real execution ordering and retains identity', function (): void {
    $this->worker->do(new DepartmentJob('older'));
    $routine = $this->worker->do(new DepartmentJob('promoted'));
    $promoted = $this->worker->do(new DepartmentJob('promoted'), Priority::Interactive);
    expect($promoted->id)->toEqual($routine->id);
    $this->department->runtime->drain('outbox');
    $this->department->runtime->drain();
    expect($this->department->connections->application->fetchFirstColumn('SELECT value FROM effects ORDER BY id'))->toBe(['promoted:refresh:one', 'older:refresh:one']);
});

/**
 * Send fixture credentials over stdin to a fresh framework-hosted process.
 */
function department_process(DepartmentFixture $fixture, array $input = []): Tempest\Process\InvokedSystemProcess
{
    return new Tempest\Process\GenericProcessExecutor()->start(new Tempest\Process\PendingProcess(
        command: [PHP_BINARY, __DIR__ . '/../../Support/Durable/department-process.php'],
        input: json_encode(['dsn' => $fixture->config->dsn, 'username' => $fixture->config->username, 'password' => $fixture->config->password, ...$input], JSON_THROW_ON_ERROR),
    ));
}

test('eight independent admissions overlap and survive a fresh worker restart', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'department-admit-');
    unlink($path);
    $children = [];
    try {
        for ($i = 0; $i < 8; $i++) {
            $children[] = department_process($this->department, ['action' => 'admit', 'scope' => 'concurrent', 'barrier' => $path]);
        }

        $deadline = microtime(true) + 8;
        while (count(glob($path . '.*')) < 8 && microtime(true) < $deadline) {
            usleep(10_000);
        }

        expect(glob($path . '.*'))->toHaveCount(8);
        file_put_contents($path . '.go', 'go');
        $ids = [];
        foreach ($children as $child) {
            $result = $child->wait();
            expect($result->exitCode)->toBe(0, $result->errorOutput);
            expect(preg_match('/RECEIPT:(\{[^\n]+\})/', $result->output, $receipt))->toBe(1);
            $ids[] = json_decode($receipt[1], true, flags: JSON_THROW_ON_ERROR)['id'];
        }

        expect(array_unique($ids))->toHaveCount(1);
        $result = department_process($this->department)->wait();
        expect($result->exitCode)->toBe(0, $result->errorOutput);
        expect($this->worker->status(Identifier::parse($ids[0], Assignment::class))->state)->toBe(AssignmentState::Completed);
        expect($this->department->connections->application->fetchFirstColumn('SELECT value FROM effects'))->toBe(['concurrent:refresh:one']);
    } finally {
        foreach (array_filter($children, static fn ($child): bool => $child->running) as $child) {
            $child->signal(9)->wait();
        }

        foreach (glob($path . '.*') as $file) {
            unlink($file);
        }
    }
});

test('initial activity enqueue rejection follows the native Durable failure outcome', function (): void {
    $assignment = $this->worker->do(new DepartmentJob('native-enqueue-rejection'));
    $this->department->runtime->drain('outbox');
    $this->department->runtime->drain('activities');
    $db = $this->department->connections->application;
    $db->executeStatement(
        "CREATE FUNCTION reject_activity() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.queue_name = 'durable_activities' THEN RAISE EXCEPTION 'native enqueue rejected'; END IF; RETURN NEW; END $$",
    );
    $db->executeStatement('CREATE TRIGGER reject_activity BEFORE INSERT ON messenger_messages FOR EACH ROW EXECUTE FUNCTION reject_activity()');
    try {
        $this->department->runtime->drain();
        expect($this->worker->status($assignment->id)->state)->toBe(AssignmentState::Failed);
        expect($this->worker->status($assignment->id)->error)->toContain('native enqueue rejected');
        expect($db->fetchOne('SELECT count(*) FROM effects'))->toBe(0);
        expect($db->fetchOne('SELECT count(*) FROM operation_attempts'))->toBe(0);
    } finally {
        $db->executeStatement('DROP TRIGGER reject_activity ON messenger_messages');
        $db->executeStatement('DROP FUNCTION reject_activity()');
    }
});

test('promotion after workflow scheduling moves pending activity execution ahead of routine work', function (): void {
    $this->worker->do(new DepartmentJob('older-activity'));
    $a = $this->worker->do(new DepartmentJob('promoted-activity'));
    $this->department->runtime->drain('outbox');
    $this->department->runtime->drain('activities'); // StartAssignment only; resumes remain queued.
    $this->department->runtime->drain('resumes'); // Journal activities before promotion.
    $this->worker->do(new DepartmentJob('promoted-activity'), Priority::Interactive);
    $this->department->runtime->drain();
    expect($this->department->connections->application->fetchFirstColumn('SELECT value FROM effects ORDER BY id'))->toBe([
        'promoted-activity:refresh:one',
        'older-activity:refresh:one',
    ]);
    expect($this->worker->status($a->id)->state)->toBe(AssignmentState::Completed);
});

test('promotion retains a delayed delivery and never bypasses its native due time', function (): void {
    $assignment = $this->worker->do(new DepartmentJob('delayed-promotion'));
    $runtime = $this->department->runtime;
    $runtime->drain('outbox');
    $runtime->drain('activities');
    $runtime->drain('resumes');
    $db = $this->department->connections->application;
    $db->executeStatement("UPDATE messenger_messages SET available_at = NOW() + INTERVAL '1 hour' WHERE queue_name = 'durable_activities'");
    $before = $db->fetchAssociative("SELECT * FROM messenger_messages WHERE queue_name = 'durable_activities'");
    expect($before)->not->toBeFalse();

    $db->executeStatement(
        "CREATE FUNCTION reject_promotion() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.queue_name = 'operations_outbox' THEN RAISE EXCEPTION 'promotion rejected'; END IF; RETURN NEW; END $$",
    );
    $db->executeStatement('CREATE TRIGGER reject_promotion BEFORE INSERT ON messenger_messages FOR EACH ROW EXECUTE FUNCTION reject_promotion()');
    try {
        expect(fn () => $this->worker->do(new DepartmentJob('delayed-promotion'), Priority::Interactive))->toThrow('promotion rejected');
    } finally {
        $db->executeStatement('DROP TRIGGER reject_promotion ON messenger_messages');
        $db->executeStatement('DROP FUNCTION reject_promotion()');
    }

    expect($db->fetchAssociative('SELECT * FROM messenger_messages WHERE id = ?', [$before['id']]))->toBe($before);
    expect($this->worker->status($assignment->id)->priority)->toBe(Priority::Routine);

    $this->worker->do(new DepartmentJob('delayed-promotion'), Priority::Interactive);
    $runtime->drain();
    expect($db->fetchOne('SELECT count(*) FROM effects'))->toBe(0);
    expect($db->fetchAssociative('SELECT * FROM messenger_messages WHERE id = ?', [$before['id']]))->toBe([
        ...$before,
        'queue_name' => 'operations_interactive',
    ]);
    expect($this->worker->status($assignment->id)->state)->toBe(AssignmentState::Queued);
    $db->executeStatement("UPDATE messenger_messages SET available_at = NOW() - INTERVAL '1 second' WHERE id = ?", [$before['id']]);
    $runtime->drain();
    expect($this->worker->status($assignment->id)->state)->toBe(AssignmentState::Completed);
    expect($db->fetchFirstColumn('SELECT value FROM effects'))->toBe(['delayed-promotion:refresh:one']);
});

/**
 * Require observable entry into the real handler before changing ownership.
 */
function department_barrier(string $path, Tempest\Process\InvokedSystemProcess $child): array
{
    $deadline = microtime(true) + 6;
    while (! is_file($path) && $child->running && microtime(true) < $deadline) {
        usleep(10_000);
    }

    expect(is_file($path))->toBeTrue($child->errorOutput);

    return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * Redeliver a real retained native envelope without changing domain ownership rows.
 */
function department_redeliver(DepartmentFixture $fixture): void
{
    $id = $fixture->connections->application->fetchOne("SELECT id FROM messenger_messages WHERE queue_name = 'durable_activities' ORDER BY id LIMIT 1");
    expect($id)->not->toBeFalse();
    $envelope = $fixture->runtime->activityQueue->find((string) $id);
    expect($envelope)->not->toBeNull();
    $fixture->runtime->activityQueue->send(new Symfony\Component\Messenger\Envelope($envelope->getMessage()));
}

test('a stale living handler cannot publish a result or journal completion after takeover', function (): void {
    $assignment = $this->worker->do(new DepartmentJob('session-loss'));
    $runtime = $this->department->runtime;
    $runtime->drain('outbox');
    $runtime->drain('activities');
    $runtime->drain('resumes');
    $path = tempnam(sys_get_temp_dir(), 'department-stale-');
    unlink($path);
    $a = department_process($this->department, ['handlerBarrier' => $path]);
    try {
        $owner = department_barrier($path, $a);
        expect($this->department->admin->query('SELECT pg_terminate_backend(' . (int) $owner['pid'] . ', 5000)')->fetchColumn())->toBeTrue();
        expect($a->running)->toBeTrue();
        expect($this->worker->status($assignment->id)->state)->toBe(AssignmentState::Queued);
        department_redeliver($this->department);
        $b = department_process($this->department)->wait();
        expect($b->exitCode)->toBe(0, $b->errorOutput);
        expect($this->worker->status($assignment->id)->state)->toBe(AssignmentState::Completed);
        file_put_contents($path . '.release', 'go');
        $late = $a->wait();
        expect($late->exitCode)->toBe(0, $late->errorOutput);
        expect($this->department->connections->application->fetchFirstColumn('SELECT value FROM effects'))->toBe(['session-loss:refresh:one']);
        $events = iterator_to_array($runtime->events->readStream(Gplanchat\Durable\ExecutionId::fromString($assignment->id->uuidFor(Assignment::class))));
        expect(array_filter($events, fn ($event) => $event instanceof Gplanchat\Durable\Event\ActivityCompleted))->toHaveCount(1);
        expect(array_filter($events, fn ($event) => $event instanceof Gplanchat\Durable\Event\ActivityTaskFailed))->toBe([]);
        expect($this->department->connections->application->fetchFirstColumn('SELECT outcome FROM operation_attempts ORDER BY started_at'))->toBe(['interrupted', 'completed']);
    } finally {
        if ($a->running) {
            $a->signal(9)->wait();
        }

        foreach (glob($path . '*') as $file) {
            unlink($file);
        }
    }
});

test('authority loss during the protected mutation rolls back its write before recovery', function (): void {
    $assignment = $this->worker->do(new DepartmentJob('mutation-loss'));
    $runtime = $this->department->runtime;
    $runtime->drain('outbox');
    $runtime->drain('activities');
    $runtime->drain('resumes');
    $path = tempnam(sys_get_temp_dir(), 'department-mutation-');
    unlink($path);
    $a = department_process($this->department, ['handlerBarrier' => $path, 'insideMutation' => true]);
    try {
        $owner = department_barrier($path, $a);
        expect($this->department->connections->application->fetchOne('SELECT count(*) FROM effects'))->toBe(0);
        expect($this->department->admin->query('SELECT pg_terminate_backend(' . (int) $owner['pid'] . ', 5000)')->fetchColumn())->toBeTrue();
        file_put_contents($path . '.release', 'go');
        $a->wait();
        expect($this->department->connections->application->fetchOne('SELECT count(*) FROM effects'))->toBe(0);
        department_redeliver($this->department);
        $b = department_process($this->department)->wait();
        expect($b->exitCode)->toBe(0, $b->errorOutput);
        expect($this->worker->status($assignment->id)->state)->toBe(AssignmentState::Completed);
        expect($this->department->connections->application->fetchFirstColumn('SELECT value FROM effects'))->toBe(['mutation-loss:refresh:one']);
    } finally {
        if ($a->running) {
            $a->signal(9)->wait();
        }

        foreach (glob($path . '*') as $file) {
            unlink($file);
        }
    }
});

test('a late stale handler failure cannot consume Durable retry budget or change the successor', function (): void {
    $assignment = $this->worker->do(new DepartmentJob('late-failure'));
    $runtime = $this->department->runtime;
    $runtime->drain('outbox');
    $runtime->drain('activities');
    $runtime->drain('resumes');
    $path = tempnam(sys_get_temp_dir(), 'department-failure-');
    unlink($path);
    $a = department_process($this->department, ['handlerBarrier' => $path, 'fail' => true]);
    try {
        $owner = department_barrier($path, $a);
        expect($this->department->admin->query('SELECT pg_terminate_backend(' . (int) $owner['pid'] . ', 5000)')->fetchColumn())->toBeTrue();
        department_redeliver($this->department);
        $b = department_process($this->department)->wait();
        expect($b->exitCode)->toBe(0, $b->errorOutput);
        $before = $this->department->connections->application->fetchAllAssociative('SELECT * FROM operation_assignments ORDER BY uuid');
        file_put_contents($path . '.release', 'go');
        $a->wait();
        expect($this->department->connections->application->fetchAllAssociative('SELECT * FROM operation_assignments ORDER BY uuid'))->toBe($before);
        $events = iterator_to_array($runtime->events->readStream(Gplanchat\Durable\ExecutionId::fromString($assignment->id->uuidFor(Assignment::class))));
        expect(array_filter(
            $events,
            fn ($event) => $event instanceof Gplanchat\Durable\Event\ActivityTaskFailed || $event instanceof Gplanchat\Durable\Event\ActivityRetryQueued,
        ))->toBe([]);
        expect($this->worker->status($assignment->id)->state)->toBe(AssignmentState::Completed);
    } finally {
        if ($a->running) {
            $a->signal(9)->wait();
        }

        foreach (glob($path . '*') as $file) {
            unlink($file);
        }
    }
});

test('acknowledged custody survives worker death and a successor verifies it before adoption', function (): void {
    $bytes = tempnam(sys_get_temp_dir(), 'department-custody-');
    file_put_contents($bytes, 'retained preservation bytes');
    $assignment = $this->worker->do(new DepartmentJob('custody', custodyPath: $bytes));
    $runtime = $this->department->runtime;
    $runtime->drain('outbox');
    $runtime->drain('activities');
    $runtime->drain('resumes');
    $path = tempnam(sys_get_temp_dir(), 'department-custody-owner-');
    unlink($path);
    $a = department_process($this->department, ['handlerBarrier' => $path]);
    try {
        department_barrier($path, $a);
        $db = $this->department->connections->application;
        expect($db->fetchFirstColumn('SELECT digest FROM operation_custody_receipts'))->toBe([hash_file('sha256', $bytes)]);
        expect($db->fetchOne('SELECT count(*) FROM operation_results'))->toBe(0);
        $a->signal(9);
        expect($a->wait()->exitCode)->toBe(137);
        department_redeliver($this->department);
        $b = department_process($this->department)->wait();
        expect($b->exitCode)->toBe(0, $b->errorOutput);
        expect($this->worker->status($assignment->id)->state)->toBe(AssignmentState::Completed);
        expect($db->fetchFirstColumn('SELECT coverage FROM operation_results'))->toBe(['verified']);
        expect($db->fetchOne('SELECT count(*) FROM operation_reconciliations'))->toBe(1);
        expect(Tempest\Database\query(App\Operations\Models\Checkpoint::class)->select()->all())->toHaveCount(2);
        $receipt = Tempest\Database\query(App\Operations\Models\CustodyReceipt::class)->select()->first();
        expect($receipt->key)->toBe('retained');
        expect($receipt->digest)->toBe(hash_file('sha256', $bytes));
        $recorded = Tempest\Database\query(App\Operations\Models\Result::class)->select()->first();
        expect($recorded->coverage)->toBe('verified');
        expect($recorded->attempt_id)->not->toBe($receipt->attempt_id);
        expect($db->fetchOne('SELECT count(*) FROM operation_custody_receipts'))->toBe(1);
    } finally {
        if ($a->running) {
            $a->signal(9)->wait();
        }

        foreach (glob($path . '*') as $file) {
            unlink($file);
        }

        @unlink($bytes);
    }
});

test('Durable owns the bounded handler retry budget and exposes terminal failure', function (): void {
    $this->department->container->singleton(Tests\Support\Durable\DepartmentControl::class, new Tests\Support\Durable\DepartmentControl(fail: true));
    $assignment = $this->worker->do(new DepartmentJob('exhausted'));
    $this->department->runtime->drain();
    $failed = $this->worker->status($assignment->id);
    expect($failed->state)->toBe(AssignmentState::Failed);
    expect($failed->error)->toContain('identifiable handler failure');
    expect($this->department->connections->application->fetchFirstColumn('SELECT outcome FROM operation_attempts ORDER BY started_at'))->toBe([
        'failed',
        'failed',
        'failed',
        'failed',
    ]);
    expect($this->department->connections->application->fetchOne('SELECT count(*) FROM effects'))->toBe(0);
    $this->department->container->singleton(Tests\Support\Durable\DepartmentControl::class, new Tests\Support\Durable\DepartmentControl());
    $new = $this->worker->do(new DepartmentJob('exhausted', 'new-input'));
    expect($new->id)->not->toEqual($failed->id);
    $this->department->runtime->drain();
    expect($this->worker->status($new->id)->state)->toBe(AssignmentState::Completed);
});

test('changed custody bytes cannot be adopted by the recovering worker', function (): void {
    $bytes = tempnam(sys_get_temp_dir(), 'department-invalid-custody-');
    file_put_contents($bytes, 'acknowledged bytes');
    $digest = hash_file('sha256', $bytes);
    $assignment = $this->worker->do(new DepartmentJob('invalid-custody', custodyPath: $bytes));
    $runtime = $this->department->runtime;
    $runtime->drain('outbox');
    $runtime->drain('activities');
    $runtime->drain('resumes');
    $path = tempnam(sys_get_temp_dir(), 'department-invalid-owner-');
    unlink($path);
    $a = department_process($this->department, ['handlerBarrier' => $path]);
    try {
        department_barrier($path, $a);
        $a->signal(9)->wait();
        file_put_contents($bytes, 'changed bytes');
        department_redeliver($this->department);
        $b = department_process($this->department)->wait();
        expect($b->exitCode)->toBe(0, $b->errorOutput);
        expect($this->worker->status($assignment->id)->state)->toBe(AssignmentState::Failed);
        expect($this->department->connections->application->fetchOne('SELECT count(*) FROM operation_results'))->toBe(0);
        expect($this->department->connections->application->fetchFirstColumn('SELECT digest FROM operation_custody_receipts'))->toBe([$digest]);
    } finally {
        if ($a->running) {
            $a->signal(9)->wait();
        }

        foreach (glob($path . '*') as $file) {
            unlink($file);
        }

        @unlink($bytes);
    }
});

test('the normal Tempest worker command starts and shuts down cleanly under SIGTERM', function (): void {
    $fixture = $this->department;
    $setup = new Tempest\Process\GenericProcessExecutor()->run(new Tempest\Process\PendingProcess(
        command: [PHP_BINARY, 'tempest', 'operations:install', '--no-interaction'],
        path: dirname(__DIR__, 3),
        environment: [
            'STASHD_OPERATIONS_DSN' => $fixture->config->dsn,
            'STASHD_OPERATIONS_USERNAME' => $fixture->config->username,
            'STASHD_OPERATIONS_PASSWORD' => $fixture->config->password,
            'STASHD_OPERATIONS_SCHEMA' => $fixture->config->schema,
        ],
    ));
    expect($setup->exitCode)->toBe(0, $setup->output . $setup->errorOutput);

    $existing = $fixture->admin->query("SELECT pid FROM pg_stat_activity WHERE datname = '" . $fixture->name . "'")->fetchAll(PDO::FETCH_COLUMN);
    $child = new Tempest\Process\GenericProcessExecutor()->start(new Tempest\Process\PendingProcess(
        command: [PHP_BINARY, 'tempest', 'operations:work', '--no-interaction'],
        path: dirname(__DIR__, 3),
        environment: [
            'STASHD_OPERATIONS_DSN' => $fixture->config->dsn,
            'STASHD_OPERATIONS_USERNAME' => $fixture->config->username,
            'STASHD_OPERATIONS_PASSWORD' => $fixture->config->password,
            'STASHD_OPERATIONS_SCHEMA' => $fixture->config->schema,
        ],
    ));
    try {
        $deadline = microtime(true) + 5;
        $ready = false;
        $observed = [];
        do {
            $query = $fixture->admin->prepare("SELECT pid, query_start::text FROM pg_stat_activity WHERE datname = ? AND usename = ? AND query = 'COMMIT'");
            $query->execute([$fixture->name, $fixture->name]);
            foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $backend) {
                if (in_array($backend['pid'], $existing, false)) {
                    continue;
                }

                if (isset($observed[$backend['pid']]) && $observed[$backend['pid']] !== $backend['query_start']) {
                    $ready = true;
                }

                $observed[$backend['pid']] = $backend['query_start'];
            }

            if ($ready || ! $child->running) {
                break;
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);

        expect($ready)->toBeTrue($child->output . $child->errorOutput);
        expect($child->running)->toBeTrue($child->output . $child->errorOutput);
        $child->signal(SIGTERM);
        $result = $child->wait();
        expect($result->exitCode)->toBe(0, $result->output . $result->errorOutput);
    } finally {
        if ($child->running) {
            $child->signal(9)->wait();
        }
    }
});

test('an observation arriving during execution remains durable and runs after the admitted snapshot', function (): void {
    $assignment = $this->worker->do(new DepartmentJob('new-demand', 'original'));
    $runtime = $this->department->runtime;
    $runtime->drain('outbox');
    $runtime->drain('activities');
    $runtime->drain('resumes');
    $path = tempnam(sys_get_temp_dir(), 'department-demand-');
    unlink($path);
    $a = department_process($this->department, ['handlerBarrier' => $path]);
    try {
        department_barrier($path, $a);
        expect($this->worker->status($assignment->id)->state)->toBe(AssignmentState::Running);
        $joined = $this->worker->do(new DepartmentJob('new-demand', 'newer'));
        expect($joined->id)->toEqual($assignment->id);
        expect($this->department->connections->application->fetchOne('SELECT count(*) FROM operation_demands WHERE NOT satisfied'))->toBe(2);
        file_put_contents($path . '.release', 'go');
        $result = $a->wait();
        expect($result->exitCode)->toBe(0, $result->errorOutput);
        $runtime->drain();
        expect($this->worker->status($assignment->id)->state)->toBe(AssignmentState::Completed);
        expect($this->department->connections->application->fetchFirstColumn('SELECT value FROM effects ORDER BY id'))->toBe([
            'new-demand:refresh:original',
            'new-demand:refresh:newer',
        ]);
        expect($this->department->connections->application->fetchOne('SELECT count(*) FROM operation_demands WHERE NOT satisfied'))->toBe(0);
    } finally {
        if ($a->running) {
            $a->signal(9)->wait();
        }

        foreach (glob($path . '*') as $file) {
            unlink($file);
        }
    }
});

test('native redelivery cannot start another domain Attempt while the healthy worker is paused', function (): void {
    $assignment = $this->worker->do(new DepartmentJob('live-exclusion'));
    $runtime = $this->department->runtime;
    $runtime->drain('outbox');
    $runtime->drain('activities');
    $runtime->drain('resumes');
    $path = tempnam(sys_get_temp_dir(), 'department-exclusion-');
    unlink($path);
    $a = department_process($this->department, ['handlerBarrier' => $path]);
    try {
        department_barrier($path, $a);
        department_redeliver($this->department);
        $b = department_process($this->department)->wait();
        expect($b->exitCode)->toBe(0, $b->errorOutput);
        expect($a->running)->toBeTrue();
        expect($this->department->connections->application->fetchOne('SELECT count(*) FROM operation_attempts'))->toBe(1);
        expect($this->department->connections->application->fetchOne('SELECT count(*) FROM effects'))->toBe(0);
        file_put_contents($path . '.release', 'go');
        $a->wait();
        $runtime->drain();
        expect($this->worker->status($assignment->id)->state)->toBe(AssignmentState::Completed);
        expect($this->department->connections->application->fetchFirstColumn('SELECT value FROM effects'))->toBe(['live-exclusion:refresh:one']);
        expect($this->department->connections->application->fetchOne('SELECT count(*) FROM operation_attempts'))->toBe(1);
    } finally {
        if ($a->running) {
            $a->signal(9)->wait();
        }

        foreach (glob($path . '*') as $file) {
            unlink($file);
        }
    }
});

test('resolving Tempest database before Worker still rolls back business data and admission together', function (): void {
    $container = DepartmentFixture::boot($this->department->config);
    $database = $container->get(Tempest\Database\Database::class); // Deliberately resolve models first.
    $connections = $container->get(App\Operations\Durable\DurableConnections::class);
    $worker = $container->get(Worker::class);
    $database->execute(new Tempest\Database\Query('CREATE TABLE business_proof (value TEXT NOT NULL)'));
    expect(function () use ($connections, $database, $worker): void {
        $connections->application->transactional(function () use ($database, $worker): void {
            $database->execute(Tempest\Database\query('business_proof')->insert(value: 'business data'));
            $worker->do(new DepartmentJob('business-rollback'));
            throw new RuntimeException('rollback outer business transaction');
        });
    })->toThrow('rollback outer business transaction');
    expect($connections->application->fetchOne('SELECT count(*) FROM business_proof'))->toBe(0);
    expect($connections->application->fetchOne('SELECT count(*) FROM operation_assignments'))->toBe(0);
    expect($connections->application->fetchOne('SELECT count(*) FROM messenger_messages'))->toBe(0);
});

test('changed serialized input retains a distinct Demand under the same work key', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'department-input-');
    file_put_contents($path, 'retained input');
    try {
        $assignment = $this->worker->do(new DepartmentJob('changed-input'));
        $changed = new DepartmentJob('changed-input', custodyPath: $path);
        expect($this->worker->do($changed, Priority::Interactive)->id)->toEqual($assignment->id);
        expect($this->worker->do($changed)->id)->toEqual($assignment->id);
        $db = $this->department->connections->application;
        expect($db->fetchOne('SELECT count(*) FROM operation_demands'))->toBe(2);
        foreach ($db->fetchAllAssociative('SELECT observation, payload FROM operation_demands') as $demand) {
            expect($demand['observation'])->toBe(hash('sha256', $demand['payload']));
        }

        expect($this->worker->status($assignment->id)->priority)->toBe(Priority::Interactive);
        $this->department->runtime->drain();
        expect($this->worker->status($assignment->id)->state)->toBe(AssignmentState::Completed);
        expect($db->fetchFirstColumn('SELECT value FROM effects'))->toBe(['changed-input:refresh:one', 'changed-input:refresh:one']);
        expect($db->fetchOne('SELECT count(*) FROM operation_attempts'))->toBe(2);
        expect($db->fetchOne('SELECT count(*) FROM operation_results'))->toBe(1);
    } finally {
        unlink($path);
    }
});

test('Assignment models and typed execution identities survive serialization and worker restart', function (): void {
    $assignment = $this->worker->do(new DepartmentJob('identifiers'));
    $id = (string) $assignment->id;
    expect($id)->toStartWith('urn:stashd:assignment:');
    $json = json_decode(json_encode($assignment, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    expect($json['id'])->toBe($id);
    expect($json)->not->toHaveKey('uuid');
    $model = Tempest\Database\query(App\Operations\Assignment::class)->select()->where('uuid', $assignment->id->uuidFor(Assignment::class))->first();
    expect($model)->toBeInstanceOf(App\Operations\Assignment::class);
    expect((string) $model->id)->toBe($id);
    expect($model->job_type)->toBe('department.probe.v1');
    expect($this->department->connections->application->fetchOne('SELECT payload FROM operation_demands'))->not->toContain(DepartmentJob::class);
    $result = department_process($this->department)->wait();
    expect($result->exitCode)->toBe(0, $result->errorOutput);
    expect((string) $this->worker->status(Identifier::parse($id))->id)->toBe($id);
    $completed = Tempest\Database\query(App\Operations\Assignment::class)->select()->where('uuid', $assignment->id->uuidFor(Assignment::class))->first();
    expect($completed->active)->toBeFalse();
    expect($this->worker->status($completed->id)->state)->toBe(AssignmentState::Completed);
    $attempt = Tempest\Database\query(App\Operations\Models\Attempt::class)->select()->first();
    $demand = Tempest\Database\query(App\Operations\Models\Demand::class)->select()->first();
    expect((string) $attempt->id)->toStartWith('urn:stashd:attempt:');
    expect((string) $demand->id)->toStartWith('urn:stashd:demand:');
    $context = new App\Operations\DTO\AssignmentContext($assignment->id, $demand->id, $attempt->id);
    $restored = Tempest\Mapper\map(json_decode(Tempest\Mapper\map($context)->toJson(), true, flags: JSON_THROW_ON_ERROR))->to(App\Operations\DTO\AssignmentContext::class);
    expect($restored)->toEqual($context);
    expect(fn () => $this->worker->status($attempt->id))->toThrow(InvalidArgumentException::class);
    expect(fn () => new App\Operations\DTO\AssignmentContext($attempt->id, $demand->id, $attempt->id))->toThrow(InvalidArgumentException::class);
});

test('status reads do not persist terminal failure reconciliation', function (): void {
    $this->department->container->singleton(Tests\Support\Durable\DepartmentControl::class, new Tests\Support\Durable\DepartmentControl(fail: true));
    $assignment = $this->worker->do(new DepartmentJob('read-only-status'));
    $this->department->runtime->drain();
    $db = $this->department->connections->application;
    $before = $db->fetchAllAssociative('SELECT * FROM operation_assignments');
    expect($this->worker->status($assignment->id)->state)->toBe(AssignmentState::Failed);
    expect($this->worker->status($assignment->id)->error)->toContain('identifiable handler failure');
    expect($db->fetchAllAssociative('SELECT * FROM operation_assignments'))->toBe($before);
});

test('historical starts do not describe the next queued demand as running', function (): void {
    $assignment = $this->worker->do(new DepartmentJob('status', 'first'));
    $this->worker->do(new DepartmentJob('status', 'second'));
    $runtime = $this->department->runtime;
    $runtime->drain('outbox');
    $runtime->drain('activities');
    $runtime->drain('resumes');
    $runtime->drain('activities', limit: 1);
    expect($this->department->connections->application->fetchOne('SELECT count(*) FROM operation_attempts'))->toBe(1);
    expect($this->department->connections->application->fetchOne('SELECT count(*) FROM operation_demands WHERE NOT satisfied'))->toBe(1);
    expect($this->worker->status($assignment->id)->state)->toBe(AssignmentState::Queued);
    $runtime->drain();
    expect($this->worker->status($assignment->id)->state)->toBe(AssignmentState::Completed);
});

test('Job-owned keys distinguish composite identity boundaries', function (): void {
    $left = $this->worker->do(new DepartmentJob('asset:refresh', mode: 'complete'));
    $right = $this->worker->do(new DepartmentJob('asset', mode: 'refresh:complete'));
    expect($left->id)->not->toEqual($right->id);
    expect($this->department->connections->application->fetchOne('SELECT count(*) FROM operation_assignments'))->toBe(2);
});

test('a living session without its advisory lock is queued and every handoff mutation is fenced', function (): void {
    $assignment = $this->worker->do(new DepartmentJob('unlocked'));
    $runtime = $this->department->runtime;
    $runtime->drain('outbox');
    $runtime->drain('activities');
    $runtime->drain('resumes');
    $path = tempnam(sys_get_temp_dir(), 'department-unlock-');
    unlink($path);
    $child = department_process($this->department, ['handlerBarrier' => $path]);
    try {
        $owner = department_barrier($path, $child);
        expect($this->worker->status($assignment->id)->state)->toBe(AssignmentState::Running);
        file_put_contents($path . '.revoke', 'revoke');
        $deadline = microtime(true) + 5;
        while (! is_file($path . '.revoked') && microtime(true) < $deadline) {
            usleep(10_000);
        }

        expect(is_file($path . '.revoked'))->toBeTrue($child->errorOutput);
        expect($child->running)->toBeTrue();
        expect($this->department->admin->query('SELECT count(*) FROM pg_stat_activity WHERE pid = ' . (int) $owner['pid'])->fetchColumn())->toBe(1);
        $db = $this->department->connections->application;
        $before = $db->fetchAllAssociative('SELECT * FROM operation_attempts');
        expect($this->worker->status($assignment->id)->state)->toBe(AssignmentState::Queued);
        expect($db->fetchAllAssociative('SELECT * FROM operation_attempts'))->toBe($before);
        expect(json_decode(file_get_contents($path . '.revoked'), true))->toBe([
            'checkpoint' => 'rejected',
            'custody' => 'rejected',
            'reconciliation' => 'rejected',
            'result' => 'rejected',
        ]);
        foreach (['checkpoints', 'custody_receipts', 'reconciliations', 'results'] as $table) {
            expect($db->fetchOne('SELECT count(*) FROM operation_' . $table))->toBe(0);
        }

        department_redeliver($this->department);
        $successor = department_process($this->department)->wait();
        expect($successor->exitCode)->toBe(0, $successor->errorOutput);
        expect($child->running)->toBeTrue();
        file_put_contents($path . '.release', 'release');
        $child->wait();
        expect($this->worker->status($assignment->id)->state)->toBe(AssignmentState::Completed);
    } finally {
        if ($child->running) {
            $child->signal(9)->wait();
        }

        foreach (glob($path . '*') as $file) {
            unlink($file);
        }
    }
});
