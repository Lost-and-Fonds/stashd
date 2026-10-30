<?php

declare(strict_types=1);

use Tests\Support\Ecotone\NativeDeliveryFixture;

beforeEach(function (): void {
    $this->native = new NativeDeliveryFixture();
});

afterEach(function (): void {
    $this->native?->dispose();
});

test('committed intent survives a fresh application and native consumer', function (): void {
    $f = $this->native;
    $id = $f->run('request')['id'];
    expect($f->count('enqueue'))->toBe(1);
    expect($f->rows('SELECT status FROM jobs')[0]['status'])->toBe('queued');
    $f->run('consume');
    expect($f->rows('SELECT status FROM jobs')[0]['status'])->toBe('completed');
    expect($f->rows('SELECT job_id FROM probe_effects'))->toBe([['job_id' => $id]]);
    expect($f->count('job_attempts'))->toBe(1);
    expect($f->count('enqueue'))->toBe(0);
});

test('receipt and claim death recover one interrupted Attempt and preserve identity', function (): void {
    $f = $this->native;
    $id = $f->run('request')['id'];
    $child = $f->start('consume', ['boundary' => 'claimed']);
    $f->paused($child);
    expect($f->rows('SELECT delivery_id FROM enqueue')[0]['delivery_id'])->not->toBeNull();
    expect($f->rows('SELECT status FROM jobs')[0]['status'])->toBe('running');
    expect($f->count('probe_effects'))->toBe(0);
    $f->kill($child);
    $f->expire();
    $f->run('consume');
    expect($f->rows('SELECT job_id FROM probe_effects'))->toBe([['job_id' => $id]]);
    expect($f->rows('SELECT outcome FROM job_attempts ORDER BY started_at'))->toBe([['outcome' => 'interrupted'], ['outcome' => 'completed']]);
    expect($f->rows('SELECT status, failures FROM jobs')[0])->toBe(['status' => 'completed', 'failures' => 0]);
});

test('completion before acknowledgement death cannot repeat effect or Attempt', function (): void {
    $f = $this->native;
    $id = $f->run('request')['id'];
    $child = $f->start('consume', ['boundary' => 'completed']);
    $f->paused($child);
    expect($f->rows('SELECT status FROM jobs')[0]['status'])->toBe('completed');
    expect($f->count('probe_effects'))->toBe(1);
    expect($f->rows('SELECT delivery_id FROM enqueue')[0]['delivery_id'])->not->toBeNull();
    $f->kill($child);
    $f->expire();
    $f->run('consume');
    expect($f->count('job_attempts'))->toBe(1);
    expect($f->rows('SELECT job_id FROM probe_effects'))->toBe([['job_id' => $id]]);
    expect($f->count('enqueue'))->toBe(0);
});

test('duplicates and obsolete generations cannot start incorrect execution', function (): void {
    $f = $this->native;
    $id = $f->run('request')['id'];
    expect($f->run('request', ['priority' => 'interactive'])['id'])->toBe($id);
    expect($f->rows('SELECT generation FROM jobs')[0]['generation'])->toBe(1);
    $f->run('publish', ['id' => $id, 'generation' => 0, 'priority' => 20]);
    $f->run('consume', ['limit' => 1]);
    expect($f->count('job_attempts'))->toBe(0);
    expect($f->count('probe_effects'))->toBe(0);
    $f->run('publish', ['id' => $id, 'generation' => 1]);
    $f->run('consume');
    $f->run('publish', ['id' => $id, 'generation' => 1]);
    $f->run('consume');
    expect($f->count('job_attempts'))->toBe(1);
    expect($f->rows('SELECT job_id FROM probe_effects'))->toBe([['job_id' => $id]]);
});

