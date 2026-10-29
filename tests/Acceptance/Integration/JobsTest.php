<?php

use App\Integration\OperationsConfig;
use App\Operations\JobRuntime;
use Tests\Support\RecordsAndArchives\ArchiveFixture;

require_once dirname(__DIR__, 2) . '/Support/Integration/configuration-cli.php';

test('jobs are admitted once and relayed to a real worker', function (): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database): void {
        configuration_result(configuration_cli($archive, $descriptor, ['integration:init']));
        $runtime = new JobRuntime(new OperationsConfig($descriptor['dsn'], $descriptor['username'], $descriptor['password'], 'configuration_proof'));
        $first = start_configuration_cli($archive, $descriptor, ['job:admit', 'key', 'hello']);
        $second = start_configuration_cli($archive, $descriptor, ['job:admit', 'key', 'hello']);
        $firstResult = finish_configuration_cli($first);
        $secondResult = finish_configuration_cli($second);
        expect($firstResult['code'])->toBe(0)->and($secondResult['code'])->toBe(0);
        $id = json_decode(substr($firstResult['output'], strrpos($firstResult['output'], '{')), true, flags: JSON_THROW_ON_ERROR)['id'];
        $secondId = json_decode(substr($secondResult['output'], strrpos($secondResult['output'], '{')), true, flags: JSON_THROW_ON_ERROR)['id'];
        expect($secondId)->toBe($id);
        expect($runtime->admit('key', 'hello'))->toBe($id);
        expect((int) $database->query("SELECT count(*) FROM configuration_proof.messenger_messages WHERE queue_name = 'outbox'")->fetchColumn())->toBe(1);
        $runtime->work('outbox', true);
        expect((int) $database->query("SELECT count(*) FROM configuration_proof.messenger_messages WHERE queue_name = 'jobs'")->fetchColumn())->toBe(1);
        $database->exec(
            "INSERT INTO configuration_proof.messenger_messages (body, headers, queue_name, created_at, available_at) SELECT body, headers, queue_name, created_at, available_at FROM configuration_proof.messenger_messages WHERE queue_name = 'jobs'",
        );
        $workerOne = start_configuration_cli($archive, $descriptor, ['job:work', 'jobs', '--once']);
        $workerTwo = start_configuration_cli($archive, $descriptor, ['job:work', 'jobs', '--once']);
        expect(finish_configuration_cli($workerOne)['code'])->toBe(0)->and(finish_configuration_cli($workerTwo)['code'])->toBe(0);
        expect($database->query('SELECT status FROM configuration_proof.jobs')->fetchColumn())->toBe('completed');
        $runtime->handle(new \App\Operations\ExecuteJob($id));
        expect((int) $database->query('SELECT attempts FROM configuration_proof.jobs')->fetchColumn())->toBe(1);
        expect(fn () => $runtime->admit('key', 'different'))->toThrow(InvalidArgumentException::class);
        $database->exec("ALTER TABLE configuration_proof.messenger_messages ADD CONSTRAINT reject_outbox CHECK (queue_name <> 'outbox')");
        expect(fn () => $runtime->admit('rollback', 'hello'))->toThrow(\Symfony\Component\Messenger\Exception\TransportException::class);
        expect((int) $database->query("SELECT count(*) FROM configuration_proof.jobs WHERE idempotency_key = 'rollback'")->fetchColumn())->toBe(0);
    });
});

