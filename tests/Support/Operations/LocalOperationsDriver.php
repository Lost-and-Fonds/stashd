<?php

declare(strict_types=1);

namespace Tests\Support\Operations;

use PDO;
use RuntimeException;
use Symfony\Component\Uid\Uuid;
use Tempest\DateTime\Duration;
use Tempest\Process\GenericProcessExecutor;
use Tempest\Process\InvokedProcess;
use Tempest\Process\PendingProcess;
use Tests\Support\RecordsAndArchives\TransactionProbe;

/**
 * Adapt the independent contracts to real admission, Messenger and PostgreSQL.
 *
 * Each fixture owns an entire disposable database and role. No worker survives
 * an action; SQL observations and probe effects never stand in for delivery.
 */
final class LocalOperationsDriver implements OperationsDriver
{
    /**
     * Execute one action using durable fixture settings rather than object state.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function run(string $action, array $arguments): array
    {
        $workspace = $arguments['workspace'];
        if (realpath($workspace) !== $workspace || ! preg_match('~/stashd-archives-[a-f0-9]{24}/(?:operations|operations-delivery)$~D', $workspace)) {
            throw new RuntimeException('Operations tests require their private disposable workspace.');
        }

        $path = $workspace . '/installation.json';
        if ($action === 'fixture.dispose') {
            if (is_file($path)) {
                $installation = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
                TransactionProbe::run($workspace, ['operation' => 'projection.dispose', 'fixture' => $installation['fixture']]);
            }

            return [];
        }

        if (! is_file($path)) {
            $this->prepare($workspace, $path);
        }

        $installation = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $descriptor = $installation['descriptor'];
        $database = self::connect($descriptor);

        if ($action === 'probe.prepare') {
            $id = Uuid::v7()->toString();
            $statement = $database->prepare('INSERT INTO probe_scopes (id, name) VALUES (?, ?)');
            $statement->execute([$id, $arguments['name']]);

            return ['scope_id' => $id];
        }

        if ($action === 'work.request') {
            return $this->finish($this->start($descriptor, ['action' => 'request', 'work' => $arguments]));
        }

        if ($action === 'work.request_concurrently') {
            return $this->concurrent($descriptor, $database, $arguments['requests']);
        }

        if ($action === 'jobs.for_work') {
            $statement = $database->prepare(
                "SELECT id, status IN ('completed', 'failed') AS terminal FROM jobs WHERE work_type = 'acceptance.probe' AND work_scope = ? AND work_mode = ? ORDER BY id",
            );
            $statement->execute([$arguments['scope_id'], $arguments['mode']]);

            return ['jobs' => $statement->fetchAll(PDO::FETCH_ASSOC)];
        }

        if ($action === 'jobs.read') {
            $statement = $database->prepare(
                "SELECT id, status AS state, desired_priority, status IN ('completed', 'failed') AS terminal, (SELECT count(*) FROM job_attempts WHERE job_id = jobs.id) AS attempt_count FROM jobs WHERE id = ?",
            );
            $statement->execute([$arguments['job_id']]);
            $job = $statement->fetch(PDO::FETCH_ASSOC);
            if ($job === false) {
                throw new RuntimeException('Requested Job does not exist.');
            }

            $job['attempt_count'] = (int) $job['attempt_count'];

            return ['job' => $job];
        }

        if ($action === 'probe.effects') {
            $statement = $database->prepare('SELECT job_id FROM probe_effects WHERE scope_id = ? AND mode = ? ORDER BY sequence');
            $statement->execute([$arguments['scope_id'], $arguments['mode']]);

            return ['job_ids' => $statement->fetchAll(PDO::FETCH_COLUMN)];
        }

        if ($action === 'work.execute_next' || $action === 'work.execute_all') {
            $before = (int) $database->query('SELECT count(*) FROM probe_effects')->fetchColumn();
            $deadline = microtime(true) + 20;
            do {
                $this->finish($this->start($descriptor, ['action' => 'execute', 'limit' => 1]));
                $after = (int) $database->query('SELECT count(*) FROM probe_effects')->fetchColumn();
                if ($action === 'work.execute_next' && $after > $before) {
                    if ($after !== ($before + 1)) {
                        throw new RuntimeException('A single controlled delivery produced multiple effects.');
                    }

                    return ['job_id' => $database->query('SELECT job_id FROM probe_effects ORDER BY sequence DESC LIMIT 1')->fetchColumn()];
                }

                $pending = (int) $database->query("SELECT count(*) FROM jobs WHERE status <> 'completed'")->fetchColumn();
                $messages = (int) $database->query('SELECT count(*) FROM messenger_messages')->fetchColumn();
                if ($pending === 0 && $messages === 0 && $action === 'work.execute_all') {
                    return [];
                }

                usleep(10_000);
            } while (microtime(true) < $deadline);

            throw new RuntimeException('Controlled execution did not settle before its deadline.');
        }

        throw new RuntimeException('Unsupported Operations action: ' . $action);
    }

    /**
     * Create restricted test resources and run the actual Tempest migrations.
     */
    private function prepare(string $workspace, string $path): void
    {
        $name = 'stashd_tx_test_' . bin2hex(random_bytes(8));
        $fixture = ['database' => $name, 'username' => $name, 'schema' => $name];
        // Save identity before provisioning so teardown can clean a partial setup.
        file_put_contents($path, json_encode(['fixture' => $fixture], JSON_THROW_ON_ERROR));
        chmod($path, 0o600);
        $descriptor = TransactionProbe::run($workspace, ['operation' => 'projection.describe', 'fixture' => $fixture]);
        file_put_contents($path, json_encode(compact('fixture', 'descriptor'), JSON_THROW_ON_ERROR));
        $result = new GenericProcessExecutor()->run(new PendingProcess(
            command: [PHP_BINARY, 'tempest', 'integration:init', '--no-interaction'],
            timeout: Duration::seconds(20),
            path: dirname(__DIR__, 3),
            environment: [
                'ENVIRONMENT' => 'testing',
                'VAULT_ROOT' => $workspace . '/vault',
                'STASHD_OPERATIONS_DSN' => $descriptor['dsn'],
                'STASHD_OPERATIONS_USERNAME' => $descriptor['username'],
                'STASHD_OPERATIONS_PASSWORD' => $descriptor['password'],
                'STASHD_OPERATIONS_SCHEMA' => $descriptor['schema'],
            ],
        ));
        if ($result->failed()) {
            throw new RuntimeException('Operations initialization exited ' . $result->exitCode . ': ' . $result->output . $result->errorOutput);
        }

        $database = self::connect($descriptor);
        $database->exec('CREATE TABLE probe_scopes (id UUID PRIMARY KEY, name TEXT NOT NULL)');
        $database->exec(
            'CREATE TABLE probe_effects (sequence BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY, scope_id UUID NOT NULL REFERENCES probe_scopes(id), mode TEXT NOT NULL, job_id UUID NOT NULL, observation TEXT NOT NULL)',
        );
    }

