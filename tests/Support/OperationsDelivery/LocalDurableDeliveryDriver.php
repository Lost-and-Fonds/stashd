<?php

declare(strict_types=1);

namespace Tests\Support\OperationsDelivery;

use App\Operations\ExecuteJob;
use PDO;
use PHPUnit\Framework\Assert;
use RuntimeException;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Tempest\DateTime\Duration;
use Tempest\Process\GenericProcessExecutor;
use Tempest\Process\PendingProcess;
use Tempest\Process\ProcessResult;
use Tests\Support\Operations\LocalOperationsDriver;

/**
 * Challenge the production runtime with real PostgreSQL faults and killed children.
 *
 * All control state survives fresh driver objects in the private fixture. Native
 * Messenger rows are decoded independently; probe effects never hide duplicates.
 */
final class LocalDurableDeliveryDriver implements DurableDeliveryDriver
{
    /**
     * Observe real persisted state or run a bounded production relay or worker.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function run(string $action, array $arguments): array
    {
        $workspace = $arguments['workspace'];
        if (realpath($workspace) !== $workspace || ! preg_match('~/stashd-archives-[a-f0-9]{24}/operations-delivery$~D', $workspace)) {
            throw new RuntimeException('Delivery tests require their private disposable workspace.');
        }

        if ($action === 'fixture.dispose' || $action === 'delivery.restart') {
            try {
                foreach (glob($workspace . '/delivery-*.request') as $path) {
                    $this->stop(substr($path, 0, -8));
                }
            } finally {
                if ($action === 'fixture.dispose') {
                    new LocalOperationsDriver()->run($action, $arguments);
                }
            }

            // Each child opens a new runtime; no connection or process is reused.
            return [];
        }

        if (in_array($action, ['probe.prepare', 'work.request', 'jobs.for_work', 'jobs.read', 'probe.effects'], true)) {
            return new LocalOperationsDriver()->run($action, $arguments);
        }

        $installation = json_decode(file_get_contents($workspace . '/installation.json'), true, flags: JSON_THROW_ON_ERROR);
        $descriptor = $installation['descriptor'];
        $database = LocalOperationsDriver::connect($descriptor);

        if ($action === 'delivery.arm_fault' || $action === 'delivery.release_fault') {
            $queue = match ($arguments['point']) {
                'outbox-insert-rejected' => 'outbox',
                'forward-target-unavailable' => 'jobs',
                default => throw new RuntimeException('Unknown delivery fault.'),
            };
            $database->exec('DROP TRIGGER IF EXISTS delivery_reject ON messenger_messages');
            if ($action === 'delivery.arm_fault') {
                $database->exec('DROP SEQUENCE IF EXISTS delivery_rejections');
                $database->exec('CREATE SEQUENCE delivery_rejections');
                $database->exec(
                    "CREATE OR REPLACE FUNCTION delivery_reject() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.queue_name = '{$queue}' THEN PERFORM nextval('delivery_rejections'); RAISE EXCEPTION 'OPS-C02 rejected {$queue} write' USING ERRCODE = 'P0001'; END IF; RETURN NEW; END \$\$",
                );
                $database->exec('CREATE TRIGGER delivery_reject BEFORE INSERT ON messenger_messages FOR EACH ROW EXECUTE FUNCTION delivery_reject()');
            }

            return [];
        }

        if ($action === 'work.request_with_expected_failure') {
            $result = $this->child($descriptor, ['action' => 'request', 'work' => $arguments['work']]);
            Assert::assertNotSame(0, $result->exitCode);
            Assert::assertStringContainsString('OPS-C02 rejected outbox write', $result->errorOutput);
            Assert::assertTrue((bool) $database->query('SELECT is_called FROM delivery_rejections')->fetchColumn());

            $this->evidence(['rejected_insert' => 'outbox', 'child_exit' => $result->exitCode]);

            return ['failure_kind' => 'outbox_write_rejected'];
        }

        if ($action === 'delivery.relay_once') {
            $this->successful($descriptor, ['action' => 'work', 'receiver' => 'outbox', 'limit' => 1]);
            Assert::assertTrue((bool) $database->query('SELECT is_called FROM delivery_rejections')->fetchColumn(), 'No destination write was rejected.');
            Assert::assertSame([], $this->messages($database, ['jobs']));
            Assert::assertNotEmpty($this->messages($database, ['outbox', 'failed']));

            $this->evidence(['rejected_insert' => 'jobs', 'recoverable_job_ids' => array_column($this->messages($database, ['outbox', 'failed']), 'job_id')]);

            return ['outcome' => 'target_unavailable'];
        }

        if (in_array($action, ['delivery.outbox_for_work', 'delivery.forwarded_for_job', 'delivery.recoverable_intents'], true)) {
            $queues = match ($action) {
                'delivery.outbox_for_work' => ['outbox'],
                'delivery.forwarded_for_job' => ['jobs', 'jobs_interactive'],
                default => ['outbox', 'failed'],
            };
            $rows = $this->messages($database, $queues);
            if (isset($arguments['job_id'])) {
                $rows = array_filter($rows, static fn (array $row): bool => $row['job_id'] === $arguments['job_id']);
            } else {
                $jobs = new LocalOperationsDriver()->run('jobs.for_work', $arguments)['jobs'];
                $ids = array_column($jobs, 'id');
                foreach ($rows as $row) {
                    $exists = $database->prepare('SELECT id FROM jobs WHERE id = ?');
                    $exists->execute([$row['job_id']]);
                    Assert::assertNotFalse($exists->fetchColumn(), 'An orphan outbox envelope has no persisted Job.');
                }

                $rows = array_filter($rows, static fn (array $row): bool => in_array($row['job_id'], $ids, true));
            }

            return ['job_ids' => array_values(array_column($rows, 'job_id'))];
        }

        if ($action === 'delivery.run_to_boundary') {
            return $this->boundary($database, $descriptor, $workspace, $arguments);
        }

        if ($action === 'delivery.kill_at_boundary') {
            $control = json_decode(file_get_contents($workspace . '/boundary.json'), true, flags: JSON_THROW_ON_ERROR);
            Assert::assertSame($arguments['boundary'], $control['boundary']);
            $result = $this->stop($control['prefix']);
            Assert::assertTrue($result['killed']);
            Assert::assertSame(137, $result['exit'], $result['error']);
            // PostgreSQL may still be asleep after its client's SIGKILL. Cancel
            // that statement so the disconnected backend promptly releases locks.
            $cancel = $database->prepare('SELECT pg_cancel_backend(pid) FROM pg_stat_activity WHERE pid = ? AND application_name = ?');
            $cancel->execute([$control['backend'], basename($control['prefix'])]);
            $this->await(function () use ($database, $control): bool {
                $statement = $database->prepare('SELECT count(*) FROM pg_stat_activity WHERE pid = ? AND application_name = ?');
                $statement->execute([$control['backend'], basename($control['prefix'])]);

                return (int) $statement->fetchColumn() === 0;
            });
            $database->exec('DROP TRIGGER delivery_pause ON messenger_messages');
            $this->evidence(['boundary' => $control['boundary'], 'pid' => $control['pid'], 'backend' => $control['backend'], 'exit' => $result['exit']]);

            return ['terminated_process' => true];
        }

        if ($action === 'delivery.expire_visibility' || $action === 'delivery.retry_stalled_forwarding') {
            $queues = $action === 'delivery.expire_visibility' ? [$arguments['transport']] : ['outbox'];
            $rows = array_filter($this->messages($database, $queues), static fn (array $row): bool => $row['job_id'] === $arguments['job_id']);
            Assert::assertNotEmpty($rows, 'Recovery must retain an actual native envelope.');
            $this->evidence([
                'recovery' => $action,
                'native_envelopes' => array_map(static fn (array $row): array => [
                    'id' => $row['id'],
                    'job_id' => $row['job_id'],
                    'queue' => $row['queue_name'],
                    'delivered_at' => $row['delivered_at'],
                ], array_values($rows)),
            ]);
            foreach ($rows as $row) {
                if ($action === 'delivery.expire_visibility') {
                    Assert::assertNotNull($row['delivered_at'], 'Cannot expire an invitation that was never received.');
                }

                $statement = $database->prepare(
                    "UPDATE messenger_messages SET delivered_at = clock_timestamp() - interval '120 seconds', available_at = clock_timestamp() - interval '120 seconds' WHERE id = ?",
                );
                $statement->execute([$row['id']]);
            }

            return [];
        }

        if ($action === 'delivery.forward_in_order') {
            $rows = $this->messages($database, ['outbox']);
            Assert::assertSame(count($arguments['job_ids']), count($rows));
            $database->exec("UPDATE messenger_messages SET available_at = clock_timestamp() + interval '1 day' WHERE queue_name = 'outbox'");
            foreach ($arguments['job_ids'] as $id) {
                $matches = array_values(array_filter($rows, static fn (array $row): bool => $row['job_id'] === $id));
                Assert::assertCount(1, $matches);
                $statement = $database->prepare("UPDATE messenger_messages SET available_at = clock_timestamp() - interval '1 second' WHERE id = ?");
                $statement->execute([$matches[0]['id']]);
                $this->successful($descriptor, ['action' => 'work', 'receiver' => 'outbox', 'limit' => 1]);
            }

            $observed = array_column($this->messages($database, ['jobs']), 'job_id');
            $this->evidence(['forward_order' => $observed]);

            return ['observed_forwarded_job_ids' => $observed];
        }

        if ($action === 'delivery.run_until_settled') {
            try {
                $this->await(function () use ($database, $descriptor): bool {
                    $this->successful($descriptor, ['action' => 'work', 'receiver' => 'outbox']);
                    $this->successful($descriptor, ['action' => 'work', 'receiver' => 'jobs']);

                    return (
                        (int) $database->query('SELECT count(*) FROM messenger_messages')->fetchColumn() === 0
                        && (int) $database->query("SELECT count(*) FROM jobs WHERE status <> 'completed'")->fetchColumn() === 0
                    );
                });
            } catch (RuntimeException $error) {
                if ($error->getMessage() !== 'Delivery control did not reach its required state before the deadline.') {
                    throw $error;
                }

                // Independent persisted state distinguishes lost intent from a wrapper timeout.
                $state = [
                    'jobs' => $database->query('SELECT id, status FROM jobs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
                    'envelopes' => (int) $database->query('SELECT count(*) FROM messenger_messages')->fetchColumn(),
                    'effects' => (int) $database->query('SELECT count(*) FROM probe_effects')->fetchColumn(),
                ];
                throw new RuntimeException('Delivery settlement timeout: ' . json_encode($state, JSON_THROW_ON_ERROR));
            }

            $this->evidence(['effects' => $database->query('SELECT job_id FROM probe_effects ORDER BY sequence')->fetchAll(PDO::FETCH_COLUMN)]);

            return [];
        }

        throw new RuntimeException('Unsupported delivery action: ' . $action);
    }

    /**
     * Decode native transport records in physical insertion order, without deduplication.
     *
     * @param list<string> $queues
     * @return list<array<string, mixed>>
     */
    private function messages(PDO $database, array $queues): array
    {
        $rows = [];
        foreach ($database->query('SELECT id, body, headers, queue_name, delivered_at FROM messenger_messages ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (! in_array($row['queue_name'], $queues, true)) {
                continue;
            }

            $message = new PhpSerializer()->decode(['body' => $row['body'], 'headers' => json_decode($row['headers'], true, flags: JSON_THROW_ON_ERROR)])->getMessage();
            Assert::assertInstanceOf(ExecuteJob::class, $message);
            $rows[] = [...$row, 'job_id' => $message->id];
        }

        return $rows;
    }