test('busy duplicate retains delayed intent while the healthy consumer handles other Work', function (): void {
    $f = $this->native;
    $id = $f->run('request')['id'];
    $first = $f->start('consume', ['boundary' => 'claimed']);
    $f->paused($first);
    $f->run('publish', ['id' => $id, 'generation' => 0]);
    $otherScope = $f->database->query('SELECT gen_random_uuid()')->fetchColumn();
    $f->database->exec("INSERT INTO probe_scopes VALUES ('{$otherScope}', 'unrelated')");
    $other = $f->run('request', ['scope' => $otherScope])['id'];
    $f->run('consume');
    expect($f->rows('SELECT job_id FROM probe_effects'))->toBe([['job_id' => $other]]);
    expect($f->count('job_attempts'))->toBe(2);
    expect($f->rows("SELECT failures FROM jobs WHERE id = '{$id}'")[0]['failures'])->toBe(0);
    expect((int) $f->database->query('SELECT count(*) FROM enqueue WHERE delivery_id IS NULL AND delayed_until > EXTRACT(EPOCH FROM clock_timestamp())')->fetchColumn())->toBe(1);
    // A second bounded poll before due time consumes no domain budget or effect.
    $f->run('consume');
    expect($f->count('job_attempts'))->toBe(2);
    expect($f->count('enqueue'))->toBe(2);
    $f->kill($first);
    $f->expire();
    $f->run('consume');
    expect($f->rows("SELECT job_id FROM probe_effects WHERE job_id = '{$id}'"))->toBe([['job_id' => $id]]);
    expect($f->count('job_attempts'))->toBe(3);
    expect($f->count('enqueue'))->toBe(0);
});

test('normal Tempest resolution shares transactions in either order', function (): void {
    $f = $this->native;
    foreach (['database-first', 'jobs-first'] as $order) {
        $result = $f->run('bootstrap', ['order' => $order]);
        expect($result)->toBe([
            'reconnected_same_pid' => true,
            'shared' => true,
            'hidden' => true,
            'enqueue_hidden' => true,
            'native_close_rejected' => true,
            'reconnect_rejected' => true,
        ]);
        expect($f->count('jobs'))->toBe(0);
        expect($f->count('enqueue'))->toBe(0);
        expect($f->count('bootstrap_probe'))->toBe(0);
    }

    $result = $f->run('bootstrap', ['order' => 'jobs-first', 'fault' => 'state']);
    expect($result['state_fault'])->toBeTrue();
    expect($f->count('jobs'))->toBe(0);
    expect($f->count('enqueue'))->toBe(0);
    expect($f->count('bootstrap_probe'))->toBe(0);
    expect($f->run('bootstrap', ['order' => 'database-first', 'commit' => true])['shared'])->toBeTrue();
    expect($f->count('jobs'))->toBe(1);
    expect($f->count('enqueue'))->toBe(1);
    expect($f->count('bootstrap_probe'))->toBe(1);
    // A role-named schema would hide reconnect regressions via PostgreSQL's $user default.
    $schema = $f->database->query('SELECT current_schema()')->fetchColumn();
    $f->database->exec('DELETE FROM enqueue; DELETE FROM jobs; DELETE FROM bootstrap_probe');
    $f->database->exec('ALTER SCHEMA "' . $schema . '" RENAME TO native_shared');
    $f->database->exec('SET search_path TO native_shared');
    expect($f->run('bootstrap', ['order' => 'jobs-first', 'schema' => 'native_shared'])['shared'])->toBeTrue();
    expect($f->count('jobs'))->toBe(0);
    expect($f->count('enqueue'))->toBe(0);
});

test('queue insertion rejection rolls back admission and permits readmission', function (): void {
    $f = $this->native;
    $f->database->exec('ALTER TABLE enqueue ADD CONSTRAINT reject_invitation CHECK (false)');
    $result = $f->start('request')->wait();
    expect($result->exitCode)->toBe(1);
    expect($result->errorOutput)->toContain('23514');
    expect($f->count('jobs'))->toBe(0);
    expect($f->count('enqueue'))->toBe(0);
    $f->database->exec('ALTER TABLE enqueue DROP CONSTRAINT reject_invitation');
    $f->run('request');
    $f->run('consume');
    expect($f->count('probe_effects'))->toBe(1);
});