test('relay and worker redeliver after acknowledgement is interrupted', function (): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database): void {
        configuration_result(configuration_cli($archive, $descriptor, ['integration:init']));
        $runtime = new JobRuntime(new OperationsConfig($descriptor['dsn'], $descriptor['username'], $descriptor['password'], 'configuration_proof'));
        $id = $runtime->admit('ack-crash', 'hello');
        $database->exec(
            "CREATE FUNCTION configuration_proof.delay_ack() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN PERFORM pg_sleep(30); RETURN OLD; END'; CREATE TRIGGER delay_ack BEFORE DELETE ON configuration_proof.messenger_messages FOR EACH ROW EXECUTE FUNCTION configuration_proof.delay_ack()",
        );
        foreach (['outbox', 'jobs'] as $queue) {
            $worker = start_configuration_cli($archive, $descriptor, ['job:work', $queue, '--once']);
            $deadline = microtime(true) + 10;
            do {
                usleep(50_000);
                $pending = (int) $database
                    ->query("SELECT count(*) FROM pg_stat_activity WHERE datname = current_database() AND wait_event = 'PgSleep' AND query LIKE 'DELETE FROM messenger_messages%'")
                    ->fetchColumn();
            } while ($pending === 0 && microtime(true) < $deadline);

            expect($pending)->toBe(1);
            expect(proc_terminate($worker['process'], 9))->toBeTrue();
            expect(finish_configuration_cli($worker)['code'])->not->toBe(0);
            $database->exec(
                "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = current_database() AND wait_event = 'PgSleep' AND query LIKE 'DELETE FROM messenger_messages%'",
            );
            $database->exec(
                "ALTER TABLE configuration_proof.messenger_messages DISABLE TRIGGER delay_ack; UPDATE configuration_proof.messenger_messages SET delivered_at = now() - interval '2 minutes' WHERE queue_name = '{$queue}'",
            );
            $runtime = new JobRuntime(new OperationsConfig($descriptor['dsn'], $descriptor['username'], $descriptor['password'], 'configuration_proof'));
            $runtime->work($queue, true);
            $database->exec('ALTER TABLE configuration_proof.messenger_messages ENABLE TRIGGER delay_ack');
        }

        $database->exec('DROP TRIGGER delay_ack ON configuration_proof.messenger_messages');
        $runtime = new JobRuntime(new OperationsConfig($descriptor['dsn'], $descriptor['username'], $descriptor['password'], 'configuration_proof'));
        $runtime->work('jobs', true);
        $row = $database->query("SELECT status, attempts FROM configuration_proof.jobs WHERE id = '{$id}'")->fetch(PDO::FETCH_ASSOC);
        expect($row['status'])
            ->toBe('completed')
            ->and((int) $row['attempts'])
            ->toBe(1);
        expect((int) $database->query('SELECT count(*) FROM configuration_proof.messenger_messages')->fetchColumn())->toBe(0);
    });
});

test('a killed worker is redelivered after its claim expires', function (): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database): void {
        configuration_result(configuration_cli($archive, $descriptor, ['integration:init']));
        $runtime = new JobRuntime(new OperationsConfig($descriptor['dsn'], $descriptor['username'], $descriptor['password'], 'configuration_proof'));
        $id = $runtime->admit('crash', 'hello');
        $runtime->work('outbox', true);
        $database->exec(
            "CREATE FUNCTION configuration_proof.wait_for_completion() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN IF NEW.status = ''completed'' THEN PERFORM pg_sleep(30); END IF; RETURN NEW; END'; CREATE TRIGGER wait_for_completion BEFORE UPDATE ON configuration_proof.jobs FOR EACH ROW EXECUTE FUNCTION configuration_proof.wait_for_completion()",
        );
        $worker = start_configuration_cli($archive, $descriptor, ['job:work', 'jobs', '--once']);
        $deadline = microtime(true) + 10;
        do {
            usleep(50_000);
            $status = $database->query("SELECT status FROM configuration_proof.jobs WHERE id = '{$id}'")->fetchColumn();
        } while ($status !== 'running' && microtime(true) < $deadline);

        expect($status)->toBe('running');
        expect(fn () => $runtime->handle(new \App\Operations\ExecuteJob($id)))->toThrow(\Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException::class);
        expect((int) $database->query("SELECT attempts FROM configuration_proof.jobs WHERE id = '{$id}'")->fetchColumn())->toBe(1);
        expect(proc_terminate($worker['process'], 9))->toBeTrue();
        $result = finish_configuration_cli($worker);
        expect($result['code'])->not->toBe(0);
        $database->exec(
            "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = current_database() AND pid <> pg_backend_pid() AND query LIKE '%UPDATE jobs SET status = ''completed''%'",
        );
        $database->exec(
            "DROP TRIGGER wait_for_completion ON configuration_proof.jobs; UPDATE configuration_proof.jobs SET lease_until = now() - interval '1 minute' WHERE id = '{$id}'; UPDATE configuration_proof.messenger_messages SET delivered_at = now() - interval '2 minutes' WHERE queue_name = 'jobs'",
        );
        $runtime = new JobRuntime(new OperationsConfig($descriptor['dsn'], $descriptor['username'], $descriptor['password'], 'configuration_proof'));
        $runtime->work('jobs', true);
        expect($database->query("SELECT status FROM configuration_proof.jobs WHERE id = '{$id}'")->fetchColumn())->toBe('completed');
    });
});

