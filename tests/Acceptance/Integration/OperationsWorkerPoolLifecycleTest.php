<?php

declare(strict_types=1);

use Tempest\DateTime\Duration;
use Tempest\Process\GenericProcessExecutor;
use Tempest\Process\PendingProcess;
use Tests\Support\RecordsAndArchives\ArchiveFixture;

/**
 * Independently confirm database and role removal using the existing private fixture administrator.
 * The worker processes never receive these credentials, and this observer performs no mutations.
 */
function assert_worker_pool_fixture_gone(array $report): void
{
    $admin = json_decode(file_get_contents(dirname(__DIR__, 3) . '/tmp/transaction-projection-admin.json'), true, flags: JSON_THROW_ON_ERROR);
    $database = new PDO($admin['dsn'], $admin['username'], $admin['password']);
    $databases = $database->prepare('SELECT count(*) FROM pg_database WHERE datname = ?');
    $databases->execute([$report['fixture_database']]);
    expect((int) $databases->fetchColumn())->toBe(0);
    $roles = $database->prepare('SELECT count(*) FROM pg_roles WHERE rolname = ?');
    $roles->execute([$report['fixture_database']]);
    expect((int) $roles->fetchColumn())->toBe(0);
}

test('continuous Operations consumers retain ownership through Prompts tasks and shutdown', function (string $scenario, int $workers, int $columns, string $signal): void {
    $artifacts = new ArchiveFixture();
    $directory = getenv('STASHD_WORKER_POOL_EVIDENCE_DIR') ?: dirname($artifacts->path('report'));
    $evidence = $directory . '/' . $scenario . '-' . $workers . '-' . $columns . '-' . $signal . '-' . bin2hex(random_bytes(4)) . '.json';
    $configuration = $artifacts->path('phpunit.config');
    PHPUnit\TextUI\Configuration\Registry::saveTo($configuration);
    try {
        $result = new GenericProcessExecutor()->run(new PendingProcess(
            command: [
                'python3',
                'tests/Support/Integration/worker_pool_pty.py',
                PHP_BINARY,
                'tests/Support/Integration/worker-pool-lifecycle.php',
                $scenario,
                $evidence,
                (string) $workers,
                (string) $columns,
                $signal,
            ],
            timeout: Duration::seconds(90),
            environment: [...getenv(), 'STASHD_LIFECYCLE_PHPUNIT_CONFIG' => $configuration],
        ));
        expect($result->exitCode)->toBe(0, $result->output . $result->errorOutput . (is_file($evidence) ? file_get_contents($evidence) : 'No evidence saved.'));
        expect(file_get_contents($evidence . '.pty'))->not->toContain('Notice:', 'Warning:', 'Fatal error:');
        $report = json_decode(file_get_contents($evidence), true, flags: JSON_THROW_ON_ERROR);
        expect($report['status'])->toBe($scenario === 'pass' ? 'passed' : 'failed');
        expect($report['cleanup'])->toBe('completed');
        expect($report['cleanup_order'])->toBe(['workers_stopped_and_reaped', 'database_disposed', 'storage_disposed']);
        assert_worker_pool_fixture_gone($report);
        expect(is_dir($report['fixture_root']))->toBeFalse();
        expect($report['renderers'])->not->toBeEmpty();
        expect($report['renderer_php_shutdowns'])->toBe([]);
        expect($report)->not->toHaveKey('observer_failure_type');
        expect($report['observer_usable_at_cleanup'])->toBeTrue();
        expect(array_values(array_unique($report['callback_pids'])))->toBe([$report['owner_pid']]);
        expect($report['owner_handlers_preserved'])->toBeTrue();
        foreach ([...$report['worker_pids'], ...$report['renderers']] as $pid) {
            expect(is_dir('/proc/' . $pid))->toBeFalse('Child survived or was not reaped: ' . $pid);
        }

        if ($scenario === 'pass') {
            expect($report['ready_workers'])->toBe($workers);
            expect($report['empty_queue_messages'])->toBe(0);
            expect($report['empty_queue_between_jobs'])->toBeTrue();
            expect($report['completed_jobs'])->toBe(2);
            expect(array_unique($report['assignments']))->toHaveCount(2);
            expect($report['tasks'])->toHaveCount(4);
            foreach ($report['tasks'] as $task) {
                expect($task['workers'])->toBe($report['worker_pids']);
            }
        } elseif ($scenario === 'startup') {
            expect($report['worker_pids'])->toHaveCount(2);
            expect($report)->not->toHaveKey('at_active_boundary');
        } else {
            expect($report['at_active_boundary'])->toBeTrue();
            expect($report['completed_jobs'] ?? 0)->toBe($scenario === 'renderer' ? 1 : 0);
        }

        if ($signal !== 'none') {
            expect($report['signal'])->toBe($signal === 'ctrl-c' ? SIGINT : SIGTERM);
        }
    } finally {
        $artifacts->dispose();
    }
})->with([
    'serial / ordinary' => ['pass', 1, 80, 'none'],
    'four consumers / narrow' => ['pass', 4, 40, 'none'],
    'partial startup' => ['startup', 4, 80, 'none'],
    'Ctrl+C active group / narrow' => ['interrupt', 4, 40, 'ctrl-c'],
    'SIGTERM active group' => ['interrupt', 4, 80, 'sigterm'],
    'worker death during real execution' => ['worker', 1, 80, 'none'],
    'task callback failure' => ['callback', 4, 80, 'none'],
    'renderer death cannot close inherited resources' => ['renderer', 4, 80, 'none'],
]);

test('the same worker lifecycle executes unattended without Prompts contaminating JSON', function (): void {
    $artifacts = new ArchiveFixture();
    $directory = getenv('STASHD_WORKER_POOL_EVIDENCE_DIR') ?: dirname($artifacts->path('report'));
    $evidence = $directory . '/json-4-' . bin2hex(random_bytes(4)) . '.json';
    $configuration = $artifacts->path('phpunit.config');
    PHPUnit\TextUI\Configuration\Registry::saveTo($configuration);
    try {
        $result = new GenericProcessExecutor()->run(new PendingProcess(
            command: [PHP_BINARY, '-d', 'lerd.devtools_flag=/disabled-for-worker-lifecycle', 'tests/Support/Integration/worker-pool-lifecycle.php', 'json', $evidence, '4'],
            timeout: Duration::seconds(60),
            environment: [...getenv(), 'STASHD_LIFECYCLE_PHPUNIT_CONFIG' => $configuration],
        ));
        expect($result->exitCode)->toBe(0, $result->output . $result->errorOutput);
        $report = json_decode($result->output, true, flags: JSON_THROW_ON_ERROR);
        file_put_contents($evidence . '.stdout', $result->output);
        expect($report)->toBe(json_decode(file_get_contents($evidence), true, flags: JSON_THROW_ON_ERROR));
        expect($result->output . $result->errorOutput)->not->toContain("\e[", 'password', 'dsn', 'SIGNING_KEY');
        expect($report['status'])->toBe('passed');
        expect($report['cleanup'])->toBe('completed');
        assert_worker_pool_fixture_gone($report);
        expect($report['completed_jobs'])->toBe(2);
        expect($report['renderers'])->toBe([]);
        foreach ($report['worker_pids'] as $pid) {
            expect(is_dir('/proc/' . $pid))->toBeFalse();
        }
    } finally {
        $artifacts->dispose();
    }
});
