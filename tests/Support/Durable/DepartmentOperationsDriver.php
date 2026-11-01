<?php

declare(strict_types=1);

namespace Tests\Support\Durable;

use PDO;
use RuntimeException;
use Tempest\Process\GenericProcessExecutor;
use Tempest\Process\InvokedProcess;
use Tempest\Process\PendingProcess;
use Tests\Support\Operations\OperationsDriver;

/**
 * Run the unchanged accepted admission contracts against the new department.
 *
 * Drivers are discarded after each action. Only generated PostgreSQL fixtures,
 * real outbox/worker messages and independent probe effects carry state.
 */
final class DepartmentOperationsDriver implements OperationsDriver
{
    /**
     * Translate the accepted observation vocabulary to public production boundaries.
     */
    public function run(string $action, array $arguments): array
    {
        $workspace = $arguments['workspace'];
        if (realpath($workspace) !== $workspace || ! preg_match('~/stashd-archives-[a-f0-9]{24}/operations$~D', $workspace)) {
            throw new RuntimeException('A private disposable Operations workspace is required.');
        }

        $path = $workspace . '/department.json';
        if ($action === 'fixture.dispose') {
            if (is_file($path)) {
                $config = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
                $admin = json_decode(file_get_contents(dirname(__DIR__, 3) . '/tmp/transaction-projection-admin.json'), true, flags: JSON_THROW_ON_ERROR);
                $pdo = new PDO($admin['dsn'], $admin['username'], $admin['password']);
                $this->validate($config);
                $pdo->exec('DROP DATABASE "' . $config['username'] . '" WITH (FORCE)');
                $pdo->exec('DROP ROLE "' . $config['username'] . '"');
            }

            return [];
        }

        if (! is_file($path)) {
            $fixture = new DepartmentFixture();
            file_put_contents($path, json_encode([
                'dsn' => $fixture->config->dsn,
                'username' => $fixture->config->username,
                'password' => $fixture->config->password,
            ], JSON_THROW_ON_ERROR));
            chmod($path, 0o600);
        }

        $config = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $this->validate($config);
        $database = new PDO($config['dsn'], $config['username'], $config['password']);
        $database->exec('SET search_path TO stashd_operations');
        if ($action === 'probe.prepare') {
            return ['scope_id' => (string) \Symfony\Component\Uid\Uuid::v7()];
        }

        if ($action === 'work.request') {
            return $this->finish($this->start($config, $arguments));
        }

        if ($action === 'work.request_concurrently') {
            $group = 'department-admission-' . bin2hex(random_bytes(8));
            $children = [];
            $database->beginTransaction();
            try {
                $database->exec('LOCK TABLE operation_assignments IN ACCESS EXCLUSIVE MODE');
                foreach ($arguments['requests'] as $request) {
                    $children[] = $this->start($config, [...$request, 'group' => $group]);
                }

                $waiting = $database->prepare("SELECT count(*) FROM pg_stat_activity WHERE application_name = ? AND xact_start IS NOT NULL AND wait_event_type = 'Lock'");
                $deadline = microtime(true) + 10;
                do {
                    $database->exec('SELECT pg_stat_clear_snapshot()');
                    $waiting->execute([$group]);
                    $count = (int) $waiting->fetchColumn();
                    if ($count === count($children)) {
                        break;
                    }

                    foreach ($children as $child) {
                        if (! $child->running) {
                            throw new RuntimeException('Admission exited before contention: ' . $child->errorOutput);
                        }
                    }

                    usleep(10_000);
                } while (microtime(true) < $deadline);

                if ($count !== count($children)) {
                    throw new RuntimeException('Not all admissions overlapped in PostgreSQL.');
                }

                $database->commit();

                return ['receipts' => array_map($this->finish(...), $children)];
            } finally {
                if ($database->inTransaction()) {
                    $database->rollBack();
                }

                foreach ($children as $child) {
                    if ($child->running) {
                        $child->signal(9)->wait();
                    }
                }
            }
        }

        if ($action === 'jobs.for_work') {
            $query = $database->prepare('SELECT id, NOT active AS terminal FROM operation_assignments WHERE work_scope = ? AND work_mode = ? ORDER BY id');
            $query->execute([$arguments['scope_id'], $arguments['mode']]);

            return ['jobs' => $query->fetchAll(PDO::FETCH_ASSOC)];
        }

        if ($action === 'jobs.read') {
            $query = $database->prepare(
                "SELECT id, CASE WHEN active THEN CASE WHEN EXISTS(SELECT 1 FROM operation_attempts WHERE assignment_id = a.id) THEN 'running' ELSE 'queued' END WHEN error IS NULL THEN 'completed' ELSE 'failed' END AS state, priority AS desired_priority, NOT active AS terminal, (SELECT count(*) FROM operation_attempts WHERE assignment_id = a.id) AS attempt_count FROM operation_assignments a WHERE id = ?",
            );
            $query->execute([$arguments['job_id']]);
            $job = $query->fetch(PDO::FETCH_ASSOC);
            if ($job === false) {
                throw new RuntimeException('Assignment does not exist.');
            }

            return ['job' => $job];
        }

        if ($action === 'probe.effects') {
            $query = $database->prepare('SELECT assignment FROM effects WHERE value LIKE ? ORDER BY id');
            $query->execute([$arguments['scope_id'] . ':' . $arguments['mode'] . ':%']);

            return ['job_ids' => $query->fetchAll(PDO::FETCH_COLUMN)];
        }

        if ($action === 'work.execute_next' || $action === 'work.execute_all') {
            $before = (int) $database->query('SELECT count(*) FROM effects')->fetchColumn();
            $deadline = microtime(true) + 20;
            do {
                $this->finish($this->start($config, ['action' => 'work', 'limit' => 1]));
                $after = (int) $database->query('SELECT count(*) FROM effects')->fetchColumn();
                if ($action === 'work.execute_next' && $after > $before) {
                    if ($after !== ($before + 1)) {
                        throw new RuntimeException('One delivery produced multiple effects.');
                    }

                    return ['job_id' => $database->query('SELECT assignment FROM effects ORDER BY id DESC LIMIT 1')->fetchColumn()];
                }

                if (
                    $action === 'work.execute_all'
                    && (int) $database->query('SELECT count(*) FROM operation_assignments WHERE active')->fetchColumn() === 0
                    && (int) $database->query('SELECT count(*) FROM messenger_messages')->fetchColumn() === 0
                ) {
                    return [];
                }
            } while (microtime(true) < $deadline);

            throw new RuntimeException('Department execution failed to settle.');
        }

        throw new RuntimeException('Unsupported accepted contract action: ' . $action);
    }

