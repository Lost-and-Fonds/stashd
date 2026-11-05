<?php

declare(strict_types=1);

use App\Development\FilesystemStressTask;
use App\Operations\OperationsWorkerPool;
use Laravel\Prompts\Support\Logger;
use Laravel\Prompts\Task;
use Tempest\Process\GenericProcessExecutor;
use Tempest\Process\PendingProcess;
use Tests\Support\RecordsAndArchives\ArchiveFixture;
use Tests\Support\RecordsAndArchives\ProjectionProbe;
use Tests\Support\RecordsAndArchives\TransactionProbe;

require dirname(__DIR__, 3) . '/vendor/autoload.php';
require __DIR__ . '/configuration-cli.php';

// Existing acceptance witnesses use PHPUnit assertions in this independent child.
PHPUnit\TextUI\Configuration\Registry::loadFrom(getenv('STASHD_LIFECYCLE_PHPUNIT_CONFIG'));

// This checkpoint runs discovery Jobs only; it is not a preservation acceptance run.
[$script, $scenario, $evidence, $workers] = $argv;
$workers = (int) $workers;
if (! is_dir(dirname($evidence)) || ! is_writable(dirname($evidence))) {
    throw new RuntimeException('Lifecycle evidence directory must exist and be writable.');
}

$report = [
    'format' => 'stashd-operations-worker-lifecycle-1',
    'generated_at_utc' => gmdate('c'),
    'php' => PHP_VERSION,
    'prompts' => Composer\InstalledVersions::getPrettyVersion('laravel/prompts'),
    'status' => 'incomplete',
    'scenario' => $scenario,
    'requested_workers' => $workers,
    'worker_pids' => [],
    'renderers' => [],
    'tasks' => [],
    'cleanup_order' => [],
];
foreach ([
    'app/Operations/OperationsWorkerPool.php',
    'app/Operations/OperationsCommands.php',
    'app/Operations/Durable/DurableRuntime.php',
    'app/Development/FilesystemStressTask.php',
    'app/Development/FilesystemStressTaskLogger.php',
    'tests/Support/Integration/worker_pool_pty.py',
    'tests/Acceptance/Integration/OperationsWorkerPoolLifecycleTest.php',
    'composer.lock',
] as $path) {
    $report['code_sha256'][$path] = hash_file('sha256', dirname(__DIR__, 3) . '/' . $path);
}

$report['code_sha256']['lifecycle_probe'] = hash_file('sha256', __FILE__);
$started = hrtime(true);
$save = static function () use (&$report, $evidence): void {
    $json = json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
    if (file_put_contents($evidence . '.tmp', $json) !== strlen($json) || ! rename($evidence . '.tmp', $evidence)) {
        throw new RuntimeException('Could not retain lifecycle evidence.');
    }
};
$owner = getmypid();
$report['owner_pid'] = $owner;
register_shutdown_function(static function () use ($owner, $evidence): void {
    if (getmypid() !== $owner) {
        file_put_contents($evidence . '.renderer-shutdown', getmypid() . "\n", FILE_APPEND);
    }
});
$signal = null;
$handlers = [];
pcntl_async_signals(true);
foreach ([SIGINT, SIGTERM] as $number) {
    $handlers[$number] = static function (int $received) use (&$signal): void {
        $signal ??= $received;
    };
    pcntl_signal($number, $handlers[$number]);
}

$check = static function () use (&$signal): void {
    if ($signal !== null) {
        throw new RuntimeException('Lifecycle probe cancelled.');
    }
};
$pool = new OperationsWorkerPool(new GenericProcessExecutor());
$archive = new ArchiveFixture();
$name = 'stashd_tx_test_' . bin2hex(random_bytes(8));
$fixture = ['database' => $name, 'username' => $name, 'schema' => $name];
$report['fixture_database'] = $name;
$report['fixture_root'] = dirname($archive->path('vault'));
$created = false;
$database = null;
$locked = false;
$failure = null;

$phase = static function (string $label, Closure $callback) use (&$report, $pool, $save, $scenario): void {
    $started = hrtime(true);
    $task = new FilesystemStressTask(label: $label, keepSummary: true, limit: 4);
    $execute = static function (Logger $log) use ($task, $callback, &$report): void {
        $report['callback_pids'][] = getmypid();
        $property = new ReflectionProperty(Task::class, 'pid');
        if ($property->isInitialized($task)) {
            $report['renderers'][] = $property->getValue($task);
        }

        $callback($log);
    };
    if ($scenario === 'json' || ! stream_isatty(STDOUT)) {
        $execute(new Logger('unattended'));
    } else {
        $task->run($execute);
    }

    $report['tasks'][] = ['label' => $label, 'workers' => $pool->pids()];
    $report['tasks'][array_key_last($report['tasks'])]['elapsed_seconds'] = (hrtime(true) - $started) / 1_000_000_000;
    $save();
};