    /**
     * Run a fresh production runtime with restricted credentials over stdin.
     */
    private function child(array $descriptor, array $request): ProcessResult
    {
        return new GenericProcessExecutor()->run(new PendingProcess(
            command: [PHP_BINARY, __DIR__ . '/runtime-process.php'],
            path: dirname(__DIR__, 3),
            input: json_encode(['descriptor' => $descriptor, ...$request], JSON_THROW_ON_ERROR),
            timeout: Duration::seconds(20),
            environment: ['ENVIRONMENT' => 'testing'],
        ));
    }

    /**
     * Reject any unexpected child failure instead of converting it to a receipt.
     */
    private function successful(array $descriptor, array $request): void
    {
        $result = $this->child($descriptor, $request);
        Assert::assertSame(0, $result->exitCode, $result->errorOutput);
    }

    /**
     * Hold a real commit or acknowledgment and independently observe its database state.
     */
    private function boundary(PDO $database, array $descriptor, string $workspace, array $arguments): array
    {
        $boundary = $arguments['boundary'];
        $admission = $boundary === 'admission-before-commit';
        $queue = match ($boundary) {
            'admission-before-commit', 'relay-forwarded-before-ack' => 'outbox',
            'worker-completed-before-ack' => 'jobs',
            default => throw new RuntimeException('Unknown crash boundary.'),
        };
        if ($queue === 'jobs') {
            $this->successful($descriptor, ['action' => 'work', 'receiver' => 'outbox']);
        }

        $record = $admission ? 'NEW' : 'OLD';
        $database->exec(
            "CREATE FUNCTION delivery_pause() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF {$record}.queue_name = '{$queue}' THEN PERFORM pg_sleep(30); END IF; RETURN {$record}; END \$\$",
        );
        $database->exec(
            $admission
                ? 'CREATE CONSTRAINT TRIGGER delivery_pause AFTER INSERT ON messenger_messages DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION delivery_pause()'
                : 'CREATE TRIGGER delivery_pause BEFORE DELETE ON messenger_messages FOR EACH ROW EXECUTE FUNCTION delivery_pause()',
        );
        $prefix = $workspace . '/delivery-' . bin2hex(random_bytes(8));
        $request = $admission
            ? ['action' => 'request', 'work' => $arguments['work']]
            : ['action' => 'work', 'receiver' => $queue === 'outbox' ? 'outbox' : 'jobs', 'limit' => 1];
        file_put_contents($prefix . '.request', json_encode(['descriptor' => $descriptor, ...$request], JSON_THROW_ON_ERROR));
        chmod($prefix . '.request', 0o600);
        $launch = new GenericProcessExecutor()->run(new PendingProcess(
            command: escapeshellarg(PHP_BINARY)
            . ' '
            . escapeshellarg(__DIR__ . '/supervisor.php')
            . ' '
            . escapeshellarg($prefix)
            . ' </dev/null >'
            . escapeshellarg($prefix . '.log')
            . ' 2>&1 &',
            timeout: Duration::seconds(2),
        ));
        Assert::assertSame(0, $launch->exitCode, $launch->errorOutput);
        $backend = null;
        $this->await(function () use ($database, $prefix, $admission, &$backend): bool {
            if (is_file($prefix . '.done')) {
                throw new RuntimeException('Child exited before its boundary: ' . file_get_contents($prefix . '.done'));
            }

            $statement = $database->prepare(
                "SELECT pid, query FROM pg_stat_activity WHERE application_name = ? AND state = 'active' AND xact_start IS NOT NULL AND wait_event = 'PgSleep'",
            );
            $statement->execute([basename($prefix)]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                return false;
            }

            Assert::assertStringContainsString($admission ? 'COMMIT' : 'DELETE', strtoupper($row['query']));
            $backend = (int) $row['pid'];

            return true;
        });
        $pid = (int) file_get_contents($prefix . '.pid');
        Assert::assertGreaterThan(1, $pid);
        if (! $admission) {
            $rows = $this->messages($database, [$queue]);
            $matches = array_values(array_filter($rows, static fn (array $row): bool => $row['job_id'] === $arguments['job_id']));
            Assert::assertCount(1, $matches);
            Assert::assertNotNull($matches[0]['delivered_at']);
        }

        file_put_contents($workspace . '/boundary.json', json_encode(compact('prefix', 'boundary', 'backend', 'pid'), JSON_THROW_ON_ERROR));

        return ['reached' => true];
    }

    /**
     * Ask the owning supervisor to SIGKILL and reap its actual child before returning.
     */
    private function stop(string $prefix): array
    {
        file_put_contents($prefix . '.kill', 'kill');
        $this->await(static fn (): bool => is_file($prefix . '.reaped'));

        return json_decode(file_get_contents($prefix . '.done'), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Fail a missing observable transition within a bounded deadline.
     */
    private function await(callable $condition): void
    {
        $deadline = microtime(true) + 10;
        do {
            if ($condition()) {
                return;
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException('Delivery control did not reach its required state before the deadline.');
    }

    /**
     * Retain non-secret crash and transport observations outside the disposable fixture.
     */
    private function evidence(array $record): void
    {
        file_put_contents(dirname(__DIR__, 3) . '/tmp/operations-delivery-evidence.jsonl', json_encode($record, JSON_THROW_ON_ERROR) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