test('transport retries exhaust into native dead letters without domain failure', function (): void {
    $f = $this->native;
    $id = $f->run('request')['id'];
    $f->database->exec("CREATE FUNCTION reject_claim() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''native claim fault'' USING ERRCODE = ''23514''; END'");
    $f->database->exec('CREATE TRIGGER reject_claim BEFORE UPDATE ON jobs FOR EACH ROW EXECUTE FUNCTION reject_claim()');
    // Four actual native deliveries: first failure, three configured delayed retries.
    for ($i = 0; $i < 4; $i++) {
        $f->run('consume');
        expect($f->rows('SELECT status, failures FROM jobs')[0])->toBe(['status' => 'queued', 'failures' => 0]);
        expect($f->count('job_attempts'))->toBe(0);
        if ($i < 3) {
            expect($f->count('enqueue'))->toBe(1);
            $f->expire();
        }
    }

    expect($f->count('ecotone_error_messages'))->toBe(1);
    expect($f->count('enqueue'))->toBe(0);
    expect($f->rows('SELECT payload FROM ecotone_error_messages')[0]['payload'])->toContain($id);
    $f->database->exec('DROP TRIGGER reject_claim ON jobs');
    $f->run('replay');
    expect($f->count('ecotone_error_messages'))->toBe(0);
    $f->run('consume');
    expect($f->rows('SELECT job_id FROM probe_effects'))->toBe([['job_id' => $id]]);
    expect($f->rows('SELECT status FROM jobs')[0]['status'])->toBe('completed');
});

test('domain handler failure has its own Attempt budget and truthful terminal Job', function (): void {
    $f = $this->native;
    $f->run('request', ['observation' => 'domain-fail']);
    for ($i = 0; $i < 3; $i++) {
        $f->run('consume');
        $f->expire();
    }

    expect($f->rows('SELECT status, failures FROM jobs')[0])->toBe(['status' => 'failed', 'failures' => 3]);
    expect($f->rows('SELECT outcome FROM job_attempts'))->toBe(array_fill(0, 3, ['outcome' => 'failed']));
    expect($f->count('probe_effects'))->toBe(0);
    expect($f->count('ecotone_error_messages'))->toBe(0);
    expect($f->count('enqueue'))->toBe(0);
});

test('death at the precommit database barrier leaves no partial intent', function (): void {
    $f = $this->native;
    $f->database->exec('SELECT pg_advisory_lock(82, 1)');
    $f->database->exec("CREATE FUNCTION pause_native_commit() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN PERFORM pg_advisory_xact_lock(82, 1); RETURN NEW; END'");
    $f->database->exec('CREATE CONSTRAINT TRIGGER pause_native_commit AFTER INSERT ON enqueue DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION pause_native_commit()');
    $child = $f->start('request');
    $deadline = microtime(true) + 8;
    $backend = false;
    do {
        $f->database->exec('SELECT pg_stat_clear_snapshot()');
        $backend = $f
            ->database
            ->query("SELECT pid FROM pg_stat_activity WHERE datname = current_database() AND pid <> pg_backend_pid() AND wait_event = 'advisory' AND xact_start IS NOT NULL")
            ->fetchColumn();
        if ($backend !== false) {
            break;
        }

        expect($child->running)->toBeTrue('Admission child exited before the database barrier: ' . $child->errorOutput);
        usleep(10_000);
    } while (microtime(true) < $deadline);

    expect($backend)->not->toBeFalse('Admission never reached its precommit advisory-lock barrier.');
    expect($f->count('jobs'))->toBe(0);
    expect($f->count('enqueue'))->toBe(0);
    $f->kill($child);
    // End the blocked server transaction too; client death alone cannot undo a COMMIT already received by PostgreSQL.
    $f->database->query('SELECT pg_terminate_backend(' . (int) $backend . ')');
    $f->database->exec('SELECT pg_advisory_unlock(82, 1)');
    $f->database->exec('DROP TRIGGER pause_native_commit ON enqueue');
    expect($f->count('jobs'))->toBe(0);
    expect($f->count('enqueue'))->toBe(0);
    $f->run('request');
    $f->run('consume');
    expect($f->count('probe_effects'))->toBe(1);
});