    /**
     * Refuse dangerous fixture identities before administration or worker execution.
     */
    private function validate(array $config): void
    {
        if (! preg_match('/^stashd_tx_test_[a-f0-9]{16}$/D', $config['username']) || $config['dsn'] !== 'pgsql:host=lerd-postgres;port=5432;dbname=' . $config['username']) {
            throw new RuntimeException('Invalid disposable fixture identity.');
        }
    }

    /**
     * Execute only actual framework-hosted admission or Messenger worker actions.
     */
    private function start(array $config, array $request): InvokedProcess
    {
        return new GenericProcessExecutor()->start(new PendingProcess(
            command: [PHP_BINARY, __DIR__ . '/department-process.php'],
            input: json_encode([...$config, 'action' => 'admit', ...$request, 'scope' => $request['scope_id'] ?? ''], JSON_THROW_ON_ERROR),
        ));
    }

    /**
     * Require successful process completion and parse the explicitly marked receipt.
     */
    private function finish(InvokedProcess $process): array
    {
        $result = $process->wait();
        if ($result->failed()) {
            throw new RuntimeException($result->errorOutput . $result->output);
        }

        if (! preg_match('/RECEIPT:(\{[^\n]+\})/', $result->output, $receipt)) {
            return [];
        }

        return ['job_id' => json_decode($receipt[1], true, flags: JSON_THROW_ON_ERROR)['id']];
    }
}