test('failed relay messages remain available for recovery', function (): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database): void {
        configuration_result(configuration_cli($archive, $descriptor, ['integration:init']));
        $config = new OperationsConfig($descriptor['dsn'], $descriptor['username'], $descriptor['password'], 'configuration_proof');
        $runtime = new JobRuntime($config);
        $id = $runtime->admit('transport-failure', 'hello');
        $database->exec("ALTER TABLE configuration_proof.messenger_messages ADD CONSTRAINT reject_target CHECK (queue_name <> 'jobs')");
        for ($attempt = 0; $attempt < 11; $attempt++) {
            new JobRuntime($config)->work('outbox', true);
            $database->exec("UPDATE configuration_proof.messenger_messages SET available_at = now() - interval '1 minute' WHERE queue_name = 'outbox'");
        }

        expect((int) $database->query("SELECT count(*) FROM configuration_proof.messenger_messages WHERE queue_name = 'failed'")->fetchColumn())->toBe(1);
        expect($database->query("SELECT status FROM configuration_proof.jobs WHERE id = '{$id}'")->fetchColumn())->toBe('queued');
        $messageId = (string) $database->query("SELECT id FROM configuration_proof.messenger_messages WHERE queue_name = 'failed'")->fetchColumn();
        $database->exec(
            "ALTER TABLE configuration_proof.messenger_messages DROP CONSTRAINT reject_target; ALTER TABLE configuration_proof.messenger_messages ADD CONSTRAINT reject_replay CHECK (queue_name <> 'outbox')",
        );
        expect(fn () => $runtime->retryFailed($messageId))->toThrow(\Symfony\Component\Messenger\Exception\TransportException::class);
        expect((int) $database->query("SELECT count(*) FROM configuration_proof.messenger_messages WHERE queue_name = 'failed'")->fetchColumn())->toBe(1);
        $database->exec('ALTER TABLE configuration_proof.messenger_messages DROP CONSTRAINT reject_replay');
        expect(configuration_cli($archive, $descriptor, ['job:retry-message', $messageId])['code'])->toBe(0);
        new JobRuntime($config)->work('outbox', true);
        new JobRuntime($config)->work('jobs', true);
        expect($database->query("SELECT status FROM configuration_proof.jobs WHERE id = '{$id}'")->fetchColumn())->toBe('completed');
        expect((int) $database->query('SELECT count(*) FROM configuration_proof.messenger_messages')->fetchColumn())->toBe(0);
    });
});

test('completion database failure preserves the handler failure budget and recovers', function (): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database): void {
        configuration_result(configuration_cli($archive, $descriptor, ['integration:init']));
        $config = new OperationsConfig($descriptor['dsn'], $descriptor['username'], $descriptor['password'], 'configuration_proof');
        $runtime = new JobRuntime($config);
        $id = $runtime->admit('completion-failure', 'hello');
        $runtime->work('outbox', true);
        $database->exec("ALTER TABLE configuration_proof.jobs ADD CONSTRAINT reject_completion CHECK (status <> 'completed')");
        new JobRuntime($config)->work('jobs', true);
        $row = $database->query("SELECT status, failures, generation FROM configuration_proof.jobs WHERE id = '{$id}'")->fetch(PDO::FETCH_ASSOC);
        expect($row['status'])
            ->toBe('running')
            ->and((int) $row['failures'])
            ->toBe(0)
            ->and((int) $row['generation'])
            ->toBe(0);
        $database->exec(
            "ALTER TABLE configuration_proof.jobs DROP CONSTRAINT reject_completion; UPDATE configuration_proof.jobs SET lease_until = now() - interval '1 minute' WHERE id = '{$id}'; UPDATE configuration_proof.messenger_messages SET available_at = now() - interval '1 minute' WHERE queue_name = 'jobs'",
        );
        new JobRuntime($config)->work('jobs', true);
        expect($database->query("SELECT status FROM configuration_proof.jobs WHERE id = '{$id}'")->fetchColumn())->toBe('completed');
    });
});