    /**
     * Open only the exact generated database and role, never application settings.
     *
     * @param array<string, mixed> $descriptor
     */
    public static function connect(array $descriptor): PDO
    {
        $name = $descriptor['username'];
        if (
            ! preg_match('/^stashd_tx_test_[a-f0-9]{16}$/D', $name)
            || $descriptor['dsn'] !== 'pgsql:host=lerd-postgres;port=5432;dbname=' . $name
            || $descriptor['schema'] !== $name
        ) {
            throw new RuntimeException('Only disposable PostgreSQL installations are allowed.');
        }

        $database = new PDO($descriptor['dsn'], $name, $descriptor['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $database->exec('SET search_path TO "' . $name . '"');

        return $database;
    }

    /**
     * Start a bounded child with restricted credentials passed through stdin.
     *
     * @param array<string, mixed> $descriptor
     * @param array<string, mixed> $request
     */
    private function start(array $descriptor, array $request, string $group = ''): InvokedProcess
    {
        return new GenericProcessExecutor()->start(new PendingProcess(
            command: [PHP_BINARY, '-d', 'display_errors=stderr', __DIR__ . '/runtime-process.php'],
            timeout: Duration::seconds(25),
            path: dirname(__DIR__, 3),
            input: json_encode(['descriptor' => $descriptor, ...$request], JSON_THROW_ON_ERROR),
            environment: ['PGAPPNAME' => $group, 'ENVIRONMENT' => 'testing'],
        ));
    }

    /**
     * Fail on process errors and decode only the child's actual result.
     *
     * @return array<string, mixed>
     */
    private function finish(InvokedProcess $process): array
    {
        $result = $process->wait();
        if ($result->failed()) {
            throw new RuntimeException('Operations child exited ' . $result->exitCode . ': ' . $result->errorOutput);
        }

        try {
            return json_decode($result->output, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new RuntimeException('Invalid child JSON: ' . substr($result->output, 0, 1500), previous: $error);
        }
    }

    /**
     * Prove all requests overlap inside production admission transactions.
     *
     * A table lock blocks the first caller after its identity lock. Remaining
     * callers block on their real transaction locks; PostgreSQL must report all
     * children waiting before the parent releases them. Cleanup always stops them.
     *
     * @param array<string, mixed> $descriptor
     * @param list<array<string, mixed>> $requests
     * @return array<string, mixed>
     */
    private function concurrent(array $descriptor, PDO $database, array $requests): array
    {
        $group = 'operations-admission-' . bin2hex(random_bytes(8));
        $children = [];
        $database->beginTransaction();
        try {
            $database->exec('LOCK TABLE jobs IN ACCESS EXCLUSIVE MODE');
            foreach ($requests as $request) {
                $children[] = $this->start($descriptor, ['action' => 'request', 'work' => $request], $group);
            }

            $waiting = $database->prepare("SELECT count(*) FROM pg_stat_activity WHERE application_name = ? AND xact_start IS NOT NULL AND wait_event_type = 'Lock'");
            $deadline = microtime(true) + 10;
            do {
                $database->exec('SELECT pg_stat_clear_snapshot()');
                $waiting->execute([$group]);
                $count = (int) $waiting->fetchColumn();
                if ($count === count($requests)) {
                    break;
                }

                foreach ($children as $child) {
                    if (! $child->running) {
                        throw new RuntimeException('Admission child exited before the transaction barrier: ' . $child->errorOutput);
                    }
                }

                usleep(10_000);
            } while (microtime(true) < $deadline);

            if ($count !== count($requests)) {
                throw new RuntimeException('Admission barrier observed only ' . $count . ' of ' . count($requests) . ' overlapping transactions.');
            }

            $database->commit();

            return ['receipts' => array_map($this->finish(...), $children)];
        } finally {
            if ($database->inTransaction()) {
                $database->rollBack();
            }

            foreach ($children as $child) {
                $child->stop(0, 9);
            }
        }
    }
}
