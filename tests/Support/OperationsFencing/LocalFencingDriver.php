<?php

declare(strict_types=1);

namespace Tests\Support\OperationsFencing;

use PDO;
use RuntimeException;
use Tempest\DateTime\Duration;
use Tempest\Process\GenericProcessExecutor;
use Tempest\Process\PendingProcess;
use Tests\Support\Operations\LocalOperationsDriver;

/**
 * Supervise real native consumers; only SQL and filesystem observations supply receipts.
 *
 * Static storage owns process handles only, surviving the fixture's fresh adapters.
 * Authority remains entirely in production services and disposable PostgreSQL.
 */
final class LocalFencingDriver implements FencingDriver
{
    private static array $children = [];
    private PDO $db;
    private array $descriptor;
    private string $workspace;

    /**
     * Dispatch a bounded scenario action without retaining domain state in this adapter.
     */
    public function run(string $action, array $arguments): array
    {
        $result = $this->perform($action, $arguments);
        $evidence = getenv('STASHD_FENCING_EVIDENCE');
        if (is_string($evidence) && $evidence !== '') {
            $line = json_encode(['action' => $action, 'result' => $result], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $line = str_replace($arguments['workspace'], '<fixture>', $line);
            file_put_contents($evidence, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        }

        return $result;
    }

    /**
     * Carry out the action using production boundaries and independent observers.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function perform(string $action, array $arguments): array
    {
        $this->workspace = $arguments['workspace'];
        $allocator = new LocalOperationsDriver();
        if ($action === 'fixture.dispose') {
            foreach (self::$children[$this->workspace] ?? [] as $child) {
                $child['process']->stop(0, 9);
                if ($child['process']->running) {
                    throw new RuntimeException('A fencing worker survived teardown.');
                }
            }

            unset(self::$children[$this->workspace]);
            $allocator->run($action, $arguments);

            return [];
        }

        if (! is_file($this->workspace . '/installation.json')) {
            $allocator->run('probe.prepare', ['workspace' => $this->workspace, 'name' => 'fencing-installation']);
            $d = json_decode(file_get_contents($this->workspace . '/installation.json'), true, flags: JSON_THROW_ON_ERROR)['descriptor'];
            $db = LocalOperationsDriver::connect($d);
            $db->exec('CREATE TABLE decision_audit (sequence BIGINT GENERATED ALWAYS AS IDENTITY, at TIMESTAMPTZ NOT NULL, token TEXT)');
            $db->exec(
                "CREATE FUNCTION audit_decision() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN INSERT INTO decision_audit(at, token) VALUES (clock_timestamp(), NEW.claim_token); RETURN NEW; END'",
            );
            $db->exec('CREATE TRIGGER audit_decision BEFORE UPDATE ON jobs FOR EACH ROW EXECUTE FUNCTION audit_decision()');
            $db->exec('CREATE TABLE publication_audit (sequence BIGINT GENERATED ALWAYS AS IDENTITY, record JSONB NOT NULL)');
            $db->exec("CREATE FUNCTION audit_enqueue() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN INSERT INTO publication_audit(record) VALUES (to_jsonb(NEW)); RETURN NEW; END'");
            $db->exec('CREATE TRIGGER audit_enqueue AFTER INSERT ON enqueue FOR EACH ROW EXECUTE FUNCTION audit_enqueue()');
        }

        $this->descriptor = json_decode(file_get_contents($this->workspace . '/installation.json'), true, flags: JSON_THROW_ON_ERROR)['descriptor'];
        $this->db = LocalOperationsDriver::connect($this->descriptor);
        $job = $arguments['job_id'] ?? null;
        $attempt = $arguments['attempt_id'] ?? null;
        if ($attempt !== null) {
            $job = $this->row('SELECT job_id FROM job_attempts WHERE id = ?', [$attempt])['job_id'];
        }

        if ($action === 'job.prepare') {
            return $this->finish($this->start('prepare', ['name' => $arguments['name']]));
        }

        if ($action === 'job.read') {
            return ['job' => $this->row(
                'SELECT id, status AS state, claim_token AS claim_attempt_id, lease_until AS lease_expires_at, failures AS domain_failures, generation AS delivery_generation, error FROM jobs WHERE id = ?',
                [$job],
            )];
        }

        if ($action === 'attempts.read') {
            $this->db->beginTransaction();
            $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            $row = $this->row("SELECT claim_token, lease_until > clock_timestamp() AND status = 'running' AS valid FROM jobs WHERE id = ?", [$job]);

            $result = [
                'attempts' => $this->rows('SELECT * FROM job_attempts WHERE job_id = ? ORDER BY started_at, id', [$job]),
                'current_attempt_id' => $row['claim_token'],
                'database_lease_valid' => (bool) $row['valid'],
                'authoritative_attempt_ids' => $row['valid'] ? [$row['claim_token']] : [],
            ];
            $this->db->commit();

            return $result;
        }

        if ($action === 'transport.publications') {
            return ['publications' => $this->rows('SELECT * FROM publication_audit ORDER BY sequence')];
        }

        if ($action === 'transport.expire_delivery') {
            $this->expire();

            return [];
        }

        if ($action === 'worker.start_paused' || $action === 'worker.recover') {
            if ($action === 'worker.recover') {
                $this->expire();
            }

            $key = $this->start('consume');

            return $this->entered($key, $job);
        }

        if ($action === 'worker.try_redelivery') {
            $before = $this->row('SELECT claim_token, lease_until > clock_timestamp() AS valid FROM jobs WHERE id = ?', [$job]);
            $key = $this->start('consume');
            $this->finish($key);
            $after = $this->row('SELECT claim_token FROM jobs WHERE id = ?', [$job]);

            return [
                'received_native_message' => $this->nativeReceived($key),
                'acquired_attempt' => ! hash_equals((string) $before['claim_token'], (string) $after['claim_token']),
                'database_lease_valid' => $before['valid'],
                'original_worker_alive' => $this->alive($before['claim_token']),
            ];
        }

        if ($action === 'lease.await_expiration' || $action === 'lease.await_original_deadline') {
            $deadline = $arguments['original_deadline'] ?? $this->row('SELECT lease_until FROM jobs WHERE id = ?', [$job])['lease_until'];
            $this->await(fn () => $this->row('SELECT clock_timestamp() > ?::timestamptz AS passed', [$deadline])['passed']);

            return [
                'database_lease_expired' => true,
                'original_worker_alive' => $this->alive($attempt),
                'original_deadline_passed' => true,
                'renewed_lease_still_valid' => $this->row('SELECT lease_until > clock_timestamp() AS valid FROM jobs WHERE id = ?', [$job])['valid'],
            ];
        }

        if ($action === 'lease.renew') {
            // Leave enough renewed margin beyond the initial deadline for a fresh consumer.
            usleep(1_500_000);
            $before = $this->row('SELECT lease_until FROM jobs WHERE id = ?', [$job]);
            $result = $this->command($attempt, ['action' => 'renew']);
            $after = $this->row("SELECT lease_until, lease_until <= clock_timestamp() + interval '4 seconds' AS db_clock FROM jobs WHERE id = ?", [$job]);

            return [...$result, 'new_deadline_later' => $after['lease_until'] > $before['lease_until'], 'used_database_clock' => $after['db_clock']];
        }

        if ($action === 'host.corrupt_custody') {
            $row = $this->row("SELECT path FROM execution_artifacts WHERE job_id = ? AND kind = 'custody'", [$job]);
            file_put_contents($row['path'], 'corrupted');

            return [];
        }

        if ($action === 'host.slow_write' || $action === 'host.blocking_tool') {
            return $this->command($attempt, ['action' => $action === 'host.slow_write' ? 'slow_checkpoint' : 'heartbeat']);
        }

        if ($action === 'host.try_write' || $action === 'host.try_publish' || $action === 'host.reconcile_custody') {
            $kind = $arguments['kind'] ?? ($action === 'host.reconcile_custody' ? 'reconcile' : 'result');
            $result = $this->command($attempt, ['action' => $kind, 'value' => $arguments['value'] ?? $arguments['coverage'] ?? '']);
            if ($action === 'host.try_publish' && ($result['accepted'] ?? false)) {
                $this->command($attempt, ['action' => 'complete']);
            }

            return $result;
        }

        if ($action === 'host.records') {
            $result = [];
            foreach (['checkpoints' => 'checkpoint', 'custody' => 'custody', 'results' => 'result'] as $name => $kind) {
                $result[$name] = $this->rows('SELECT * FROM execution_artifacts WHERE job_id = ? AND kind = ? ORDER BY id', [$job, $kind]);
            }

            return $result;
        }

        if ($action === 'worker.try_complete') {
            $this->command($attempt, ['action' => 'complete']);
            $row = $this->row('SELECT outcome FROM job_attempts WHERE id = ?', [$attempt]);

            return ['accepted' => $row['outcome'] === 'completed'];
        }

        if ($action === 'worker.fail') {
            $alive = $this->alive($attempt);
            $key = $this->key($attempt);
            $this->command($attempt, ['action' => 'fail', 'message' => $arguments['message']]);

            return [
                'original_worker_alive_at_failure' => $alive,
                'handler_error_observed' => file_get_contents($key . '.thrown') === $arguments['message'],
                'failure_transition_reached' => is_file($key . '.settled'),
                'job_failure_accepted' => $this->row('SELECT outcome FROM job_attempts WHERE id = ?', [$attempt])['outcome'] === 'failed',
                'native_delivery_settled' => is_file($key . '.settled'),
            ];
        }

        if ($action === 'worker.crash') {
            $key = $this->key($attempt);
            self::$children[$this->workspace][$key]['process']->stop(0, 9);

            return ['terminated_process' => ! $this->alive($attempt)];
        }

        if ($action === 'worker.release_all') {
            $this->db->prepare('DELETE FROM enqueue WHERE body LIKE ?')->execute(['%' . $job . '%']);
            foreach (self::$children[$this->workspace] ?? [] as $child) {
                $child['process']->stop(0, 9);
            }

            return [];
        }

        if ($action === 'host.race_write') {
            $key = $this->key($attempt);
            $valid = $this->row('SELECT lease_until > clock_timestamp() AS valid FROM jobs WHERE id = ?', [$job])['valid'];
            $this->send($key, ['action' => $arguments['kind'], 'value' => $arguments['value'], 'barrier' => true]);
            $this->await(fn () => is_file($key . '.boundary'));
            $this->run('lease.await_expiration', $arguments);
            $b = $this->run('worker.recover', $arguments);
            touch($key . '.release');
            $result = $this->response($key);

            return [
                ...$result,
                'write_boundary_reached_while_valid' => $valid,
                'original_worker_alive' => $this->alive($attempt),
                'successor_claimed_before_write' => $b['attempt_id'] !== $attempt,
                'successor_attempt_id' => $b['attempt_id'],
            ];
        }

        if ($action === 'lease.compete_with_takeover') {
            return $this->renewalRace($job, $attempt, $arguments['first_mutation']);
        }

        if ($action === 'worker.compete_for_claim') {
            return $this->compete($job);
        }

        throw new RuntimeException('Unsupported fencing action: ' . $action);
    }

    /**
     * Launch normal application bootstrap in a separately supervised process.
     */
    private function start(string $action, array $args = []): string
    {
        $key = $this->workspace . '/worker-' . bin2hex(random_bytes(8));
        $process = new GenericProcessExecutor()->start(new PendingProcess(
            command: [PHP_BINARY, __DIR__ . '/worker.php'],
            input: json_encode(['descriptor' => $this->descriptor, 'prefix' => $key, 'action' => $action, ...$args], JSON_THROW_ON_ERROR),
            path: dirname(__DIR__, 3),
            timeout: Duration::seconds(110),
            environment: ['ENVIRONMENT' => 'testing', 'PGAPPNAME' => basename($key)],
        ));
        self::$children[$this->workspace][$key] = ['process' => $process];

        return $key;
    }

    /**
     * Require an actual successful child exit rather than accepting a timeout.
     */
    private function finish(string $key): array
    {
        $r = self::$children[$this->workspace][$key]['process']->wait();
        if ($r->failed()) {
            throw new RuntimeException('Fencing child failed: ' . $r->errorOutput . $r->output);
        }

        return json_decode($r->output, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Observe executor entry and independently read persisted Attempt metadata.
     */
    private function entered(string $key, string $job): array
    {
        $this->await(fn () => is_file($key . '.entered'), $key);
        $c = json_decode(file_get_contents($key . '.entered'), true, flags: JSON_THROW_ON_ERROR);

        return $this->row(
            'SELECT j.id AS job_id, a.id AS attempt_id, j.generation AS delivery_generation, j.lease_until AS lease_expires_at FROM jobs j JOIN job_attempts a ON a.id = ? AND a.job_id = j.id WHERE j.id = ?',
            [$c['attemptId'], $job],
        );
    }

    /**
     * Find the real process by the executor's persisted correlation receipt.
     */
    private function key(string $attempt): string
    {
        foreach (self::$children[$this->workspace] ?? [] as $key => $child) {
            if (is_file($key . '.entered') && json_decode(file_get_contents($key . '.entered'), true)['attemptId'] === $attempt) {
                return $key;
            }
        }

        throw new RuntimeException('No supervised executor for Attempt.');
    }

    /**
     * Inspect the actual process handle, including after takeover.
     */
    private function alive(string $attempt): bool
    {
        return self::$children[$this->workspace][$this->key($attempt)]['process']->running;
    }

    /**
     * Send one command atomically, avoiding partial JSON observations.
     */
    private function send(string $key, array $command): void
    {
        if (is_file($key . '.response')) {
            unlink($key . '.response');
        }

        file_put_contents($key . '.tmp', json_encode($command, JSON_THROW_ON_ERROR));
        rename($key . '.tmp', $key . '.command');
    }

    /**
     * Resume a living executor and observe its result or normal native settlement.
     */
    private function command(string $attempt, array $command): array
    {
        $key = $this->key($attempt);
        if (! $this->alive($attempt)) {
            if (in_array($command['action'], ['result', 'checkpoint', 'custody', 'reconcile', 'renew'], true)) {
                return $this->finish($this->start('replay', [
                    'context' => json_decode(file_get_contents($key . '.entered'), true, flags: JSON_THROW_ON_ERROR),
                    'command' => $command,
                ]));
            }

            throw new RuntimeException('The requested executor has already settled.');
        }

        $this->send($key, $command);
        if (in_array($command['action'], ['complete', 'fail'], true)) {
            $this->finish($key);

            return [];
        }

        return $this->response($key);
    }

    /**
     * Wait for a host receipt while requiring the supervised process to stay alive.
     */
    private function response(string $key): array
    {
        $this->await(fn () => is_file($key . '.response'), $key);

        return json_decode(file_get_contents($key . '.response'), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Bound every barrier and fail immediately if its worker exits unexpectedly.
     */
    private function await(callable $ready, ?string $key = null): void
    {
        $until = microtime(true) + 15;
        do {
            if ($ready()) {
                return;
            }

            if ($key !== null && ! self::$children[$this->workspace][$key]['process']->running) {
                throw new RuntimeException('Worker exited before barrier: ' . self::$children[$this->workspace][$key]['process']->errorOutput);
            }

            usleep(10_000);
        } while (microtime(true) < $until);

        throw new RuntimeException('Fencing barrier timed out.');
    }

    /**
     * Fetch independent persisted observations with parameterized SQL.
     */
    private function rows(string $sql, array $args = []): array
    {
        $s = $this->db->prepare($sql);
        $s->execute($args);

        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Require one independently observable row.
     */
    private function row(string $sql, array $args = []): array
    {
        return $this->rows($sql, $args)[0] ?? throw new RuntimeException('Expected database observation is missing.');
    }

    /**
     * Change only transport visibility, never the execution lease.
     */
    private function expire(): void
    {
        $this->db->exec('UPDATE enqueue SET redeliver_after = EXTRACT(EPOCH FROM clock_timestamp()) - 1, delayed_until = 0');
    }

    /**
     * Verify consumption through the committed queue deletion/publication effects.
     */
    private function nativeReceived(string $key): bool
    {
        return is_file($key . '.decision') && file_get_contents($key . '.decision') !== '';
    }

    /**
     * Force both lock orders with real renewal and a separate native consumer.
     *
     * A trigger holds the first mutation's row lock while the other PostgreSQL
     * session is observed contending. It changes no authority predicate or lease.
     */
    private function renewalRace(string $job, string $attempt, string $first): array
    {
        $a = $this->key($attempt);
        $original = $this->row('SELECT lease_until FROM jobs WHERE id = ?', [$job])['lease_until'];
        $snapshots = ['before' => $this->run('attempts.read', ['workspace' => $this->workspace, 'job_id' => $job])];
        $this->db->exec('SELECT pg_advisory_lock(915, 1)');
        $condition = $first === 'renewal' ? 'NEW.claim_token = OLD.claim_token AND NEW.lease_until <> OLD.lease_until' : 'NEW.claim_token <> OLD.claim_token';
        $this->db->exec(
            "CREATE FUNCTION pause_fencing_decision() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN IF "
            . $condition
            . " THEN PERFORM pg_advisory_xact_lock(915, 1); END IF; RETURN NEW; END'",
        );
        $this->db->exec('CREATE TRIGGER pause_fencing_decision AFTER UPDATE ON jobs FOR EACH ROW EXECUTE FUNCTION pause_fencing_decision()');
        try {
            $this->expire();
            if ($first === 'renewal') {
                usleep(1_500_000);
                $this->send($a, ['action' => 'renew']);
                $this->awaitWaiters([$a]);
                $observer = $this->start('observe', ['job_id' => $job]);
                $this->awaitWaiters([$a, $observer]);
                $b = $this->start('consume');
                $this->awaitWaiters([$a, $b]);
                $this->await(fn () => $this->row('SELECT clock_timestamp() > ?::timestamptz AS passed', [$original])['passed']);
            } else {
                $this->send($a, ['action' => 'renew', 'barrier' => true]);
                $this->await(fn () => is_file($a . '.boundary'));
                $this->await(fn () => $this->row('SELECT clock_timestamp() > ?::timestamptz AS passed', [$original])['passed']);
                $b = $this->start('consume');
                $this->awaitWaiters([$b]);
                $observer = $this->start('observe', ['job_id' => $job]);
                $this->awaitWaiters([$b, $observer]);
                touch($a . '.release');
                $this->awaitWaiters([$a, $b]);
            }

            $this->db->exec('SELECT pg_advisory_unlock(915, 1)');
            $this->await(fn () => is_file($observer . '.observing'), $observer);
            $snapshots['after_first'] = $this->run('attempts.read', ['workspace' => $this->workspace, 'job_id' => $job]);
            touch($observer . '.observer_release');
            $this->finish($observer);
            $renewal = $this->response($a);
            if ($first === 'takeover') {
                $successor = $this->entered($b, $job)['attempt_id'];
            } else {
                $this->finish($b);
                $successor = null;
            }

            $snapshots['after_both'] = $this->run('attempts.read', ['workspace' => $this->workspace, 'job_id' => $job]);
            // Successful UPDATE timestamps are independently witnessed by a trigger.
            $times = $this->rows('SELECT * FROM decision_audit ORDER BY sequence');
            $renewalAt = $renewal['decided_at'];
            $takeoverAt = $first === 'takeover' ? end($times)['at'] : file_get_contents($b . '.decision');

            return [
                'observed_competing_processes' => 2,
                'overlapping_database_operations_observed' => true,
                'received_native_message' => is_file($b . '.entered') || $this->nativeReceived($b),
                'original_worker_alive' => $this->alive($attempt),
                'renewal_accepted' => $renewal['accepted'],
                'takeover_accepted' => $successor !== null,
                'successor_attempt_id' => $successor,
                'renewal_decision_at' => $renewalAt,
                'takeover_decision_at' => $takeoverAt,
                'authority_snapshots' => $snapshots,
            ];
        } finally {
            $this->db->exec('SELECT pg_advisory_unlock(915, 1)');
            $this->db->exec('DROP TRIGGER pause_fencing_decision ON jobs');
            $this->db->exec('DROP FUNCTION pause_fencing_decision()');
        }
    }

    /**
     * Observe lock waits in independent PostgreSQL sessions, not worker assertions.
     */
    private function awaitWaiters(array $keys): void
    {
        $this->await(function () use ($keys): bool {
            $this->db->exec('SELECT pg_stat_clear_snapshot()');
            $sql = "SELECT count(*) AS n FROM pg_stat_activity WHERE wait_event_type = 'Lock' AND application_name IN (" . implode(',', array_fill(0, count($keys), '?')) . ')';

            return (int) $this->row($sql, array_map('basename', $keys))['n'] === count($keys);
        });
    }

    /**
     * Hold the Job table until both native consumers are waiting at their claim.
     */
    private function compete(string $job): array
    {
        // The second native invitation exercises the same Job through real delivery.
        $row = $this->row('SELECT * FROM enqueue LIMIT 1');
        $columns = array_keys($row);
        $values = array_map(static fn ($v) => is_bool($v) ? ($v ? 'true' : 'false') : $v, array_values($row));
        $values[array_search('id', $columns, true)] = \Symfony\Component\Uid\Uuid::v7()->toString();
        $this->db->prepare('INSERT INTO enqueue (' . implode(',', $columns) . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')')->execute($values);
        $this->db->beginTransaction();
        try {
            $this->db->exec('LOCK TABLE jobs IN ACCESS EXCLUSIVE MODE');
            $keys = [$this->start('consume'), $this->start('consume')];
            $this->await(function () use ($keys): bool {
                $this->db->exec('SELECT pg_stat_clear_snapshot()');

                return (
                    (int) $this->row("SELECT count(*) AS n FROM pg_stat_activity WHERE application_name IN (?, ?) AND wait_event_type = 'Lock' AND query ILIKE '%SELECT id FROM jobs%FOR UPDATE%'", array_map(
                        'basename',
                        $keys,
                    ))['n'] === 2
                );
            });
            $this->db->commit();
            $this->await(fn () => is_file($keys[0] . '.entered') || is_file($keys[1] . '.entered'));
            $ids = [];
            foreach ($keys as $key) {
                if (is_file($key . '.entered')) {
                    $ids[] = json_decode(file_get_contents($key . '.entered'), true)['attemptId'];
                } else {
                    $this->finish($key);
                }
            }

            return ['observed_competing_processes' => 2, 'entered_executor_attempt_ids' => $ids];
        } finally {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
        }
    }
}