test('duplicate old deliveries cannot consume retry attempts', function (): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database): void {
        configuration_result(configuration_cli($archive, $descriptor, ['integration:init']));
        $config = new OperationsConfig($descriptor['dsn'], $descriptor['username'], $descriptor['password'], 'configuration_proof');
        $runtime = new JobRuntime($config);
        $id = $runtime->admit('duplicate-failure', 'fail');
        $runtime->work('outbox', true);
        $database->exec(
            "INSERT INTO configuration_proof.messenger_messages (body, headers, queue_name, created_at, available_at) SELECT body, headers, queue_name, created_at, available_at FROM configuration_proof.messenger_messages WHERE queue_name = 'jobs'",
        );
        new JobRuntime($config)->work('jobs', true);
        $row = $database->query("SELECT status, attempts, failures, generation FROM configuration_proof.jobs WHERE id = '{$id}'")->fetch(PDO::FETCH_ASSOC);
        expect($row['status'])
            ->toBe('queued')
            ->and((int) $row['attempts'])
            ->toBe(1)
            ->and((int) $row['failures'])
            ->toBe(1)
            ->and((int) $row['generation'])
            ->toBe(1);
        expect((int) $database->query("SELECT count(*) FROM configuration_proof.messenger_messages WHERE queue_name = 'outbox'")->fetchColumn())->toBe(1);
        $database->exec("UPDATE configuration_proof.messenger_messages SET available_at = now() - interval '1 minute' WHERE queue_name = 'outbox'");
        new JobRuntime($config)->work('outbox', true);
        new JobRuntime($config)->work('jobs', true);
        expect((int) $database->query("SELECT failures FROM configuration_proof.jobs WHERE id = '{$id}'")->fetchColumn())->toBe(2);
    });
});

test('expired claims are recovered and failures are retried', function (): void {
    with_configuration_database(function (ArchiveFixture $archive, array $descriptor, PDO $database): void {
        configuration_result(configuration_cli($archive, $descriptor, ['integration:init']));
        $runtime = new JobRuntime(new OperationsConfig($descriptor['dsn'], $descriptor['username'], $descriptor['password'], 'configuration_proof'));
        $id = $runtime->admit('expired', 'hello');
        $database->exec("UPDATE configuration_proof.jobs SET status = 'running', claim_token = 'old', lease_until = now() - interval '1 second' WHERE id = '{$id}'");
        $runtime->work('outbox', true);
        $runtime->work('jobs', true);
        expect($database->query("SELECT status FROM configuration_proof.jobs WHERE id = '{$id}'")->fetchColumn())->toBe('completed');
        $failure = $runtime->admit('failure', 'fail');
        $database->exec("UPDATE configuration_proof.jobs SET attempts = 8 WHERE id = '{$failure}'");
        $runtime->work('outbox', true);
        $database->exec("ALTER TABLE configuration_proof.messenger_messages ADD CONSTRAINT reject_retry CHECK (queue_name <> 'outbox')");
        expect(fn () => $runtime->handle(new \App\Operations\ExecuteJob($failure)))->toThrow(\Symfony\Component\Messenger\Exception\TransportException::class);
        $state = $database->query("SELECT status FROM configuration_proof.jobs WHERE id = '{$failure}'")->fetchColumn();
        expect($state)->toBe('running');
        $database->exec(
            "ALTER TABLE configuration_proof.messenger_messages DROP CONSTRAINT reject_retry; UPDATE configuration_proof.jobs SET lease_until = now() - interval '1 minute' WHERE id = '{$failure}'",
        );
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $runtime = new JobRuntime(new OperationsConfig($descriptor['dsn'], $descriptor['username'], $descriptor['password'], 'configuration_proof'));
            $runtime->work('jobs', true);
            $database->exec("UPDATE configuration_proof.messenger_messages SET available_at = now() - interval '1 minute' WHERE queue_name = 'outbox'");
            $runtime = new JobRuntime(new OperationsConfig($descriptor['dsn'], $descriptor['username'], $descriptor['password'], 'configuration_proof'));
            $runtime->work('outbox', true);
        }

        $row = $database->query("SELECT status, attempts, failures FROM configuration_proof.jobs WHERE id = '{$failure}'")->fetch(PDO::FETCH_ASSOC);
        expect($row['status'])
            ->toBe('failed')
            ->and((int) $row['attempts'])
            ->toBe(12)
            ->and((int) $row['failures'])
            ->toBe(3);
    });
});