try {
    $save();
    $descriptor = TransactionProbe::run(dirname($archive->path('vault')), ['operation' => 'projection.describe', 'fixture' => $fixture]);
    $created = true;
    $database = ProjectionProbe::connect($descriptor, $name);
    $archive->onProcessTick = static fn () => $check();
    configuration_result(configuration_cli($archive, $descriptor, ['vault:init']));
    configuration_result(configuration_cli($archive, $descriptor, ['integration:init']));
    $source = dirname($archive->input('one.txt', "lifecycle fixture\n"));
    $input = configuration_result(configuration_cli($archive, $descriptor, ['input:create', 'filesystem', $source]));
    $php = [PHP_BINARY];
    if (ini_get('lerd.devtools_flag') !== false) {
        $php = [...$php, '-d', 'lerd.devtools_flag=/disabled-for-worker-lifecycle'];
    }

    $command = new PendingProcess(
        command: [...$php, 'tempest', 'operations:work', '--notify-ready', '--no-interaction'],
        path: dirname(__DIR__, 3),
        environment: [
            ...getenv(),
            'ENVIRONMENT' => 'testing',
            'VAULT_ROOT' => $archive->path('vault'),
            'STASHD_OPERATIONS_DSN' => $descriptor['dsn'],
            'STASHD_OPERATIONS_USERNAME' => $descriptor['username'],
            'STASHD_OPERATIONS_PASSWORD' => $descriptor['password'],
            'STASHD_OPERATIONS_SCHEMA' => 'configuration_proof',
        ],
    );
    $phase('Lifecycle · start continuous workers', static function (Logger $log) use ($pool, $command, $workers, $scenario, $check, &$report, $save, $database): void {
        $pool->start($command, $workers, static function () use ($pool, $scenario, $check, &$report, $save): void {
            $report['worker_pids'] = $pool->pids();
            $save();
            $check();
            if ($scenario === 'startup' && count($pool->pids()) === 2) {
                throw new RuntimeException('Deliberate partial startup failure.');
            }
        });
        $report['ready_workers'] = count($pool->pids());
        $log->subLabel('Ready; waiting through an empty queue');
        $until = hrtime(true) + 1_200_000_000;
        do {
            $check();
            $pool->assertRunning();
            usleep(20_000);
        } while (hrtime(true) < $until);

        $report['empty_queue_messages'] = (int) $database->query('SELECT count(*) FROM configuration_proof.messenger_messages')->fetchColumn();
        if ($report['empty_queue_messages'] !== 0) {
            throw new RuntimeException('The idle-worker check did not observe an empty queue.');
        }

        $log->label('Lifecycle · workers survived an empty queue');
    });

    $database->exec(<<<'SQL'
    CREATE FUNCTION configuration_proof.lifecycle_pause() RETURNS trigger LANGUAGE plpgsql AS $$
    BEGIN
        PERFORM pg_advisory_xact_lock(7196, 1);
        RETURN NEW;
    END
    $$;
    CREATE TRIGGER lifecycle_pause BEFORE INSERT ON configuration_proof.discovery_items
    FOR EACH ROW EXECUTE FUNCTION configuration_proof.lifecycle_pause();
    SQL);
    $database->query('SELECT pg_advisory_lock(7196, 1)');
    $locked = true;

    foreach ([1, 2] as $job) {
        $phase('Lifecycle · discovery job ' . $job, static function (Logger $log) use (
            $job,
            $pool,
            $archive,
            $descriptor,
            $input,
            $database,
            $scenario,
            $check,
            &$report,
            $save,
            &$locked,
        ): void {
            if ($job === 2) {
                $deadline = hrtime(true) + 10_000_000_000;
                while ((int) $database->query('SELECT count(*) FROM configuration_proof.messenger_messages')->fetchColumn() !== 0) {
                    $check();
                    $pool->assertRunning();
                    if (hrtime(true) >= $deadline) {
                        throw new RuntimeException('The between-Jobs empty queue was not observed.');
                    }

                    usleep(20_000);
                }

                $report['empty_queue_between_jobs'] = true;
                $pool->assertRunning();
            }

            $receipt = configuration_result(configuration_cli($archive, $descriptor, ['input:discover', $input['id']]));
            $report['assignments'][] = $receipt['assignment_id'];
            $deadline = hrtime(true) + 20_000_000_000;
            $seenActive = false;
            do {
                $check();
                $pool->assertRunning();
                if (! $seenActive && $job === 1) {
                    $blocked = $database
                        ->query(
                            "SELECT pid FROM pg_locks WHERE locktype = 'advisory' AND classid = 7196 AND objid = 1 AND NOT granted AND database = (SELECT oid FROM pg_database WHERE datname = current_database())",
                        )
                        ->fetchColumn();
                    if ($blocked !== false) {
                        $seenActive = true;
                        $report['active_backend'] = (int) $blocked;
                        $report['at_active_boundary'] = true;
                        $save();
                        $log->subLabel('Real discovery handler active');
                        if ($scenario === 'worker') {
                            posix_kill($pool->pids()[0], SIGKILL);
                        }

                        if ($scenario === 'renderer') {
                            posix_kill($report['renderers'][array_key_last($report['renderers'])], SIGKILL);
                        }

                        if ($scenario === 'callback') {
                            throw new RuntimeException('Deliberate task failure.');
                        }

                        if (! in_array($scenario, ['interrupt', 'worker'], true)) {
                            $database->query('SELECT pg_advisory_unlock(7196, 1)');
                            $locked = false;
                        }
                    }
                }

                $status = configuration_result(configuration_cli($archive, $descriptor, ['operations:inspect', $receipt['assignment_id']]));
                if ($status['state'] === 'completed') {
                    $report['completed_jobs'] = $job;
                    $log->label('Lifecycle · completed discovery job ' . $job);
                    break;
                }

                if ($status['state'] === 'failed' || hrtime(true) >= $deadline) {
                    throw new RuntimeException('Discovery lifecycle did not complete.');
                }

                usleep(20_000);
            } while (true);
        });
    }
} catch (Throwable $error) {
    $failure = $error;
    $report['failure_type'] = $error::class;
    $report['failure_location'] = basename($error->getFile()) . ':' . $error->getLine();
} finally {
    // Unblock the real handler so cooperative shutdown can finish before fixture disposal.
    if ($locked && $database !== null) {
        try {
            $database->query('SELECT pg_advisory_unlock(7196, 1)');
        } catch (Throwable $error) {
            $failure ??= $error;
            $report['observer_failure_type'] = $error::class;
        }
    }

    $archive->onProcessTick = null;
    if ($database !== null) {
        try {
            $report['observer_usable_at_cleanup'] = (int) $database->query('SELECT 1')->fetchColumn() === 1;
        } catch (Throwable $error) {
            $failure ??= $error;
            $report['observer_failure_type'] = $error::class;
        }
    }

    try {
        $phase('Lifecycle · stop workers and clean up', static function (Logger $log) use ($pool, &$report): void {
            $pool->stop();
            foreach ($pool->pids() as $pid) {
                if (posix_kill($pid, 0)) {
                    throw new RuntimeException('A worker survived shutdown.');
                }
            }

            $report['cleanup_order'][] = 'workers_stopped_and_reaped';
            $log->label('Lifecycle · workers stopped and reaped');
        });
        $database = null;
        if ($created) {
            TransactionProbe::run(dirname($archive->path('vault')), ['operation' => 'projection.dispose', 'fixture' => $fixture]);
        }

        $report['cleanup_order'][] = 'database_disposed';
        $archive->dispose();
        $report['cleanup_order'][] = 'storage_disposed';
        $report['cleanup'] = 'completed';
    } catch (Throwable $error) {
        $failure ??= $error;
        $report['cleanup'] = 'not_confirmed';
        $report['cleanup_failure_type'] = $error::class;
    }

    $report['signal'] = $signal;
    $report['owner_handlers_preserved'] = pcntl_signal_get_handler(SIGINT) === $handlers[SIGINT] && pcntl_signal_get_handler(SIGTERM) === $handlers[SIGTERM];
    $report['renderer_php_shutdowns'] = is_file($evidence . '.renderer-shutdown') ? file($evidence . '.renderer-shutdown', FILE_IGNORE_NEW_LINES) : [];
    $report['status'] = $failure === null ? 'passed' : 'failed';
    $report['elapsed_seconds'] = (hrtime(true) - $started) / 1_000_000_000;
    $save();
}

if ($scenario === 'json') {
    fwrite(STDOUT, json_encode($report, JSON_THROW_ON_ERROR) . "\n");
}

exit($failure === null ? 0 : 1);
